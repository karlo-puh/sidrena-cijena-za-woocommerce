<?php
/**
 * Combines WooCommerce's price HTML with the badge (and rebuilds <del> with the 30-day low).
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\Display;

use SidrenaCijena\Settings\Settings;
use WC_Product;

final class PriceHtmlComposer {

	public function __construct( private readonly Settings $settings, private readonly PriceBadge $badge ) {}

	public function compose( string $wcHtml, WC_Product $product, BadgeData $data, string $context ): string {
		$html  = $this->maybeRebuildSaleHtml( $wcHtml, $product, $data, $context );
		$badge = $this->badge->render( $data, $context );
		if ( '' === $badge ) {
			return $html;
		}
		return 'before' === $this->settings->get( 'display.position', 'after' )
			? $badge . ' ' . $html
			: $html . ' ' . $badge;
	}

	private function maybeRebuildSaleHtml( string $wcHtml, WC_Product $product, BadgeData $data, string $context ): string {
		if (
			// Cart/checkout HTML is a unit price or a line subtotal, never a sale display: leave it.
			in_array( $context, BadgeContext::CART_LIKE, true )
			|| ! $data->isOnSale
			|| null === $data->omnibus
			|| null === $data->regularDisplay
			|| 'variable' === $product->get_type()
			|| ! (bool) $this->settings->get( 'display.omnibus_replace_del', true )
		) {
			return $wcHtml;
		}
		if ( $data->omnibus->amount <= $data->currentDisplay ) {
			// The 30-day low before the sale (e.g. an earlier promotion's price) is not above
			// today's price: there is no reduction to announce, so no struck-through price.
			// The 30-day low itself is still shown in the badge.
			return wc_price( $data->currentDisplay ) . $product->get_price_suffix();
		}
		return wc_format_sale_price( wc_price( $data->omnibus->amount ), wc_price( $data->currentDisplay ) ) . $product->get_price_suffix();
	}
}
