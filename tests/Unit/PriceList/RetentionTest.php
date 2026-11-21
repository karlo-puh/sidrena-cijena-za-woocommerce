<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\PriceList;

use SidrenaCijena\PriceList\Manifest;
use SidrenaCijena\PriceList\Retention;
use SidrenaCijena\PriceList\Storage;
use SidrenaCijena\Tests\Support\FixedClock;
use SidrenaCijena\Tests\TestCase;

/** "Now" is 2026-10-01 04:00 UTC, so with 15 days a file replaced before 2026-09-16 04:00 goes. */
final class RetentionTest extends TestCase {
	private string $dir;
	private Storage $storage;
	private Manifest $manifest;

	protected function setUp(): void {
		parent::setUp();
		$this->dir      = sys_get_temp_dir() . '/scwc-retention-' . uniqid();
		$this->storage  = new Storage( $this->dir, 'https://example.hr/u/scwc-cjenik' );
		$this->storage->ensure();
		$this->manifest = new Manifest( $this->storage );
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->dir . '/*.*' ) ?: [] );
		@rmdir( $this->dir . '/tmp' );
		@rmdir( $this->dir );
		parent::tearDown();
	}

	/** Adds a manifest entry (call oldest first; the manifest is newest first). Kind '' = pre-2.0 file. */
	private function file( string $name, string $utc, string $kind = 'roba', string $outlet = 'web1', bool $onDisk = true ): void {
		if ( $onDisk ) {
			file_put_contents( $this->storage->path( $name ), 'x' );
		}
		$entry = [ 'name' => $name, 'format' => substr( $name, -3 ), 'generated_at_utc' => $utc ];
		if ( '' !== $kind ) {
			$entry += [ 'kind' => $kind, 'outlet' => $outlet ];
		}
		$this->manifest->add( $entry );
	}

	/** @param string[] $knownKeys */
	private function prune( int $days = 15, array $knownKeys = [] ): array {
		return ( new Retention( $this->manifest, $this->storage, new FixedClock( '2026-10-01 04:00:00' ) ) )->prune( $days, 'web1', $knownKeys );
	}

	public function test_file_goes_n_days_after_it_was_replaced_not_after_it_was_made(): void {
		$this->file( 'a.csv', '2026-07-01 04:00:00' ); // Replaced 08-01: gone.
		$this->file( 'b.csv', '2026-08-01 04:00:00' ); // Old, but replaced only on 09-20: kept.
		$this->file( 'c.csv', '2026-09-20 04:00:00' );
		$this->file( 'd.csv', '2026-09-28 04:00:00' ); // Current.
		self::assertSame( [], $this->prune( 90 ), 'a longer setting keeps replaced files longer' );
		self::assertSame( [ 'a.csv' ], $this->prune() );
		self::assertFileDoesNotExist( $this->storage->path( 'a.csv' ) );
		self::assertSame( [ 'd.csv', 'c.csv', 'b.csv' ], array_column( ( new Manifest( $this->storage ) )->entries(), 'name' ) );
	}

	public function test_window_is_never_shorter_than_fifteen_days(): void {
		$this->file( 'a.csv', '2026-09-01 04:00:00' );
		$this->file( 'b.csv', '2026-09-15 04:00:00' ); // Replaced a 16 days ago.
		$this->file( 'c.csv', '2026-09-20 04:00:00' ); // Replaced b 11 days ago.
		self::assertSame( [ 'a.csv' ], $this->prune( 1 ) );
	}

	public function test_current_file_of_each_outlet_and_kind_is_never_deleted(): void {
		$this->file( 'roba-old.csv', '2026-01-01 04:00:00' );
		$this->file( 'usluge.csv', '2026-01-01 04:00:00', 'usluge' );
		$this->file( 'zg-roba.csv', '2026-01-01 04:00:00', 'roba', 'zg-02' );
		$this->file( 'roba-new.csv', '2026-01-02 04:00:00' );
		self::assertSame( [ 'roba-old.csv' ], $this->prune() );
		self::assertSame( [ 'roba-new.csv', 'zg-roba.csv', 'usluge.csv' ], array_column( $this->manifest->entries(), 'name' ) );
	}

	public function test_pre_20_files_are_replaced_by_the_oldest_20_file_of_their_outlet(): void {
		$this->file( 'old1.xml', '2026-08-01 04:00:00', '' );
		$this->file( 'old1.csv', '2026-08-01 04:00:00', '' );
		$this->file( 'old2.xml', '2026-08-15 04:00:00', '' );
		$this->file( 'old2.csv', '2026-08-15 04:00:00', '' );
		$this->file( 'zg.xml', '2026-08-15 04:00:00', '', 'zg-02' ); // No outlet key: belongs to the primary (web1).
		$this->file( 'new1.csv', '2026-09-10 04:00:00' ); // First 2.0 file: replaced old2 26 days ago.
		$this->file( 'new2.csv', '2026-09-30 04:00:00' );
		self::assertEqualsCanonicalizing( [ 'old1.xml', 'old1.csv', 'old2.xml', 'old2.csv', 'zg.xml' ], $this->prune() );
		self::assertSame( [ 'new2.csv', 'new1.csv' ], array_column( $this->manifest->entries(), 'name' ) );
	}

	public function test_pre_20_files_stay_until_15_days_after_the_first_20_file(): void {
		$this->file( 'old1.xml', '2026-08-01 04:00:00', '' );
		$this->file( 'old1.csv', '2026-08-01 04:00:00', '' );
		$this->file( 'old2.xml', '2026-08-02 04:00:00', '' ); // Same-time pair: neither replaces the other.
		$this->file( 'old2.csv', '2026-08-02 04:00:00', '' );
		self::assertEqualsCanonicalizing( [ 'old1.xml', 'old1.csv' ], $this->prune(), 'without 2.0 files the newest old pair is current' );
		$this->file( 'new.csv', '2026-09-25 04:00:00' );
		self::assertSame( [], $this->prune() );
		self::assertTrue( $this->manifest->has( 'old2.xml' ) );
	}

	public function test_files_of_removed_outlets_count_as_replaced_when_they_were_made(): void {
		$this->file( 'web1.csv', '2026-01-01 04:00:00' );
		$this->file( 'gone-old.csv', '2026-09-01 04:00:00', 'roba', 'gone' );
		$this->file( 'gone-new.csv', '2026-09-10 04:00:00', 'roba', 'gone' );
		$this->file( 'gone2.csv', '2026-09-20 04:00:00', 'roba', 'gone2' ); // Made 11 days ago.
		self::assertSame( [ 'gone-old.csv' ], $this->prune(), 'without known keys every outlet keeps its current file' );
		self::assertSame( [ 'gone-new.csv' ], $this->prune( 15, [ 'web1' ] ) );
		self::assertSame( [ 'gone2.csv', 'web1.csv' ], array_column( $this->manifest->entries(), 'name' ) );
	}

	public function test_entries_whose_file_vanished_are_dropped_and_undated_ones_left_alone(): void {
		$this->file( 'weird.csv', 'not-a-date' );
		$this->file( 'gone.csv', '2026-09-30 04:00:00', 'roba', 'web1', false );
		self::assertSame( [ 'gone.csv' ], $this->prune() );
		self::assertSame( [ 'weird.csv' ], array_column( $this->manifest->entries(), 'name' ) );
	}
}
