<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\PriceList;

use SidrenaCijena\PriceList\Collector;
use SidrenaCijena\PriceList\Item;
use SidrenaCijena\PriceList\ItemFactory;
use SidrenaCijena\PriceList\ServiceItem;
use SidrenaCijena\Product\BarcodeResolver;
use SidrenaCijena\Product\BrandResolver;
use SidrenaCijena\Product\ProductAdapter;
use SidrenaCijena\Product\ProductSnapshot;
use SidrenaCijena\Product\ServiceRule;
use SidrenaCijena\Reference\CategoryOverrideResolver;
use SidrenaCijena\Reference\ReferenceDateResolver;
use SidrenaCijena\Reference\ReferencePriceRegistry;
use SidrenaCijena\Reference\ReferencePriceRepository;
use SidrenaCijena\Settings\Defaults;
use SidrenaCijena\Settings\Settings;
use SidrenaCijena\Tests\TestCase;

final class CollectorTest extends TestCase {
	private function factory(): ItemFactory {
		$settings = new Settings( Defaults::all() );
		return new ItemFactory(
			$settings,
			ReferencePriceRegistry::fromSettings( $settings ),
			new ReferencePriceRepository( new ReferenceDateResolver( new CategoryOverrideResolver( fn() => [] ) ) ),
			new ServiceRule(),
		);
	}

	/**
	 * @param array<int,int[]> $pages
	 */
	private function collector( array $pages, int $perPage, ?callable $observer = null ): Collector {
		return new Collector(
			fn( int $after, int $per ) => array_slice( array_values( array_filter( array_merge( ...array_values( $pages ) ), fn( $id ) => $id > $after ) ), 0, $per ),
			fn( int $id ) => \wc_get_product( $id ) ?: null,
			new ProductAdapter( new BrandResolver(), new BarcodeResolver() ),
			$this->factory(),
			$perPage,
			$observer,
		);
	}

	public function test_pages_until_a_short_page_and_yields_items(): void {
		foreach ( [ 1, 2, 3, 4 ] as $id ) {
			$this->product( [ 'id' => $id, 'name' => 'P' . $id, 'regular_price' => '10' ] );
		}
		$this->product( [ 'id' => 5, 'name' => 'Usluga', 'regular_price' => '99', 'virtual' => true ] );
		$calls = [];
		$pager = function ( int $after, int $per ) use ( &$calls ) {
			$calls[] = [ $after, $per ];
			return array_slice( array_values( array_filter( [ 1, 2, 3, 4, 5 ], fn( $id ) => $id > $after ) ), 0, $per );
		};
		$collector = new Collector( $pager, fn( int $id ) => \wc_get_product( $id ) ?: null, new ProductAdapter( new BrandResolver(), new BarcodeResolver() ), $this->factory(), 2 );
		$items     = iterator_to_array( $collector->items(), false );
		self::assertCount( 5, $items );
		self::assertSame( [ 'P1', 'P2', 'P3', 'P4' ], array_map( fn( $i ) => $i->name, array_slice( $items, 0, 4 ) ) );
		self::assertInstanceOf( ServiceItem::class, $items[4] );
		self::assertSame( [ [ 0, 2 ], [ 2, 2 ], [ 4, 2 ], [ 5, 2 ] ], $calls );
	}

	public function test_variable_products_are_expanded_into_variations_only(): void {
		$this->product( [ 'id' => 11, 'type' => 'variation', 'parent_id' => 10, 'name' => 'Majica - S', 'regular_price' => '10' ] );
		$this->product( [ 'id' => 12, 'type' => 'variation', 'parent_id' => 10, 'name' => 'Majica - M', 'regular_price' => '12', 'meta' => [ '_scwc_ref_anchor_price' => '13' ] ] );
		$this->product( [ 'id' => 10, 'type' => 'variable', 'name' => 'Majica', 'children' => [ 11, 12, 999 ], 'meta' => [ '_scwc_unit' => 'kom' ] ] );
		$items = iterator_to_array( $this->collector( [ 1 => [ 10, 11, 12, 999 ] ], 50 )->items(), false );
		self::assertCount( 2, $items );
		self::assertContainsOnlyInstancesOf( Item::class, $items );
		self::assertSame( [ 'Majica - S', 'Majica - M' ], array_map( fn( Item $i ) => $i->name, $items ) );
		self::assertSame( 'kom', $items[0]->unit ); // inherited from parent via adapter
		self::assertSame( 13.0, $items[1]->references['anchor']['amount'] );
	}

