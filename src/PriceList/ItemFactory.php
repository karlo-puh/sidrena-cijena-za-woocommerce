<?php
/**
 * Turns product snapshots into price-list rows (or drops them).
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\PriceList;

use SidrenaCijena\Product\ProductSnapshot;
use SidrenaCijena\Product\ServiceRule;
use SidrenaCijena\Product\UnitPrice;
use SidrenaCijena\Reference\ReferencePriceRegistry;
use SidrenaCijena\Reference\ReferencePriceRepository;
use SidrenaCijena\Settings\Settings;
use WC_Product;

class ItemFactory {

	public function __construct(
		private readonly Settings $settings,
		private readonly ReferencePriceRegistry $registry,
		private readonly ReferencePriceRepository $references,
		private readonly ServiceRule $serviceRule,
	) {}

	/**
	 * @param WC_Product           $product  Product (needed for tax conversion).
	 * @param ProductSnapshot      $snapshot Snapshot of $product.
	 * @param ProductSnapshot|null $parent   Parent snapshot for variations.
	 * @return Item|ServiceItem|null Null when the product is not listed.
	 */
	/**
	 * @return Item|ServiceItem|null Null when the product is not listed.
	 */
	public function make( WC_Product $product, ProductSnapshot $snapshot, ?ProductSnapshot $parent ) {
		if ( ! $this->isListed( $snapshot ) ) {
			return null;
		}
		$price = $this->toDisplay( $product, $snapshot->active );
		$base  = $this->basePrice( $product, $snapshot, $parent );

		if ( $this->serviceRule->isService( $snapshot ) ) {
			$item = new ServiceItem( $snapshot->id, $snapshot->name, $price, $snapshot->isOnSale, $base );
		} else {
			// One barcode field in WooCommerce: a GTIN-shaped number is the EAN, anything else the shop's own barcode.
			$isEan = 1 === preg_match( '/^(?:\d{8}|\d{12,14})$/', $snapshot->barcode );
			$item  = new Item(
				$snapshot->id,
				$snapshot->name,
				'' !== $snapshot->sku ? $snapshot->sku : (string) $snapshot->id,
				$snapshot->brand,
				$snapshot->unitQuantity,
				$snapshot->unit,
				$price,
				$snapshot->isOnSale,
				UnitPrice::compute( $price, $snapshot->unitQuantity ),
				$isEan ? '' : $snapshot->barcode,
				$isEan ? $snapshot->barcode : '',
				$base,
			);
		}

		$item = apply_filters( 'scwc_price_list_item', $item, $snapshot );
		return ( $item instanceof Item || $item instanceof ServiceItem ) ? $item : null;
	}

	/** Retail prices in the public list always include tax (maloprodajna cijena). */
	public function toDisplay( WC_Product $product, string $amount ): float {
		return round( (float) wc_get_price_including_tax( $product, [ 'price' => $amount ] ), 2 );
	}

	private function isListed( ProductSnapshot $s ): bool {
		if ( $s->excludeFromPriceList || $s->priceOnRequest || $s->isContainer() ) {
			return false;
		}
		if ( '' === $s->active || ! is_numeric( $s->active ) ) {
			return false;
		}
		if ( 'publish' !== $s->status ) {
			return false;
		}
		if ( ! $s->visible && ! (bool) $this->settings->get( 'price_list.include_hidden', false ) ) {
			return false;
		}
		if ( 'outofstock' === $s->stockStatus && ! (bool) $this->settings->get( 'price_list.include_out_of_stock', true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * @return array<string,array{amount:?float,date:?string}>
	 */
	/** The primary base price (bazna cijena); the optional second type is not part of the legal list. */
	private function basePrice( WC_Product $product, ProductSnapshot $snapshot, ?ProductSnapshot $parent ): ?float {
		$inherit = (bool) $this->settings->get( 'reference_prices.variation_inherit_parent', false );
		$ref     = $this->references->get( $this->registry->primary(), $snapshot, $parent, $inherit );
		return $ref->isPresent() ? $this->toDisplay( $product, (string) $ref->amount ) : null;
	}
}
