<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\Display;

use Brain\Monkey\Functions;
use SidrenaCijena\Display\BadgeContext;
use SidrenaCijena\Display\FeaturedVariation;
use SidrenaCijena\Settings\Defaults;
use SidrenaCijena\Settings\Settings;
use SidrenaCijena\Tests\TestCase;

final class FeaturedVariationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key = '', bool $single = false ) => isset( \WC_Product::$registry[ $id ] ) ? \WC_Product::$registry[ $id ]->get_meta( $key ) : '' );
	}

	private function featured( bool $on = true, string $variableLoop = 'range' ): FeaturedVariation {
		$settings = ( new Settings( Defaults::all() ) )->with( 'display.variable_featured', $on )->with( 'display.variable_loop', $variableLoop );
		return new FeaturedVariation( $settings, fn( int $id ) => \wc_get_product( $id ) ?: null );
	}

	/**
	 * @param array<int,array{0:string,1:string,2:?string,3?:string}> $variants id => [regular, sale, 30-day low, stock status]
	 */
	private function variable( int $id, array $variants ): \WC_Product {
		foreach ( $variants as $vid => $v ) {
			[ $regular, $sale, $low ] = $v;
			$meta = null === $low ? [] : [ '_scwc_omnibus_ref_price' => $low ];
			if ( isset( $v[3] ) ) {
				$meta['_stock_status'] = $v[3];
			}
			$this->product(
				[
					'id'            => $vid,
					'type'          => 'variation',
					'parent_id'     => $id,
					'regular_price' => $regular,
					'sale_price'    => $sale,
					'meta'          => $meta,
				]
			);
		}
		return $this->product(
			[
				'id'       => $id,
				'type'     => 'variable',
				'children' => array_keys( $variants ),
			]
		);
	}

	public function test_picks_the_variation_with_the_largest_real_discount(): void {
		$parent = $this->variable(
			10,
			[
				11 => [ '100', '90', '100' ],  // 10 %.
				12 => [ '200', '150', '200' ], // 25 % – largest, though not the cheapest.
				13 => [ '80', '', null ],      // Not on sale, cheapest.
			]
		);
		self::assertSame( 12, $this->featured()->forParent( $parent )?->get_id() );
	}

	public function test_discount_is_measured_against_the_30_day_low_not_the_regular_price(): void {
		$parent = $this->variable(
			20,
			[
				21 => [ '200', '150', '160' ], // 25 % from regular, only 6 % from its 30-day low.
				22 => [ '100', '80', '100' ],  // 20 % from its 30-day low.
			]
		);
		self::assertSame( 22, $this->featured()->forParent( $parent )?->get_id() );
	}

	public function test_in_stock_variations_win_over_bigger_discounts_out_of_stock(): void {
		$parent = $this->variable(
			35,
			[
				36 => [ '200', '100', '200', 'outofstock' ], // 50 %, cannot be bought.
				37 => [ '100', '90', '100', 'instock' ],     // 10 %.
			]
		);
		self::assertSame( 37, $this->featured()->forParent( $parent )?->get_id() );
		$noDiscountInStock = $this->variable(
			33,
			[
				34 => [ '200', '100', '200', 'outofstock' ], // Discounted, out of stock: a dead end.
				40 => [ '150', '', null, 'instock' ],         // Not discounted, but can be bought.
			]
		);
		self::assertSame( 40, $this->featured()->forParent( $noDiscountInStock )?->get_id(), 'in stock beats a discount out of stock' );
		$allOut = $this->variable( 38, [ 39 => [ '200', '100', '200', 'outofstock' ] ] );
		self::assertSame( 39, $this->featured()->forParent( $allOut )?->get_id(), 'nothing in stock: still the discounted one' );
	}

	public function test_variations_the_shop_does_not_offer_are_skipped(): void {
		$parent = $this->variable(
			60,
			[
				61 => [ '200', '100', '200' ], // 50 %, but made unpurchasable by another plugin.
				62 => [ '100', '90', '100' ],  // 10 %.
			]
		);
		$this->product(
			[
				'id'            => 61,
				'type'          => 'variation',
				'parent_id'     => 60,
				'regular_price' => '200',
				'sale_price'    => '100',
				'purchasable'   => false,
				'meta'          => [ '_scwc_omnibus_ref_price' => '200' ],
			]
		);
		self::assertSame( 62, $this->featured()->forParent( $parent )?->get_id() );
	}

	public function test_equal_discount_prefers_the_lower_price(): void {
		$parent = $this->variable(
			30,
			[
				31 => [ '200', '160', '200' ],
				32 => [ '100', '80', '100' ],
			]
		);
		self::assertSame( 32, $this->featured()->forParent( $parent )?->get_id() );
	}

	public function test_without_a_real_discount_the_cheapest_in_stock_variation_is_shown(): void {
		$parent = $this->variable(
			40,
			[
				41 => [ '100', '90', '90' ],               // 30-day low equals today's price: no real reduction.
				42 => [ '100', '', null ],                  // Not on sale.
				43 => [ '100', '85', null, 'instock' ],     // On sale but no 30-day low recorded: cheapest in stock.
				44 => [ '80', '', null, 'outofstock' ],     // Cheapest, but out of stock.
			]
		);
		self::assertSame( 43, $this->featured()->forParent( $parent )?->get_id() );
	}

	public function test_off_by_default_and_respects_hidden_variable_badges_in_lists(): void {
		$parent = $this->variable( 50, [ 51 => [ '100', '80', '100' ] ] );
		self::assertNull( $this->featured( false )->forParent( $parent ) );
		self::assertNull( $this->featured( true, 'none' )->forParent( $parent, BadgeContext::LOOP ) );
		self::assertSame( 51, $this->featured( true, 'none' )->forParent( $parent, BadgeContext::SINGLE )?->get_id() );
		self::assertNull( $this->featured()->forParent( $this->product( [ 'id' => 52, 'regular_price' => '10' ] ) ), 'simple products have no featured variation' );
	}
}
