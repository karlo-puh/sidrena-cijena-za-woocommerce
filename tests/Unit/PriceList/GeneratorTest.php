<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\PriceList;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use SidrenaCijena\PriceList\ChangeTracker;
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
use SidrenaCijena\Settings\Defaults;
use SidrenaCijena\Settings\Settings;
use SidrenaCijena\Tests\Support\FixedClock;
use SidrenaCijena\Tests\TestCase;

final class GeneratorTest extends TestCase {

	private const GOODS    = '12345678901_webshop_ulica-1_web1_ROBA_1_20261001_0600.csv';
	private const SERVICES = '12345678901_webshop_ulica-1_web1_USLUGE_1_20261001_0600.csv';

	private string $dir;
	private Storage $storage;
	private Manifest $manifest;
	private FixedClock $clock;
	/** @var Outlet[] */
	private array $extraOutlets = [];
	/** @var \Mockery\MockInterface&ChangeTracker */
	private $changes;

	protected function setUp(): void {
		parent::setUp();
		$this->dir      = sys_get_temp_dir() . '/scwc-generator-' . uniqid();
		$this->storage  = new Storage( $this->dir, 'https://example.hr/u/scwc-cjenik' );
		$this->manifest = new Manifest( $this->storage );
		$this->clock    = new FixedClock( '2026-10-01 04:00:12' );
		$this->changes  = \Mockery::mock( ChangeTracker::class );
		$this->changes->shouldReceive( 'clearBefore' )->byDefault();
		$this->changes->shouldReceive( 'count' )->andReturn( 0 )->byDefault();
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->dir . '/tmp/*' ) ?: [] );
		array_map( 'unlink', glob( $this->dir . '/*.*' ) ?: [] );
		@rmdir( $this->dir . '/tmp' );
		@rmdir( $this->dir );
		parent::tearDown();
	}

	private function settings(): Settings {
		return ( new Settings( Defaults::all() ) )->with( 'price_list.seller_id', '12345678901' );
	}

	/** @param array<int,Item|ServiceItem> $items */
	private function collector( array $items, int $times = 1 ): Collector {
		$collector = \Mockery::mock( Collector::class );
		$collector->shouldReceive( 'batch' )->times( $times )->with( 0 )->andReturn(
			[
				'items'  => $items,
				'cursor' => 99,
				'done'   => true,
			]
		);
		return $collector;
	}

	private function outlet( bool $complete = true ): Outlet {
		return new Outlet( 'webshop', $complete ? 'Ulica 1' : '', 'WEB1', '1', 'Trgovina', 'https://example.hr/' );
	}

	private static function goods( int $id, float $price = 10.0 ): Item {
		return new Item( $id, 'Proizvod ' . $id, 'S' . $id, '', null, 'kom', $price, false, null, '', '', 12.0 );
	}

	/** @return array<int,Item|ServiceItem> */
	private function items(): array {
		return [ self::goods( 1 ), self::goods( 2, 8.0 ), new ServiceItem( 3, 'Usluga', 40.0, false, 45.0 ) ];
	}

	private function generator( ?Settings $settings = null, ?Collector $collector = null, bool $completeOutlet = true ): Generator {
		$settings = $settings ?? $this->settings();
		return new Generator(
			$settings,
			fn() => array_merge( [ $this->outlet( $completeOutlet ) ], $this->extraOutlets ),
			$collector ?? $this->collector( $this->items() ),
			new CsvWriter( false ),
			new FilenameBuilder(),
			$this->storage,
			$this->manifest,
			new Retention( $this->manifest, $this->storage, $this->clock ),
			$this->clock,
			$this->changes,
		);
	}

	/** @return string[] Lines of a published file. */
	private function lines( string $name ): array {
		return array_values( array_filter( explode( "\n", (string) file_get_contents( $this->storage->path( $name ) ) ) ) );
	}

	public function test_full_build_publishes_goods_and_services_lists(): void {
		Functions\expect( 'update_option' )->once()->with(
			Generator::OPTION_LAST,
			\Mockery::on(
				static fn( array $o ) => '2026-10-01 04:00:12' === $o['at'] && '2026-10-01 04:00:12' === $o['published_at']
					&& [ self::GOODS, self::SERVICES ] === $o['files'] && 2 === $o['products'] && 1 === $o['services'] && '' === $o['error']
			)
		);
		Actions\expectDone( 'scwc_price_list_generated' )->once()->with( \Mockery::type( GenerationResult::class ) );
		$this->changes->shouldReceive( 'clearBefore' )->once();

		$result = $this->generator()->run( 'scheduled' );

		self::assertTrue( $result->ok(), (string) $result->error );
		self::assertSame( [ self::GOODS, self::SERVICES ], array_column( $result->files, 'name' ) );
		self::assertSame( 'https://example.hr/u/scwc-cjenik/' . self::GOODS, $result->files[0]['url'] );
		self::assertCount( 3, $this->lines( self::GOODS ), 'header + 2 goods' );
		self::assertCount( 2, $this->lines( self::SERVICES ), 'header + 1 service' );
		self::assertStringStartsWith( 'naziv;sifra;marka;', $this->lines( self::GOODS )[0] );

		$entry = $this->manifest->entry( self::GOODS );
		self::assertSame( 'roba', $entry['kind'] );
		self::assertSame( 1, $entry['number'] );
		self::assertSame( 'web1', $entry['outlet'] );
		self::assertSame( '2026-10-01 04:00:12', $entry['generated_at_utc'] );
		self::assertSame( hash_file( 'sha256', $this->storage->path( self::GOODS ) ), $entry['sha256'] );
		self::assertSame( [ 'rows-full.config', 'rows-full.ndjson' ], array_map( 'basename', glob( $this->dir . '/tmp/*' ) ?: [] ) );
	}

	public function test_unchanged_list_is_not_published_again(): void {
		$this->generator()->run();
		$this->clock->set( '2026-10-02 04:00:00' );
		Functions\expect( 'get_option' )->andReturn(
			[
				'published_at' => '2026-10-01 04:00:12',
				'files'        => [ self::GOODS, self::SERVICES ],
			]
		);
		Functions\expect( 'update_option' )->once()->with(
			Generator::OPTION_LAST,
			\Mockery::on( static fn( array $o ) => '2026-10-02 04:00:00' === $o['at'] && '2026-10-01 04:00:12' === $o['published_at'] && [ self::GOODS, self::SERVICES ] === $o['files'] )
		);

		$result = $this->generator()->run();

		self::assertTrue( $result->ok() );
		self::assertSame( [], $result->files );
		self::assertCount( 2, $this->manifest->entries() );
	}

	public function test_changed_kind_gets_the_next_number_and_the_other_kind_stays(): void {
		$this->generator()->run();
		$this->clock->set( '2026-10-01 08:30:00' );
		$items    = $this->items();
		$items[0] = self::goods( 1, 9.0 );

		$result = $this->generator( null, $this->collector( $items ) )->run();

		self::assertSame( [ '12345678901_webshop_ulica-1_web1_ROBA_2_20261001_1030.csv' ], array_column( $result->files, 'name' ) );
		self::assertSame( '12345678901_webshop_ulica-1_web1_ROBA_2_20261001_1030.csv', $this->manifest->latest( 'csv', 'web1', 'web1', 'roba' )['name'] );
		self::assertSame( self::SERVICES, $this->manifest->latest( 'csv', 'web1', 'web1', 'usluge' )['name'] );
		self::assertFileExists( $this->storage->path( self::GOODS ), 'Replaced files stay available.' );
	}

	public function test_shop_without_services_publishes_no_services_file(): void {
		$result = $this->generator( null, $this->collector( [ self::goods( 1 ) ] ) )->run();
		self::assertSame( [ self::GOODS ], array_column( $result->files, 'name' ) );
	}

	public function test_seller_id_is_required(): void {
		$result = $this->generator( new Settings( Defaults::all() ), $this->collector( [], 0 ) )->run();
		self::assertFalse( $result->ok() );
		self::assertStringContainsString( 'OIB', (string) $result->error );
		self::assertSame( [], $this->storage->listFiles() );
	}

	public function test_incomplete_outlet_is_an_error_without_files(): void {
		Actions\expectDone( 'scwc_price_list_generated' )->never();
		$result = $this->generator( null, $this->collector( [], 0 ), false )->run();
		self::assertSame( 'Podaci o prodajnom objektu nisu potpuni.', $result->error );
		self::assertSame( [], $this->storage->listFiles() );
	}

	public function test_every_outlet_gets_its_own_files(): void {
		$this->extraOutlets = [ ( new Outlet( 'poslovnica', 'Vukovarska 5', 'ZG-02', '1', 'Trgovina', '' ) )->withKey( 'zg-02', false ) ];
		$result             = $this->generator()->run();
		self::assertSame( [ 'web1', 'web1', 'zg-02', 'zg-02' ], array_column( $result->files, 'outlet' ) );
		self::assertSame( '12345678901_poslovnica_vukovarska-5_zg-02_ROBA_1_20261001_0600.csv', $result->files[2]['name'] );
	}

	public function test_shared_list_is_one_file_per_kind_for_all_outlets(): void {
		$this->extraOutlets = [ ( new Outlet( 'poslovnica', 'Vukovarska 5', 'ZG-02', '1', 'Trgovina', '' ) )->withKey( 'zg-02', false ) ];
		$result             = $this->generator( $this->settings()->with( 'price_list.shared_list', true ) )->run();
		self::assertSame(
			[ '12345678901_ZA_SVA_PRODAJNA_MJESTA_ROBA_1_20261001_0600.csv', '12345678901_ZA_SVA_PRODAJNA_MJESTA_USLUGE_1_20261001_0600.csv' ],
			array_column( $result->files, 'name' )
		);
	}

	public function test_outlet_data_change_publishes_a_new_file_with_identical_rows(): void {
		$this->generator()->run();
		$this->clock->set( '2026-10-01 05:00:00' );
		$result = $this->generator( $this->settings()->with( 'price_list.seller_id', '98765432109' ) )->run();
		self::assertCount( 2, $result->files );
		self::assertStringStartsWith( '98765432109_', $result->files[0]['name'] );
	}

	public function test_change_run_rebuilds_only_marked_products(): void {
		$this->generator()->run();
		$this->clock->set( '2026-10-01 07:00:00' );
		$marks = [
			1 => '1.0',
			2 => '1.0',
			5 => '1.0',
		];
		$this->changes->shouldReceive( 'count' )->andReturn( 3 );
		$this->changes->shouldReceive( 'take' )->once()->with( Generator::CHANGE_BATCH )->andReturn( $marks );
		$this->changes->shouldReceive( 'clear' )->once()->with( $marks );
		$this->changes->shouldReceive( 'scheduleFollowUp' )->never();
		$collector = \Mockery::mock( Collector::class );
		$collector->shouldReceive( 'batch' )->never();
		$collector->shouldReceive( 'itemsFor' )->once()->with( [ 1, 2, 5 ] )->andReturn(
			[
				1 => self::goods( 1, 7.0 ), // Price changed.
				2 => null,                  // Unpublished.
				5 => self::goods( 5 ),      // New.
			]
		);

		$result = $this->generator( null, $collector )->run( 'change' );

		self::assertTrue( $result->ok(), (string) $result->error );
		self::assertSame( [ '12345678901_webshop_ulica-1_web1_ROBA_2_20261001_0900.csv' ], array_column( $result->files, 'name' ), 'Services unchanged.' );
		$lines = $this->lines( $result->files[0]['name'] );
		self::assertCount( 3, $lines, implode( "\n", $lines ) );
		self::assertStringStartsWith( '"Proizvod 1";S1;', $lines[1] );
		self::assertStringContainsString( ';7.00;', $lines[1] );
		self::assertStringStartsWith( '"Proizvod 5";S5;', $lines[2] );
		self::assertFalse( $this->generator( null, $collector )->pending() );
	}

	public function test_a_large_change_is_merged_in_batches_and_published_once(): void {
		$this->generator()->run();
		$this->clock->set( '2026-10-01 03:00:00' );
		$collector = \Mockery::mock( Collector::class );
		// Batch 1 of 2: more marks wait, so the rows are merged but nothing is published yet.
		$this->changes->shouldReceive( 'count' )->andReturn( 2, 2 );
		$this->changes->shouldReceive( 'take' )->once()->andReturn( [ 1 => '1.0' ] );
		$this->changes->shouldReceive( 'clear' )->once()->with( [ 1 => '1.0' ] );
		$this->changes->shouldReceive( 'scheduleFollowUp' )->once();
		$collector->shouldReceive( 'itemsFor' )->once()->with( [ 1 ] )->andReturn( [ 1 => self::goods( 1, 7.0 ) ] );
		Functions\expect( 'update_option' )->never(); // Not a completed check: the public list is not updated yet.
		self::assertNull( $this->generator( null, $collector )->step( 'change' ) );

		// Batch 2 (the last): both changes are published in one file.
		Functions\expect( 'update_option' )->once();
		$this->changes  = \Mockery::mock( ChangeTracker::class );
		$this->changes->shouldReceive( 'count' )->andReturn( 1 );
		$this->changes->shouldReceive( 'take' )->once()->andReturn( [ 2 => '1.0' ] );
		$this->changes->shouldReceive( 'clear' )->once();
		$this->changes->shouldReceive( 'scheduleFollowUp' )->never();
		$collector->shouldReceive( 'itemsFor' )->once()->with( [ 2 ] )->andReturn( [ 2 => self::goods( 2, 6.0 ) ] );
		$last = $this->generator( null, $collector )->run( 'change' );
		self::assertSame( [ '12345678901_webshop_ulica-1_web1_ROBA_2_20261001_0500.csv' ], array_column( $last->files, 'name' ) );
		$lines = $this->lines( $last->files[0]['name'] );
		self::assertStringContainsString( ';7.00;', $lines[1] );
		self::assertStringContainsString( ';6.00;', $lines[2] );
	}

	public function test_batches_publish_one_by_one_near_the_11_00_deadline(): void {
		$this->generator()->run();
		$this->clock->set( '2026-10-01 08:50:00' ); // 10:50 local.
		$this->changes->shouldReceive( 'count' )->andReturn( 5 );
		$this->changes->shouldReceive( 'take' )->once()->andReturn( [ 1 => '1.0' ] );
		$this->changes->shouldReceive( 'clear' )->once();
		$this->changes->shouldReceive( 'scheduleFollowUp' )->never();
		$collector = \Mockery::mock( Collector::class );
		$collector->shouldReceive( 'itemsFor' )->once()->andReturn( [ 1 => self::goods( 1, 7.0 ) ] );
		self::assertCount( 1, $this->generator( null, $collector )->run( 'change' )->files );
	}

	public function test_change_run_without_marks_publishes_rows_merged_earlier(): void {
		$this->generator()->run();
		$rows = $this->storage->tmpPath( Generator::ROWS );
		file_put_contents( $rows, str_replace( '"price":10', '"price":11', (string) file_get_contents( $rows ) ) ); // A merged, unpublished batch.
		$collector = \Mockery::mock( Collector::class );
		$collector->shouldReceive( 'itemsFor' )->never();
		$result = $this->generator( null, $collector )->run( 'change' );
		self::assertCount( 1, $result->files );
		self::assertStringContainsString( ';11.00;', $this->lines( $result->files[0]['name'] )[1] );
	}

	public function test_change_run_without_marks_publishes_nothing(): void {
		$this->generator()->run();
		$collector = \Mockery::mock( Collector::class );
		$collector->shouldReceive( 'batch' )->never();
		$collector->shouldReceive( 'itemsFor' )->never();
		$result = $this->generator( null, $collector )->run( 'change' );
		self::assertTrue( $result->ok() );
		self::assertSame( [], $result->files );
	}

	/** @dataProvider fullBuildInstead */
	public function test_change_falls_back_to_a_full_build( string $case ): void {
		if ( 'settings' === $case ) {
			$this->generator()->run();
			$settings = $this->settings()->with( 'price_list.slug', 'cjenik-novi' );
		} else {
			$settings = $this->settings(); // No rows kept yet.
		}
		$this->changes->shouldReceive( 'count' )->andReturn( 'many' === $case ? Generator::FULL_ABOVE + 1 : 3 );
		$this->changes->shouldReceive( 'take' )->never();
		$this->changes->shouldReceive( 'scheduleFollowUp' );
		if ( 'many' === $case ) {
			$this->generator()->run();
		}
		$result = $this->generator( $settings, $this->collector( $this->items() ) )->run( 'change' );
		self::assertTrue( $result->ok(), (string) $result->error );
	}

	/** @return array<string,array{string}> */
	public static function fullBuildInstead(): array {
		return [
			'no rows kept'     => [ 'none' ],
			'settings changed' => [ 'settings' ],
			'too many marks'   => [ 'many' ],
		];
	}

	public function test_marks_made_during_a_full_build_get_a_follow_up_run(): void {
		$this->changes->shouldReceive( 'count' )->andReturn( 4 );
		$this->changes->shouldReceive( 'scheduleFollowUp' )->once();
		self::assertTrue( $this->generator()->run( 'scheduled' )->ok() );
	}

	public function test_refuses_to_run_while_locked(): void {
		$lock = $this->storage->lock();
		Functions\expect( 'update_option' )->never();
		$result = $this->generator( null, $this->collector( [], 0 ) )->run();
		self::assertTrue( $result->busy );
		self::assertSame( 'Generiranje je već u tijeku.', $result->error );
		fclose( $lock );
	}

	public function test_disabled_price_list_is_an_error_without_files(): void {
		$result = $this->generator( $this->settings()->with( 'price_list.enabled', false ), $this->collector( [], 0 ) )->run();
		self::assertFalse( $result->ok() );
		self::assertSame( [], $this->storage->listFiles() );
	}

	public function test_replaced_files_are_pruned_after_the_retention_window(): void {
		$this->generator()->run();
		$this->clock->set( '2026-10-01 05:00:00' );
		$items    = $this->items();
		$items[0] = self::goods( 1, 9.0 );
		$this->generator( null, $this->collector( $items ) )->run();
		$this->clock->set( '2026-10-17 05:00:01' ); // 16 days after the replacement.

		$collector = \Mockery::mock( Collector::class );
		$collector->shouldReceive( 'itemsFor' )->never();
		$this->changes->shouldReceive( 'count' )->andReturn( 0 );
		$this->generator( null, $collector )->run( 'change' );

		self::assertFalse( $this->storage->exists( self::GOODS ) );
		self::assertTrue( $this->storage->exists( self::SERVICES ), 'The current services list is never deleted.' );
	}

	public function test_bulk_request_restarts_a_build_in_progress(): void {
		$collector = \Mockery::mock( Collector::class );
		$collector->shouldReceive( 'batch' )->once()->with( 0 )->andReturn(
			[
				'items'  => [ self::goods( 1 ) ],
				'cursor' => 1,
				'done'   => false,
			]
		);
		self::assertNull( $this->generator( null, $collector )->step( 'scheduled' ) );
		// An import changed product 1, which was already collected: start again from the first product.
		$result = $this->generator( null, $this->collector( [ self::goods( 1, 5.0 ) ] ) )->run( 'bulk' );
		self::assertTrue( $result->ok(), (string) $result->error );
		self::assertSame( 'bulk', $result->reason );
		self::assertStringContainsString( ';5.00;', $this->lines( $result->files[0]['name'] )[1] );
	}

	public function test_batches_resume_after_an_interrupted_append_without_duplicates(): void {
		$collector = \Mockery::mock( Collector::class );
		$collector->shouldReceive( 'batch' )->once()->with( 0 )->andReturn(
			[
				'items'  => [ self::goods( 1 ) ],
				'cursor' => 1,
				'done'   => false,
			]
		);
		self::assertNull( $this->generator( null, $collector )->step( 'scheduled' ) );
		file_put_contents( $this->storage->tmpPath( 'rows.ndjson' ), "garbage from a dead worker\n", FILE_APPEND );
		$collector->shouldReceive( 'batch' )->once()->with( 1 )->andReturn(
			[
				'items'  => [ self::goods( 2 ) ],
				'cursor' => 2,
				'done'   => true,
			]
		);
		$result = $this->generator( null, $collector )->run( 'manual' );
		self::assertTrue( $result->ok(), (string) $result->error );
		self::assertSame( 'scheduled', $result->reason, 'A second request resumes the existing generation.' );
		self::assertSame( 2, $result->products );
		self::assertNull( $this->generator( null, $this->collector( [], 0 ) )->step( '__resume' ), 'A stale continuation is a no-op.' );
	}

	public function test_previous_day_checkpoint_is_restarted(): void {
		self::assertNull(
			$this->generator(
				null,
				\Mockery::mock( Collector::class )->shouldReceive( 'batch' )->andReturn(
					[
						'items'  => [ self::goods( 7 ) ],
						'cursor' => 7,
						'done'   => false,
					]
				)->getMock()
			)->step()
		);
		$this->clock->set( '2026-10-02 04:00:00' );
		$result = $this->generator( null, $this->collector( [ self::goods( 1 ) ] ) )->run();
		self::assertTrue( $result->ok(), (string) $result->error );
		self::assertSame( 1, $result->products );
		self::assertCount( 2, $this->lines( $result->files[0]['name'] ), 'Yesterday\'s partial rows are not published.' );
	}

	public function test_corrupt_checkpoint_restarts(): void {
		$this->storage->ensure();
		file_put_contents( $this->storage->tmpPath( 'generation.json' ), '{broken' );
		$result = $this->generator()->run( '__resume' );
		self::assertTrue( $result->ok(), (string) $result->error );
		self::assertSame( 'scheduled', $result->reason );
		self::assertSame( 2, $result->products );
	}

	public function test_repeated_worker_deaths_stop_after_three_attempts(): void {
		$collector = \Mockery::mock( Collector::class );
		$collector->shouldReceive( 'batch' )->once()->andReturn(
			[
				'items'  => [ self::goods( 1 ) ],
				'cursor' => 1,
				'done'   => false,
			]
		);
		self::assertNull( $this->generator( null, $collector )->step() );
		$path  = $this->storage->tmpPath( 'generation.json' );
		$state = json_decode( (string) file_get_contents( $path ), true );
		self::assertSame( 0, $state['attempts'], 'Successful progress resets the retry budget.' );
		$state['attempts'] = 3;
		file_put_contents( $path, json_encode( $state ) );
		$result = $this->generator( null, $this->collector( [], 0 ) )->step( '__resume' );
		self::assertStringContainsString( 'failed three times', (string) $result->error );
		self::assertFalse( $this->generator( null, $this->collector( [], 0 ) )->pending() );
	}

	public function test_lock_storage_failure_is_reported_in_status(): void {
		$this->storage = \Mockery::mock( Storage::class );
		$this->storage->shouldReceive( 'lock' )->once()->andThrow( new \RuntimeException( 'Uploads not writable' ) );
		Functions\expect( 'update_option' )->once()->with( Generator::OPTION_LAST, \Mockery::on( fn( array $last ) => 'Uploads not writable' === $last['error'] ) );
		$result = $this->generator( null, $this->collector( [], 0 ) )->step();
		self::assertSame( 'Uploads not writable', $result->error );
	}

	public function test_sync_resume_without_a_checkpoint_returns_instead_of_looping(): void {
		$result = $this->generator( null, $this->collector( [], 0 ) )->run( '__resume' );
		self::assertSame( 'Nema generiranja za nastavak.', $result->error );
	}

	public function test_generation_result_ok(): void {
		$at = new DateTimeImmutable( '2026-10-01 06:00:12' );
		self::assertTrue( ( new GenerationResult( [], $at, 'manual', null, 0, 0 ) )->ok() );
		self::assertFalse( ( new GenerationResult( [], $at, 'manual', 'x', 0, 0 ) )->ok() );
	}
}
