<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\PriceList;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use SidrenaCijena\PriceList\Collector;
use SidrenaCijena\PriceList\CsvWriter;
use SidrenaCijena\PriceList\FilenameBuilder;
use SidrenaCijena\PriceList\GenerationResult;
use SidrenaCijena\PriceList\Generator;
use SidrenaCijena\PriceList\Item;
use SidrenaCijena\PriceList\Manifest;
use SidrenaCijena\PriceList\Outlet;
use SidrenaCijena\PriceList\Retention;
use SidrenaCijena\PriceList\ServiceItem;
use SidrenaCijena\PriceList\Storage;
use SidrenaCijena\PriceList\Writer;
use SidrenaCijena\PriceList\XmlWriter;
use SidrenaCijena\Reference\ReferencePriceRegistry;
use SidrenaCijena\Settings\Defaults;
use SidrenaCijena\Settings\Settings;
use SidrenaCijena\Tests\Support\FixedClock;
use SidrenaCijena\Tests\TestCase;

final class GeneratorTest extends TestCase {
	private string $dir;
	private Storage $storage;
	private Manifest $manifest;
	private FixedClock $clock;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['scwc_test_transients'] = [];
		$this->dir      = sys_get_temp_dir() . '/scwc-generator-' . uniqid();
		$this->storage  = new Storage( $this->dir, 'https://example.hr/u/scwc-cjenik' );
		$this->manifest = new Manifest( $this->storage );
		$this->clock    = new FixedClock( '2026-10-01 04:00:12' );
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->dir . '/tmp/*' ) ?: [] );
		array_map( 'unlink', glob( $this->dir . '/*.*' ) ?: [] );
		@rmdir( $this->dir . '/tmp' );
		@rmdir( $this->dir );
		unset( $GLOBALS['scwc_test_transients'] );
		parent::tearDown();
	}

	/**
	 * @param array<int,Item|ServiceItem> $items
	 */
	private function collector( array $items, int $times = 1 ): Collector {
		$collector = \Mockery::mock( Collector::class );
		$collector->shouldReceive( 'batch' )->times( $times )->with( 0 )->andReturn( [ 'items' => $items, 'cursor' => count( $items ), 'done' => true ] );
		return $collector;
	}

	/** @var Outlet[] */
	private array $extraOutlets = [];

	private function outlet( bool $complete = true ): Outlet {
		return new Outlet( 'webshop', $complete ? 'Ulica 1' : '', 'WEB1', '1', 'Trgovina', 'https://example.hr/' );
	}

	/** @return array<int,Item|ServiceItem> */
	private function items(): array {
		return [
			new Item( 'P1', 'S1', '', 'kom', null, 10.0, false, '', [ 'anchor' => [ 'amount' => 12.0, 'date' => '2026-09-10' ] ], null, '', true, 'https://example.hr/p/1' ),
			new Item( 'P2', 'S2', '', '', null, 8.0, true, 'Sniženje', [ 'anchor' => [ 'amount' => null, 'date' => '2026-09-10' ] ], 9.0, '', false, '' ),
			new ServiceItem( 'U1', 40.0, false, '', [ 'anchor' => [ 'amount' => 45.0, 'date' => '2026-09-10' ] ] ),
		];
	}

	/**
	 * @param array<string,Writer>|null $writers
	 */
	private function generator( ?Settings $settings = null, ?Collector $collector = null, ?array $writers = null, bool $completeOutlet = true ): Generator {
		$settings = $settings ?? new Settings( Defaults::all() );
		return new Generator(
			$settings,
			fn() => array_merge( [ $this->outlet( $completeOutlet ) ], $this->extraOutlets ),
			$collector ?? $this->collector( $this->items() ),
			$writers ?? [ 'xml' => new XmlWriter(), 'csv' => new CsvWriter() ],
			new FilenameBuilder(),
			$this->storage,
			$this->manifest,
			new Retention( $this->manifest, $this->storage, $this->clock ),
			$this->clock,
			ReferencePriceRegistry::fromSettings( $settings ),
		);
	}

	public function test_generates_all_enabled_formats_and_records_everything(): void {
		Functions\expect( 'update_option' )->once()->with(
			'scwc_last_generation',
			\Mockery::on(
				static fn( array $o ) => '2026-10-01 04:00:12' === $o['at']
					&& 'scheduled' === $o['reason']
					&& [ 'webshop_ulica-1_web1_1_20261001_060012.xml', 'webshop_ulica-1_web1_1_20261001_060012.csv' ] === $o['files']
					&& 2 === $o['products'] && 1 === $o['services'] && '' === $o['error']
					&& str_starts_with( (string) $o['at_local'], '2026-10-01T06:00:12' )
			)
		);
		Actions\expectDone( 'scwc_price_list_generated' )->once()->with( \Mockery::type( GenerationResult::class ) );

		$result = $this->generator()->run( 'scheduled' );

		self::assertTrue( $result->ok() );
		self::assertNull( $result->error );
		self::assertSame( 'scheduled', $result->reason );
		self::assertSame( 2, $result->products );
		self::assertSame( 1, $result->services );
		self::assertSame( '2026-10-01T06:00:12+02:00', $result->generatedAt->format( DATE_ATOM ) );
		self::assertCount( 2, $result->files );
		self::assertSame( 'webshop_ulica-1_web1_1_20261001_060012.xml', $result->files[0]['name'] );
		self::assertSame( 'xml', $result->files[0]['format'] );
		self::assertSame( 'https://example.hr/u/scwc-cjenik/webshop_ulica-1_web1_1_20261001_060012.xml', $result->files[0]['url'] );
		self::assertSame( 2, $result->files[0]['products'] );
		self::assertSame( 1, $result->files[0]['services'] );
		self::assertSame( 'csv', $result->files[1]['format'] );

		$xml = $this->storage->path( $result->files[0]['name'] );
		$csv = $this->storage->path( $result->files[1]['name'] );
		self::assertFileExists( $xml );
		self::assertFileExists( $csv );
		self::assertSame( [], glob( $this->dir . '/tmp/*' ) ?: [] );
		self::assertFileExists( $this->dir . '/index.php' );
		self::assertCount( 2, simplexml_load_file( $xml )->Proizvodi->Proizvod );
		self::assertCount( 4, array_filter( explode( "\n", (string) file_get_contents( $csv ) ) ) );

		$entries = ( new Manifest( $this->storage ) )->entries();
		self::assertSame( [ 'webshop_ulica-1_web1_1_20261001_060012.csv', 'webshop_ulica-1_web1_1_20261001_060012.xml' ], array_column( $entries, 'name' ) );
		$e = $entries[1];
		self::assertSame( 'xml', $e['format'] );
		self::assertSame( '2026-10-01T06:00:12+02:00', $e['generated_at'] );
		self::assertSame( '2026-10-01 04:00:12', $e['generated_at_utc'] );
		self::assertSame( 'scheduled', $e['reason'] );
		self::assertSame( 2, $e['products'] );
		self::assertSame( 1, $e['services'] );
		self::assertSame( filesize( $xml ), $e['size'] );
		self::assertSame( hash_file( 'sha256', $xml ), $e['sha256'] );
		self::assertArrayNotHasKey( 'scwc_generating', $GLOBALS['scwc_test_transients'] );
	}

	public function test_every_outlet_gets_its_own_files_with_identical_rows(): void {
		$this->extraOutlets = [ new Outlet( 'poslovnica', 'Vukovarska 5', 'ZG-02', '3', 'Trgovina', 'https://example.hr/' ) ];
		Functions\when( 'update_option' )->justReturn( true );
		$result = $this->generator()->run( 'scheduled' );
		self::assertTrue( $result->ok() );
		self::assertSame(
			[
				'webshop_ulica-1_web1_1_20261001_060012.xml',
				'webshop_ulica-1_web1_1_20261001_060012.csv',
				'poslovnica_vukovarska-5_zg-02_3_20261001_060012.xml',
				'poslovnica_vukovarska-5_zg-02_3_20261001_060012.csv',
			],
			array_column( $result->files, 'name' )
		);
		self::assertSame( [ 'web1', 'web1', 'zg-02', 'zg-02' ], array_column( $result->files, 'outlet' ) );
		$xml2 = simplexml_load_file( $this->storage->path( $result->files[2]['name'] ) );
		self::assertSame( 'ZG-02', (string) $xml2->ProdajniObjekt->Oznaka );
		self::assertSame( 'Vukovarska 5', (string) $xml2->ProdajniObjekt->Adresa );
		self::assertSame( '3', (string) $xml2->ProdajniObjekt->BrojPohrane );
		self::assertCount( 2, $xml2->Proizvodi->Proizvod, 'same rows as the primary' );
		$manifest = new Manifest( $this->storage );
		self::assertSame( 'zg-02', $manifest->entry( $result->files[2]['name'] )['outlet'] );
		self::assertSame( 'web1', $manifest->entry( $result->files[0]['name'] )['outlet'] );
		self::assertSame( $result->files[2]['name'], $manifest->latest( 'xml', 'zg-02', 'web1' )['name'] );
		self::assertSame( $result->files[0]['name'], $manifest->latest( 'xml', 'web1', 'web1' )['name'] );
		self::assertSame( 2, $result->products );
	}

	public function test_only_enabled_formats_with_a_writer_are_written(): void {
		$settings = ( new Settings( Defaults::all() ) )->with( 'price_list.formats', [ 'csv', 'pdf' ] );
		$result   = $this->generator( $settings )->run();
		self::assertTrue( $result->ok() );
		self::assertSame( [ 'csv' ], array_column( $result->files, 'format' ) );
		self::assertSame( [ 'webshop_ulica-1_web1_1_20261001_060012.csv' ], $this->storage->listFiles() );
		self::assertSame( 'manual', $result->reason );
	}

	public function test_items_are_collected_once_for_all_writers(): void {
		$collector = $this->collector( $this->items(), 1 );
		$xml       = \Mockery::mock( Writer::class );
		$csv       = \Mockery::mock( Writer::class );
		$seen      = [];
		$capture   = function ( iterable $items, Outlet $outlet, DateTimeImmutable $at, string $path, array $types ) use ( &$seen ) {
			$seen[] = iterator_count( ( static function () use ( $items ) { yield from $items; } )() );
			self::assertSame( [ 'anchor' ], array_map( fn( $t ) => $t->key, $types ) );
			file_put_contents( $path, 'x' );
			return new \SidrenaCijena\PriceList\WriteStats( 2, 1 );
		};
		$xml->shouldReceive( 'write' )->once()->andReturnUsing( $capture );
		$csv->shouldReceive( 'write' )->once()->andReturnUsing( $capture );
		$result = $this->generator( null, $collector, [ 'xml' => $xml, 'csv' => $csv ] )->run();
		self::assertTrue( $result->ok() );
		self::assertSame( [ 3, 3 ], $seen );
	}

	public function test_refuses_to_run_while_locked_and_leaves_foreign_lock_alone(): void {
		$lock = $this->storage->lock();
		Functions\expect( 'update_option' )->never();
		$result = $this->generator( null, $this->collector( [], 0 ) )->run();
		self::assertFalse( $result->ok() );
		self::assertSame( 'Generiranje je već u tijeku.', $result->error );
		self::assertSame( [], $result->files );
		self::assertNull( $this->storage->lock(), 'The unsuccessful worker cannot release another process lock.' );
		fclose( $lock );
	}

	public function test_disabled_price_list_is_an_error_without_files(): void {
		$settings  = ( new Settings( Defaults::all() ) )->with( 'price_list.enabled', false );
		$collector = \Mockery::mock( Collector::class );
		$collector->shouldReceive( 'items' )->never();
		$result = $this->generator( $settings, $collector )->run( 'scheduled' );
		self::assertFalse( $result->ok() );
		self::assertSame( [], $result->files );
	}

	public function test_incomplete_outlet_is_an_error_without_files(): void {
		Functions\expect( 'update_option' )->once()->with( 'scwc_last_generation', \Mockery::on( static fn( array $o ) => 'Podaci o prodajnom objektu nisu potpuni.' === $o['error'] && [] === $o['files'] ) );
		Actions\expectDone( 'scwc_price_list_generated' )->never();
		$result = $this->generator( null, $this->collector( [], 0 ), null, false )->run();
		self::assertFalse( $result->ok() );
		self::assertSame( 'Podaci o prodajnom objektu nisu potpuni.', $result->error );
		self::assertSame( [], $this->storage->listFiles() );
		self::assertFalse( get_transient( 'scwc_generating' ) );
	}

	public function test_writer_failure_is_reported_and_lock_released(): void {
		$boom = \Mockery::mock( Writer::class );
		$boom->shouldReceive( 'write' )->once()->andThrow( new \RuntimeException( 'Disk pun' ) );
		Functions\expect( 'update_option' )->once()->with( 'scwc_last_generation', \Mockery::on( static fn( array $o ) => 'Disk pun' === $o['error'] ) );
		$result = $this->generator( null, null, [ 'xml' => $boom ] )->run( 'manual' );
		self::assertFalse( $result->ok() );
		self::assertSame( 'Disk pun', $result->error );
		self::assertSame( [], $result->files );
		self::assertSame( [], $this->storage->listFiles() );
		self::assertFileExists( $this->dir . '/tmp/generation.json', 'Failed output can be retried without collecting the catalogue again.' );
		$lock = $this->storage->lock();
		self::assertIsResource( $lock );
		fclose( $lock );
		self::assertSame( [], ( new Manifest( $this->storage ) )->entries() );
	}

	public function test_old_files_are_pruned_after_generation(): void {
		$this->storage->ensure();
		file_put_contents( $this->storage->path( 'old_20260101_060000.xml' ), 'x' );
		$this->manifest->add( [ 'name' => 'old_20260101_060000.xml', 'format' => 'xml', 'generated_at' => '', 'generated_at_utc' => '2026-01-01 05:00:00', 'reason' => 'scheduled', 'products' => 0, 'services' => 0, 'size' => 1, 'sha256' => '' ] );
		$settings = ( new Settings( Defaults::all() ) )->with( 'price_list.formats', [ 'xml' ] )->with( 'price_list.retention_days', 30 );
		$this->generator( $settings )->run();
		self::assertSame( [ 'webshop_ulica-1_web1_1_20261001_060012.xml' ], $this->storage->listFiles() );
		self::assertFalse( $this->manifest->has( 'old_20260101_060000.xml' ) );
	}

	public function test_batches_resume_after_an_interrupted_append_without_duplicates(): void {
		$items = $this->items();
		$collector = \Mockery::mock( Collector::class );
		$collector->shouldReceive( 'batch' )->once()->with( 0 )->andReturn( [ 'items' => [ $items[0] ], 'cursor' => 10, 'done' => false ] );
		$collector->shouldReceive( 'batch' )->once()->with( 10 )->andReturn( [ 'items' => [ $items[1], $items[2] ], 'cursor' => 20, 'done' => true ] );
		self::assertNull( $this->generator( null, $collector )->step( 'scheduled' ) );
		self::assertSame( [], ( new Manifest( $this->storage ) )->entries() );
		// Simulate a killed process: disk append happened but the cursor was never committed.
		file_put_contents( $this->storage->tmpPath( 'rows.ndjson' ), "partial uncommitted garbage", FILE_APPEND );
		$result = $this->generator( null, $collector )->run( 'manual' );
		self::assertTrue( $result->ok(), (string) $result->error );
		self::assertSame( 'scheduled', $result->reason, 'A second request resumes the existing generation.' );
		self::assertSame( 2, $result->products );
		self::assertSame( 1, $result->services );
		self::assertCount( 2, simplexml_load_file( $this->storage->path( $result->files[0]['name'] ) )->Proizvodi->Proizvod );
		self::assertNull( $this->generator( null, $this->collector( [], 0 ) )->step( '__resume' ), 'A stale continuation is a no-op.' );
	}

	public function test_second_writer_failure_preserves_previous_generation_and_retries_without_recollection(): void {
		$old = $this->generator()->run();
		$oldManifest = file_get_contents( $this->storage->path( Manifest::FILENAME ) );
		$oldXml = file_get_contents( $this->storage->path( $old->files[0]['name'] ) );
		$csv = \Mockery::mock( Writer::class );
		$csv->shouldReceive( 'write' )->once()->andThrow( new \RuntimeException( 'Disk full' ) );
		$failed = $this->generator( null, null, [ 'xml' => new XmlWriter(), 'csv' => $csv ] )->run();
		self::assertSame( 'Disk full', $failed->error );
		self::assertSame( $oldManifest, file_get_contents( $this->storage->path( Manifest::FILENAME ) ) );
		self::assertSame( $oldXml, file_get_contents( $this->storage->path( $old->files[0]['name'] ) ) );
		$retry = $this->generator( null, $this->collector( [], 0 ) )->run();
		self::assertTrue( $retry->ok(), (string) $retry->error );
		self::assertNotSame( $old->files[0]['name'], $retry->files[0]['name'], 'Same-second generations cannot overwrite live files.' );
		self::assertCount( 4, ( new Manifest( $this->storage ) )->entries() );
	}

	public function test_interrupted_publication_reuses_completed_files(): void {
		$g = $this->generator();
		self::assertNull( $g->step() ); // Collection.
		self::assertNull( $g->step() ); // XML.
		self::assertNull( $g->step() ); // CSV.
		$state = json_decode( file_get_contents( $this->storage->tmpPath( 'generation.json' ) ), true );
		$this->storage->publish( $state['files'][0]['name'] ); // Killed between the two renames.
		self::assertSame( [], ( new Manifest( $this->storage ) )->entries() );
		$result = $this->generator( null, $this->collector( [], 0 ) )->run();
		self::assertTrue( $result->ok(), (string) $result->error );
		self::assertCount( 2, ( new Manifest( $this->storage ) )->entries() );
	}

	public function test_cache_flush_cannot_remove_a_live_generation_lock(): void {
		$lock = $this->storage->lock();
		$GLOBALS['scwc_test_transients'] = []; // Redis eviction / flush does not control this lock.
		self::assertFalse( $this->generator( null, $this->collector( [], 0 ) )->run()->ok() );
		fclose( $lock );
		self::assertTrue( $this->generator()->run()->ok() );
	}

	public function test_previous_day_checkpoint_is_restarted_instead_of_publishing_stale_rows(): void {
		self::assertNull( $this->generator()->step() );
		$path = $this->storage->tmpPath( 'generation.json' );
		$state = json_decode( file_get_contents( $path ), true );
		$state['at'] = '2026-09-30T06:00:12+02:00';
		file_put_contents( $path, json_encode( $state ) );
		$result = $this->generator( null, $this->collector( [ $this->items()[0] ] ) )->run();
		self::assertTrue( $result->ok(), (string) $result->error );
		self::assertSame( 1, $result->products );
		self::assertSame( 0, $result->services );
		self::assertSame( '2026-10-01', $result->generatedAt->format( 'Y-m-d' ) );
	}

	public function test_generation_result_ok(): void {
		$at = new DateTimeImmutable( '2026-10-01 06:00:12' );
		self::assertTrue( ( new GenerationResult( [], $at, 'manual', null, 0, 0 ) )->ok() );
		self::assertFalse( ( new GenerationResult( [], $at, 'manual', 'x', 0, 0 ) )->ok() );
	}

	public function test_lock_storage_failure_is_reported_in_status(): void {
		$this->storage = \Mockery::mock( Storage::class );
		$this->storage->shouldReceive( 'lock' )->once()->andThrow( new \RuntimeException( 'Uploads not writable' ) );
		Functions\expect( 'update_option' )->once()->with( Generator::OPTION_LAST, \Mockery::on( fn( array $last ) => 'Uploads not writable' === $last['error'] ) );
		$result = $this->generator( null, $this->collector( [], 0 ) )->step();
		self::assertSame( 'Uploads not writable', $result->error );
	}

	public function test_corrupt_checkpoint_restarts_without_losing_the_published_catalogue(): void {
		$old = $this->generator()->run();
		$oldXml = file_get_contents( $this->storage->path( $old->files[0]['name'] ) );
		file_put_contents( $this->storage->tmpPath( 'generation.json' ), '{broken' );
		$result = $this->generator()->run( '__resume' );
		self::assertTrue( $result->ok(), (string) $result->error );
		self::assertSame( 'scheduled', $result->reason );
		self::assertSame( 2, $result->products );
		self::assertSame( $oldXml, file_get_contents( $this->storage->path( $old->files[0]['name'] ) ) );
	}

	/** @dataProvider damagedRows */
	public function test_missing_or_truncated_rows_restart_from_zero( bool $missing ): void {
		self::assertNull( $this->generator()->step() );
		$path = $this->storage->tmpPath( 'rows.ndjson' );
		if ( $missing ) {
			unlink( $path );
		} else {
			file_put_contents( $path, '' );
		}
		$result = $this->generator()->run( '__resume' );
		self::assertTrue( $result->ok(), (string) $result->error );
		self::assertSame( 2, $result->products );
		self::assertSame( 1, $result->services );
	}

	public static function damagedRows(): array {
		return [ 'missing' => [ true ], 'truncated' => [ false ] ];
	}

	public function test_repeated_worker_deaths_stop_after_three_attempts(): void {
		self::assertNull( $this->generator()->step() );
		$path = $this->storage->tmpPath( 'generation.json' );
		$state = json_decode( file_get_contents( $path ), true );
		self::assertSame( 0, $state['attempts'], 'Successful progress resets the retry budget.' );
		$state['attempts'] = 3; // Three workers died after persisting the attempt, before completing the file.
		file_put_contents( $path, json_encode( $state ) );
		$result = $this->generator( null, $this->collector( [], 0 ) )->step( '__resume' );
		self::assertStringContainsString( 'failed three times', $result->error );
		self::assertFalse( $this->generator( null, $this->collector( [], 0 ) )->pending() );
		self::assertSame( [], $this->storage->listFiles() );
		self::assertNull( $this->generator( null, $this->collector( [], 0 ) )->step( '__resume' ) );
	}

	public function test_sync_resume_without_a_checkpoint_returns_instead_of_looping(): void {
		$result = $this->generator( null, $this->collector( [], 0 ) )->run( '__resume' );
		self::assertSame( 'Nema generiranja za nastavak.', $result->error );
	}
}
