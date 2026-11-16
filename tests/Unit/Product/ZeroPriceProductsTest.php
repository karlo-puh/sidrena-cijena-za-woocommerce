<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\Product;

use Brain\Monkey\Functions;
use SidrenaCijena\Product\ZeroPriceProducts;
use SidrenaCijena\Tests\Support\FakeWpdb;
use SidrenaCijena\Tests\TestCase;

final class ZeroPriceProductsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['scwc_test_transients'][ ZeroPriceProducts::TRANSIENT ], $_GET['scwc_price'] );
	}

	protected function tearDown(): void {
		unset( $_GET['scwc_price'] );
		parent::tearDown();
	}

	public function test_lists_published_products_with_a_zero_regular_price_and_parents_of_such_variations(): void {
		$wpdb               = new FakeWpdb();
		$wpdb->colResults[] = [ '1845', '9150' ];
		self::assertSame( [ 1845, 9150 ], ( new ZeroPriceProducts( $wpdb ) )->ids() );
		$sql = $wpdb->queries[0];
		self::assertStringContainsString( "IF(p.post_type = 'product_variation', p.post_parent, p.ID)", $sql, 'variations are listed as their product' );
		self::assertStringContainsString( 'CAST(rp.meta_value AS DECIMAL(20,6)) = 0', $sql );
		self::assertStringContainsString( "rp.meta_key = '_regular_price' AND rp.meta_value <> ''", $sql, 'an empty price is not 0 €' );
		self::assertStringContainsString( "parent.post_status = 'publish'", $sql );
		self::assertStringContainsString( "IN ('variable','grouped')", $sql );
	}

	public function test_count_is_cached_and_flushed(): void {
		$wpdb               = new FakeWpdb();
		$wpdb->colResults[] = [ 1, 2, 3 ];
		$z                  = new ZeroPriceProducts( $wpdb );
		self::assertSame( 3, $z->count() );
		self::assertSame( 3, $z->count() );
		self::assertCount( 1, $wpdb->queries );
		$z->flush();
		$wpdb->colResults[] = [];
		self::assertSame( 0, $z->count() );
	}

	public function test_save_affects_the_list_only_for_zero_priced_or_listed_products(): void {
		$z = new ZeroPriceProducts( new FakeWpdb() );
		self::assertFalse( $z->affectedBySave( 30, new \WC_Product( [ 'id' => 30, 'regular_price' => '0' ] ) ), 'nothing cached yet' );
		$GLOBALS['scwc_test_transients'][ ZeroPriceProducts::TRANSIENT ] = [ 10 ];
		self::assertFalse( $z->affectedBySave( 20, new \WC_Product( [ 'id' => 20, 'regular_price' => '19.90' ] ) ), 'an ordinary priced product' );
		self::assertTrue( $z->affectedBySave( 11, new \WC_Product( [ 'id' => 11, 'parent_id' => 10, 'regular_price' => '5' ] ) ), 'a listed product (via its variation) got a price' );
		self::assertTrue( $z->affectedBySave( 30, new \WC_Product( [ 'id' => 30, 'regular_price' => '0' ] ) ), 'a product that became 0 €' );
	}

	public function test_search_keeps_the_filter(): void {
		$z = new ZeroPriceProducts( new FakeWpdb() );
		ob_start();
		$z->keepFilter( 'product' );
		self::assertSame( '', ob_get_clean(), 'not on the normal list' );
		$_GET['scwc_price'] = 'zero';
		ob_start();
		$z->keepFilter( 'shop_order' );
		$z->keepFilter( 'product' );
		self::assertSame( '<input type="hidden" name="scwc_price" value="zero">', ob_get_clean() );
	}

	public function test_products_list_filter(): void {
		$wpdb = new FakeWpdb();
		$z    = new ZeroPriceProducts( $wpdb );
		self::assertSame( 'https://example.hr/wp-admin/edit.php?post_type=product&scwc_price=zero', ZeroPriceProducts::listUrl() );
		$query = new class() {
			/** @var array<string,mixed> */
			public array $vars = [ 'post_type' => 'product' ];
			public function is_main_query(): bool { return true; }
			public function get( string $k ) { return $this->vars[ $k ] ?? ''; }
			public function set( string $k, $v ): void { $this->vars[ $k ] = $v; }
		};
		Functions\when( 'is_admin' )->justReturn( true );
		$z->filterList( $query );
		self::assertArrayNotHasKey( 'post__in', $query->vars, 'the normal products list is untouched' );

		$_GET['scwc_price'] = 'zero';
		$wpdb->colResults[] = [ 7, 8 ];
		$z->filterList( $query );
		self::assertSame( [ 7, 8 ], $query->vars['post__in'] );
		$wpdb->colResults[] = [ 7, 8 ];
		$query->vars['post__in'] = [ 8, 99 ]; // A product search matched 8 and a priced product.
		$z->filterList( $query );
		self::assertSame( [ 8 ], $query->vars['post__in'], 'search stays inside the 0 € list' );
		$wpdb->colResults[] = [ 7, 8 ];
		$query->vars['post__in'] = [ 0 ]; // A search that matched nothing.
		$z->filterList( $query );
		self::assertSame( [ 0 ], $query->vars['post__in'] );
		$wpdb->colResults[] = [];
		unset( $query->vars['post__in'] );
		$z->filterList( $query );
		self::assertSame( [ 0 ], $query->vars['post__in'], 'none left: an empty list, not all products' );
	}
}
