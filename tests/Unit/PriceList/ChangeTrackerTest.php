<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\PriceList;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use SidrenaCijena\PriceList\ChangeTracker;
use SidrenaCijena\Tests\Support\FakeWpdb;
use SidrenaCijena\Tests\TestCase;

final class ChangeTrackerTest extends TestCase {
	private FakeWpdb $wpdb;
	private int $scheduled = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$this->scheduled = 0;
	}

	private function tracker(): ChangeTracker {
		return new ChangeTracker( $this->wpdb, function (): void {
			++$this->scheduled;
		} );
	}

	public function test_mark_marks_the_product_and_its_variations_and_schedules_a_run(): void {
		$this->wpdb->colResults = [ [ '11', '12' ] ];
		$marked                 = [];
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) use ( &$marked ) {
			$marked[ $id ] = [ $key, $value ];
			return true;
		} );
		$this->tracker()->mark( 10 );
		self::assertSame( [ 10, 11, 12 ], array_keys( $marked ) );
		self::assertSame( ChangeTracker::META, $marked[11][0] );
		self::assertMatchesRegularExpression( '/^\d+\.\d{4}$/', $marked[10][1] );
		self::assertStringContainsString( "post_parent = 10 AND post_type = 'product_variation'", $this->wpdb->queries[0] );
		self::assertSame( 1, $this->scheduled );

		$this->tracker()->mark( 0 );
		self::assertSame( 1, $this->scheduled, 'invalid IDs are ignored' );
	}

	public function test_saves_inside_a_wc_stock_update_are_not_marked_unless_stock_matters(): void {
		$marked = [];
		Functions\when( 'update_post_meta' )->alias( function ( $id ) use ( &$marked ) {
			$marked[] = $id;
			return true;
		} );
		$product = $this->product( [ 'id' => 7 ] );
		$t       = $this->tracker();
		$t->onStockStart( $product );
		$t->onSaved( 7 ); // The save inside wc_update_product_stock() (order stock reduction).
		$t->onStockEnd( $product );
		self::assertSame( [], $marked );
		$t->onSaved( 7 ); // An ordinary save afterwards.
		self::assertSame( [ 7 ], $marked );

		Actions\expectAdded( 'woocommerce_product_before_set_stock' )->never();
		( new ChangeTracker( $this->wpdb, static fn() => null, true ) )->register();
	}

	public function test_status_changes_mark_only_products_entering_or_leaving_publish(): void {
		$marked = [];
		Functions\when( 'update_post_meta' )->alias( function ( $id ) use ( &$marked ) {
			$marked[] = $id;
			return true;
		} );
		$t = $this->tracker();
		$t->onStatus( 'publish', 'draft', new \WP_Post( [ 'ID' => 1, 'post_type' => 'product' ] ) );
		$t->onStatus( 'trash', 'publish', new \WP_Post( [ 'ID' => 2, 'post_type' => 'product_variation' ] ) );
		$t->onStatus( 'publish', 'publish', new \WP_Post( [ 'ID' => 3, 'post_type' => 'product' ] ) );
		$t->onStatus( 'draft', 'pending', new \WP_Post( [ 'ID' => 4, 'post_type' => 'product' ] ) );
		$t->onStatus( 'publish', 'draft', new \WP_Post( [ 'ID' => 5, 'post_type' => 'post' ] ) );
		$t->onStatus( 'publish', 'draft', (object) [ 'ID' => 6, 'post_type' => 'product' ] );
		self::assertSame( [ 1, 2 ], $marked );
	}

	public function test_take_clear_and_clear_before_build_the_expected_queries(): void {
		$this->wpdb->resultsResults = [ [ (object) [ 'post_id' => '5', 'meta_value' => '1.5000' ], (object) [ 'post_id' => '9', 'meta_value' => '2.0000' ] ] ];
		self::assertSame( [ 5 => '1.5000', 9 => '2.0000' ], $this->tracker()->take( 200 ) );
		self::assertSame( "SELECT post_id, meta_value FROM wp_postmeta WHERE meta_key = '_scwc_pl_changed' ORDER BY post_id ASC LIMIT 200", $this->wpdb->queries[0] );

		Functions\expect( 'delete_post_meta' )->once()->with( 5, ChangeTracker::META, '1.5000' );
		Functions\expect( 'delete_post_meta' )->once()->with( 9, ChangeTracker::META, '2.0000' );
		$this->tracker()->clear( [ 5 => '1.5000', 9 => '2.0000' ] );

		$this->wpdb->queries    = [];
		$this->wpdb->colResults = [ [], [ '3', '4' ] ];
		Functions\expect( 'wp_cache_delete' )->twice()->with( \Mockery::anyOf( 3, 4 ), 'post_meta' );
		$this->tracker()->clearBefore( 1700000000.25 );
		self::assertCount( 1, $this->wpdb->queries, 'nothing to delete: no DELETE query' );
		$this->tracker()->clearBefore( 1700000000.25 );
		self::assertSame( "DELETE FROM wp_postmeta WHERE meta_key = '_scwc_pl_changed' AND CAST(meta_value AS DECIMAL(14,4)) < 1700000000.250000", $this->wpdb->queries[2] );
	}
}
