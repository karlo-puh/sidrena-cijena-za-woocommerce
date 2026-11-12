<?php
/**
 * Picks the variation a variable product is shown as (price, card image, preselected variation):
 * among variations in stock (all of them when none is), the one with the largest real discount
 * against its own 30-day low before the sale; without any real discount, the cheapest. Ties go to
 * the lower price.
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\Display;

use SidrenaCijena\History\DiscountCalculator;
use SidrenaCijena\Product\MetaKeys;
use SidrenaCijena\Settings\Settings;
use WC_Product;

final class FeaturedVariation {

	/** @var callable(int):?WC_Product */
	private $loader;

	/** @var array<int,int> Parent ID → featured variation ID (0 = none) for this request. */
	private array $cache = [];

	/**
	 * @param callable(int):?WC_Product $productLoader Loads a product by ID.
	 */
	public function __construct( private readonly Settings $settings, callable $productLoader ) {
		$this->loader = $productLoader;
	}

	public function enabled(): bool {
		return (bool) $this->settings->get( 'display.variable_featured', false );
	}

	public function forParent( WC_Product $parent, string $context = '' ): ?WC_Product {
		if ( ! $parent instanceof \WC_Product_Variable || ! $this->enabled() ) {
			return null;
		}
		if ( BadgeContext::LOOP === $context && 'none' === $this->settings->get( 'display.variable_loop', 'range' ) ) {
			return null;
		}
		$id = $parent->get_id();
		if ( ! isset( $this->cache[ $id ] ) ) {
			$this->cache[ $id ] = $this->pick( $parent );
		}
		return $this->cache[ $id ] ? ( $this->loader )( $this->cache[ $id ] ) : null;
	}

	private function pick( \WC_Product_Variable $parent ): int {
		// Raw (stored) prices of the parent's priced variations; the stored 30-day low is raw too.
		$prices = $parent->get_variation_prices( false );
		$ids    = array_map( 'intval', array_keys( $prices['price'] ?? [] ) );
		if ( [] === $ids ) {
			return 0;
		}
		if ( function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $ids );
		}
		$ranked = [];
		foreach ( $ids as $vid ) {
			$price   = (float) $prices['price'][ $vid ];
			$regular = (float) ( $prices['regular_price'][ $vid ] ?? 0 );
			$low     = get_post_meta( $vid, MetaKeys::OMNIBUS_REF_PRICE, true );
			$percent = ( $price < $regular && is_numeric( $low ) ) ? (int) DiscountCalculator::percent( (float) $low, $price ) : 0;
			// In stock first (an out-of-stock variation is a dead end), then a real discount, larger
			// discount, lower price, lower ID.
			$ranked[ $vid ] = [ 'outofstock' !== get_post_meta( $vid, '_stock_status', true ), $percent > 0, $percent, -$price, -$vid ];
		}
		uasort( $ranked, static fn( array $a, array $b ): int => $b <=> $a );
		// The best one the shop actually offers (other plugins can hide variations or make them unpurchasable).
		foreach ( array_keys( $ranked ) as $vid ) {
			$variation = ( $this->loader )( (int) $vid );
			if ( $variation instanceof \WC_Product_Variation && $variation->variation_is_visible() && $variation->is_purchasable() ) {
				return (int) $vid;
			}
		}
		return 0;
	}
}
