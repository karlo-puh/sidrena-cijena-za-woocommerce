<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\PriceList;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use SidrenaCijena\PriceList\Item;
use SidrenaCijena\PriceList\ItemFactory;
use SidrenaCijena\PriceList\ServiceItem;
use SidrenaCijena\Product\ProductSnapshot;
use SidrenaCijena\Product\ServiceRule;
use SidrenaCijena\Reference\CategoryOverrideResolver;
use SidrenaCijena\Reference\ReferenceDateResolver;
use SidrenaCijena\Reference\ReferencePriceRegistry;
use SidrenaCijena\Reference\ReferencePriceRepository;
use SidrenaCijena\Settings\Defaults;
use SidrenaCijena\Settings\Settings;
use SidrenaCijena\Tests\TestCase;

final class ItemFactoryTest extends TestCase {
	private function factory( ?Settings $settings = null ): ItemFactory {
		$settings = $settings ?? new Settings( Defaults::all() );
		return new ItemFactory(
			$settings,
			ReferencePriceRegistry::fromSettings( $settings ),
			new ReferencePriceRepository( new ReferenceDateResolver( new CategoryOverrideResolver( fn() => [] ) ) ),
			new ServiceRule( (string) $settings->get( 'price_list.service_rule' ) ),
		);
	}

	/**
	 * @param array<string,mixed> $a
	 */
	private function snapshot( array $a = [] ): ProductSnapshot {
		return ProductSnapshot::fromArray( array_merge( [ 'id' => 1, 'name' => 'Proizvod', 'regular' => '12.99', 'active' => '12.99' ], $a ) );
	}

	public function test_maps_a_simple_product_to_an_item(): void {
		$s = $this->snapshot(
			[
				'sku'          => 'SKU-1',
				'brand'        => 'Marka',
				'barcode'      => '3850001',
				'unit'         => 'kg',
				'unitQuantity' => 0.5,
				'meta'         => [ '_scwc_ref_anchor_price' => '14.99' ],
			]
		);
		$item = $this->factory()->make( $this->product( [ 'id' => 1 ] ), $s, null );
		self::assertInstanceOf( Item::class, $item );
		self::assertSame( 1, $item->productId );
		self::assertSame( 'Proizvod', $item->name );
		self::assertSame( 'SKU-1', $item->sku );
		self::assertSame( 'Marka', $item->brand );
		self::assertSame( 0.5, $item->netQuantity );
		self::assertSame( 'kg', $item->unit );
		self::assertSame( 12.99, $item->price );
		self::assertFalse( $item->isSpecialSale );
		self::assertSame( 25.98, $item->unitPrice );
		self::assertSame( '3850001', $item->barcode, 'not GTIN-shaped: the shop\'s own barcode' );
		self::assertSame( '', $item->ean );
		self::assertSame( 14.99, $item->basePrice );
		$noSku = $this->factory()->make( $this->product( [ 'id' => 77, 'regular_price' => '1' ] ), ProductSnapshot::fromArray( [ 'id' => 77, 'regular' => '1', 'active' => '1' ] ), null );
		self::assertSame( '77', $noSku->sku, 'šifra is mandatory: fall back to the product ID' );
		self::assertNull( $noSku->basePrice );
	}

	/** @return array<string,array{0:string,1:bool}> */
	public static function barcodes(): array {
		return [
			'EAN-8'      => [ '12345678', true ],
			'UPC-12'     => [ '123456789012', true ],
			'EAN-13'     => [ '3850001234567', true ],
			'GTIN-14'    => [ '13850001234564', true ],
			'7 digits'   => [ '1234567', false ],
			'9 digits'   => [ '123456789', false ],
			'15 digits'  => [ '123456789012345', false ],
			'with text'  => [ 'ABC12345678', false ],
			'with space' => [ '385 0001234567', false ],
			'empty'      => [ '', false ],
		];
	}

	/** @dataProvider barcodes */
	public function test_gtin_shaped_barcode_goes_to_ean_column( string $barcode, bool $isEan ): void {
		$item = $this->factory()->make( $this->product( [ 'id' => 1 ] ), $this->snapshot( [ 'barcode' => $barcode ] ), null );
		self::assertSame( $isEan ? $barcode : '', $item->ean );
		self::assertSame( $isEan ? '' : $barcode, $item->barcode );
	}

	public function test_on_sale_product_is_special_sale(): void {
		$s    = $this->snapshot( [ 'sale' => '9.99', 'active' => '9.99', 'isOnSale' => true ] );
		$item = $this->factory()->make( $this->product( [ 'id' => 1 ] ), $s, null );
		self::assertTrue( $item->isSpecialSale );
		self::assertSame( 9.99, $item->price );
		self::assertNull( $item->unitPrice );
		self::assertNull( $item->netQuantity );
	}

