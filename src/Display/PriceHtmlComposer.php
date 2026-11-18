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
		$html = $this->maybeRebuildSaleHtml( $wcHtml, $product, $data, $context );
		if ( null !== $data->omnibus && $this->strikesThroughLow( $product, $data, $context ) && 'tooltip' === $this->settings->get( 'display.omnibus_style', 'line' ) ) {
			// The struck-through price is the 30-day low: explain it with a "(?)" next to it instead of a line.
			$tip = $this->badge->renderTooltip( $data, $context );
			if ( '' !== $tip ) {
				$html = $this->stackedSaleHtml( $product, $data->omnibus->amount, $data->currentDisplay, $tip );
				$data = $data->withoutOmnibus();
			}
		}
		$badge = $this->badge->render( $data, $context );
		if ( '' === $badge ) {
			return $html;
		}
		return 'before' === $this->settings->get( 'display.position', 'after' )
			? $badge . ' ' . $html
			: $html . ' ' . $badge;
	}

	/** Whether the sale display is rebuilt from the 30-day low (see maybeRebuildSaleHtml). */
	private function rebuilds( WC_Product $product, BadgeData $data, string $context ): bool {
		return ! (
			// Cart/checkout HTML is a unit price or a line subtotal, never a sale display: leave it.
			in_array( $context, BadgeContext::CART_LIKE, true )
			|| ! $data->isOnSale
			|| null === $data->omnibus
			|| null === $data->regularDisplay
			|| 'variable' === $product->get_type()
			|| ! (bool) $this->settings->get( 'display.omnibus_replace_del', true )
		);
	}

	/** The struck-through price shown is the 30-day low (a real reduction against it). */
	private function strikesThroughLow( WC_Product $product, BadgeData $data, string $context ): bool {
		return $this->rebuilds( $product, $data, $context ) && null !== $data->omnibus && $data->omnibus->amount > $data->currentDisplay;
	}

	private function maybeRebuildSaleHtml( string $wcHtml, WC_Product $product, BadgeData $data, string $context ): string {
		if ( ! $this->rebuilds( $product, $data, $context ) || null === $data->omnibus ) {
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

	/**
	 * Tooltip mode's own sale markup: a row "~~30-day low~~ −X % (?)" above the price. Built here rather than by
	 * wc_format_sale_price(), whose theme wrappers (e.g. a reversed flex row) would split or reorder the row.
	 * Screen readers get the same texts as WooCommerce's sale price; the struck and current amounts are hidden.
	 */
	private function stackedSaleHtml( WC_Product $product, float $was, float $now, string $tip ): string {
		$wasHtml = wc_price( $was );
		$nowHtml = wc_price( $now );
		return '<span class="scwc-price"><span class="scwc-was"><del aria-hidden="true">' . $wasHtml . '</del>'
			/* translators: %s: struck-through price (30-day low) */
			. '<span class="screen-reader-text">' . sprintf( __( 'Izvorna cijena bila je: %s.', 'sidrena-cijena-za-woocommerce' ), wp_strip_all_tags( $wasHtml ) ) . '</span> '
			. $tip . '</span> <ins aria-hidden="true">' . $nowHtml . '</ins>'
			/* translators: %s: current price */
			. '<span class="screen-reader-text">' . sprintf( __( 'Trenutna cijena je: %s.', 'sidrena-cijena-za-woocommerce' ), wp_strip_all_tags( $nowHtml ) ) . '</span></span>'
			. $product->get_price_suffix();
	}
}