	public function test_missing_and_excluded_products_are_skipped(): void {
		$this->product( [ 'id' => 1, 'regular_price' => '10', 'meta' => [ '_scwc_exclude_from_price_list' => 'yes' ] ] );
		$this->product( [ 'id' => 2, 'regular_price' => '10', 'catalog_visibility' => 'hidden' ] );
		$this->product( [ 'id' => 3, 'regular_price' => '10' ] );
		$items = iterator_to_array( $this->collector( [ 1 => [ 1, 2, 3, 404 ] ], 50 )->items(), false );
		self::assertCount( 2, $items, 'excluded flag and missing product skipped; hidden products are listed by default (still purchasable)' );
	}

	public function test_observer_sees_every_non_container_snapshot(): void {
		$this->product( [ 'id' => 1, 'regular_price' => '10', 'meta' => [ '_scwc_exclude_from_price_list' => 'yes' ] ] );
		$this->product( [ 'id' => 11, 'type' => 'variation', 'parent_id' => 10, 'regular_price' => '10' ] );
		$this->product( [ 'id' => 10, 'type' => 'variable', 'children' => [ 11 ] ] );
		$seen = [];
		$obs  = function ( ProductSnapshot $s ) use ( &$seen ) {
			$seen[] = $s->id;
		};
		iterator_to_array( $this->collector( [ 1 => [ 1, 10, 11 ] ], 50, $obs )->items(), false );
		self::assertSame( [ 1, 11 ], $seen );
	}

	public function test_wc_pager_is_a_callable(): void {
		self::assertIsCallable( Collector::wcPager() );
	}

	public function test_variations_are_bounded_and_unpublished_parents_are_excluded(): void {
		$this->product( [ 'id' => 10, 'type' => 'variable', 'status' => 'draft', 'children' => [ 11 ] ] );
		$this->product( [ 'id' => 11, 'type' => 'variation', 'parent_id' => 10, 'regular_price' => '10' ] );
		$this->product( [ 'id' => 20, 'type' => 'variable', 'children' => range( 21, 30 ) ] );
		foreach ( range( 21, 30 ) as $id ) {
			$this->product( [ 'id' => $id, 'type' => 'variation', 'parent_id' => 20, 'regular_price' => '10' ] );
		}
		$collector = $this->collector( [ 1 => array_merge( [ 10, 11, 20 ], range( 21, 30 ) ) ], 3 );
		$first = $collector->batch( 0 );
		self::assertSame( [], $first['items'] );
		self::assertSame( 20, $first['cursor'] );
		self::assertFalse( $first['done'] );
		$next = $collector->batch( $first['cursor'] );
		self::assertCount( 3, $next['items'], 'A parent with many variations must not defeat the batch limit.' );
		self::assertSame( 23, $next['cursor'] );
		self::assertCount( 10, iterator_to_array( $collector->items() ) );
	}

	public function test_custom_variation_type_also_requires_a_published_parent(): void {
		$this->product( [ 'id' => 10, 'type' => 'variable', 'status' => 'draft' ] );
		new \WC_Product_Variation( [ 'id' => 11, 'type' => 'subscription_variation', 'parent_id' => 10, 'regular_price' => '10' ] );
		self::assertSame( [], $this->collector( [ 1 => [ 11 ] ], 10 )->batch( 0 )['items'] );
	}
}