	public function test_excluded_products_yield_null(): void {
		$f = $this->factory();
		$p = $this->product( [ 'id' => 1 ] );
		self::assertNull( $f->make( $p, $this->snapshot( [ 'excludeFromPriceList' => true ] ), null ) );
		self::assertNull( $f->make( $p, $this->snapshot( [ 'priceOnRequest' => true ] ), null ) );
		self::assertNull( $f->make( $p, $this->snapshot( [ 'active' => '' ] ), null ) );
		self::assertNull( $f->make( $p, $this->snapshot( [ 'active' => 'abc' ] ), null ) );
		self::assertNull( $f->make( $p, $this->snapshot( [ 'status' => 'draft' ] ), null ) );
		self::assertNotNull( $f->make( $p, $this->snapshot( [ 'visible' => false ] ), null ), 'hidden products are listed by default (purchasable via URL)' );
		$strict = $this->factory( ( new Settings( Defaults::all() ) )->with( 'price_list.include_hidden', false ) );
		self::assertNull( $strict->make( $p, $this->snapshot( [ 'visible' => false ] ), null ) );
		self::assertNull( $f->make( $p, $this->snapshot( [ 'type' => 'variable' ] ), null ) );
		self::assertNull( $f->make( $p, $this->snapshot( [ 'type' => 'grouped' ] ), null ) );
	}

	public function test_hidden_products_included_when_setting_allows(): void {
		$settings = ( new Settings( Defaults::all() ) )->with( 'price_list.include_hidden', true );
		self::assertNotNull( $this->factory( $settings )->make( $this->product( [ 'id' => 1 ] ), $this->snapshot( [ 'visible' => false ] ), null ) );
	}

	public function test_out_of_stock_handling_follows_setting(): void {
		$s = $this->snapshot( [ 'stockStatus' => 'outofstock' ] );
		self::assertNotNull( $this->factory()->make( $this->product( [ 'id' => 1 ] ), $s, null ) );
		$settings = ( new Settings( Defaults::all() ) )->with( 'price_list.include_out_of_stock', false );
		self::assertNull( $this->factory( $settings )->make( $this->product( [ 'id' => 1 ] ), $s, null ) );
	}

	public function test_prices_always_include_tax_rounded_to_two_decimals(): void {
		$settings = ( new Settings( Defaults::all() ) )->with( 'price_list.tax_mode', 'excl' ); // Legacy value, ignored.
		Functions\expect( 'wc_get_price_excluding_tax' )->never();
		Functions\expect( 'wc_get_price_including_tax' )->atLeast()->once()->andReturnUsing( fn( $p, array $args ) => (float) $args['price'] * 1.25 );
		$item = $this->factory( $settings )->make( $this->product( [ 'id' => 1 ] ), $this->snapshot( [ 'active' => '9.99', 'meta' => [ '_scwc_ref_anchor_price' => '20' ] ] ), null );
		self::assertSame( 12.49, $item->price );
		self::assertSame( 25.0, $item->basePrice );
	}

	public function test_na_hides_base_price_and_only_the_primary_type_is_exported(): void {
		$na = $this->factory()->make( $this->product( [ 'id' => 1 ] ), $this->snapshot( [ 'meta' => [ '_scwc_ref_anchor_price' => '5', '_scwc_ref_anchor_na' => '1' ] ] ), null );
		self::assertNull( $na->basePrice );
		$settings = ( new Settings( Defaults::all() ) )->with( 'reference_prices.base.enabled', true )->with( 'reference_prices.base.date', '2026-11-17' );
		$s        = $this->snapshot( [ 'meta' => [ '_scwc_ref_anchor_price' => '12', '_scwc_ref_base_price' => '11' ] ] );
		self::assertSame( 12.0, $this->factory( $settings )->make( $this->product( [ 'id' => 1 ] ), $s, null )->basePrice );
	}

	public function test_variation_inherits_parent_base_price_when_enabled(): void {
		$settings = ( new Settings( Defaults::all() ) )->with( 'reference_prices.variation_inherit_parent', true );
		$parent   = ProductSnapshot::fromArray( [ 'id' => 10, 'type' => 'variable', 'meta' => [ '_scwc_ref_anchor_price' => '7', '_scwc_ref_anchor_date' => '2026-02-02' ] ] );
		$child    = $this->snapshot( [ 'id' => 11, 'parentId' => 10, 'type' => 'variation', 'active' => '8' ] );
		$item     = $this->factory( $settings )->make( $this->product( [ 'id' => 11, 'type' => 'variation' ] ), $child, $parent );
		self::assertSame( 7.0, $item->basePrice );
		self::assertSame( 11, $item->productId );
		self::assertNull( $this->factory()->make( $this->product( [ 'id' => 11, 'type' => 'variation' ] ), $child, $parent )->basePrice );
	}

	public function test_virtual_product_becomes_service_item(): void {
		$s    = $this->snapshot( [ 'name' => 'Montaža', 'isVirtual' => true, 'sale' => '40', 'active' => '40', 'isOnSale' => true, 'meta' => [ '_scwc_ref_anchor_price' => '50' ] ] );
		$item = $this->factory()->make( $this->product( [ 'id' => 1, 'virtual' => true ] ), $s, null );
		self::assertInstanceOf( ServiceItem::class, $item );
		self::assertSame( 1, $item->productId );
		self::assertSame( 'Montaža', $item->name );
		self::assertSame( 40.0, $item->price );
		self::assertTrue( $item->isSpecialSale );
		self::assertSame( 50.0, $item->basePrice );
	}

	public function test_item_filter_can_drop_or_replace(): void {
		Filters\expectApplied( 'scwc_price_list_item' )->once()->with( \Mockery::type( Item::class ), \Mockery::type( ProductSnapshot::class ) )->andReturn( null );
		self::assertNull( $this->factory()->make( $this->product( [ 'id' => 1 ] ), $this->snapshot(), null ) );
	}
}
