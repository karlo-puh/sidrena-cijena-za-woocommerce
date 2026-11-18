<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\Display;

use SidrenaCijena\Display\BadgeContext;
use SidrenaCijena\Display\BadgeData;
use SidrenaCijena\Display\OmnibusView;
use SidrenaCijena\Display\PriceBadge;
use SidrenaCijena\Display\PriceHtmlComposer;
use SidrenaCijena\Display\ReferenceView;
use SidrenaCijena\Settings\Defaults;
use SidrenaCijena\Settings\Settings;
use SidrenaCijena\Tests\TestCase;

final class PriceHtmlComposerTest extends TestCase {
	private function composer( ?Settings $settings = null ): PriceHtmlComposer {
		$settings = $settings ?? new Settings( Defaults::all() );
		return new PriceHtmlComposer( $settings, new PriceBadge( $settings, dirname( __DIR__, 3 ) . '/templates' ) );
	}

	private function anchor(): ReferenceView {
		return new ReferenceView( 'anchor', 'Cijena na dan', '10. 9. 2026.', '2026-09-10', 14.99, '14,99 €', null, null, false );
	}

	public function test_badge_appended_after_price_by_default(): void {
		$p    = $this->product( [ 'id' => 1 ] );
		$data = new BadgeData( 1, [ $this->anchor() ], null, false, 10.0, null );
		$html = $this->composer()->compose( '<span class="amount">10,00 €</span>', $p, $data, BadgeContext::LOOP );
		self::assertStringStartsWith( '<span class="amount">10,00 €</span> <span class="scwc-badge', $html );
	}

	public function test_badge_before_price_when_configured(): void {
		$p    = $this->product( [ 'id' => 1 ] );
		$data = new BadgeData( 1, [ $this->anchor() ], null, false, 10.0, null );
		$html = $this->composer( ( new Settings( Defaults::all() ) )->with( 'display.position', 'before' ) )->compose( '<span class="amount">10,00 €</span>', $p, $data, BadgeContext::LOOP );
		self::assertStringStartsWith( '<span class="scwc-badge', $html );
		self::assertStringEndsWith( '<span class="amount">10,00 €</span>', $html );
	}

	public function test_del_is_rebuilt_with_lowest_30_when_lower_than_regular(): void {
		$p    = $this->product( [ 'id' => 1, 'regular_price' => '20', 'sale_price' => '10', 'price_suffix' => ' <small>s PDV</small>' ] );
		$data = new BadgeData( 1, [ $this->anchor() ], new OmnibusView( 15.0, '15,00 €', 33, 'history' ), true, 10.0, 20.0 );
		$wc   = '<del aria-hidden="true"><span class="amount">20,00 €</span></del> <ins><span class="amount">10,00 €</span></ins>';
		$html = $this->composer()->compose( $wc, $p, $data, BadgeContext::SINGLE );
		self::assertStringStartsWith( '<del aria-hidden="true"><span class="woocommerce-Price-amount amount">15,00&nbsp;€</span></del> <ins><span class="woocommerce-Price-amount amount">10,00&nbsp;€</span></ins> <small>s PDV</small>', $html );
		self::assertStringContainsString( 'scwc-lowest30', $html );
	}

	private function tooltip(): Settings {
		return ( new Settings( Defaults::all() ) )->with( 'display.omnibus_style', 'tooltip' );
	}

	public function test_tooltip_follows_the_struck_through_low_and_replaces_its_line(): void {
		$p    = $this->product( [ 'id' => 1, 'regular_price' => '24.90', 'sale_price' => '22.41' ] );
		$data = new BadgeData( 1, [ $this->anchor() ], new OmnibusView( 24.90, '<span class="amount">24,90 €</span>', 10, 'history', '24,90 €' ), true, 22.41, 24.90 );
		$wc   = '<del aria-hidden="true"><span class="amount">24,90 €</span></del> <ins><span class="amount">22,41 €</span></ins>';
		$html = $this->composer( $this->tooltip() )->compose( $wc, $p, $data, BadgeContext::LOOP );
		self::assertStringStartsWith( '<span class="scwc-price"><span class="scwc-was"><del aria-hidden="true"><span class="woocommerce-Price-amount amount">24,90&nbsp;€</span></del><span class="screen-reader-text">Izvorna cijena bila je: 24,90&nbsp;€.</span> <span class="scwc-discount">', $html, 'row: struck 30-day low, −X %, (?) – also when the low equals the regular price' );
		self::assertMatchesRegularExpression( '#<span class="scwc-info".*?</span></span></span> <ins aria-hidden="true"><span class="woocommerce-Price-amount amount">22,41&nbsp;€</span></ins><span class="screen-reader-text">Trenutna cijena je: 22,41&nbsp;€.</span></span>#s', $html, 'then the price on its own line' );
		self::assertStringContainsString( 'aria-label="Najniža cijena u 30 dana prije sniženja: 24,90 €"', $html );
		self::assertStringContainsString( 'role="tooltip">Najniža cijena u 30 dana prije sniženja: <strong><span class="amount">24,90 €</span></strong>', $html );
		self::assertStringNotContainsString( 'scwc-lowest30', $html, 'no separate line' );
		self::assertStringContainsString( 'scwc-ref-price--anchor', $html, '"Cijena na dan" stays a line' );
		self::assertStringContainsString( 'scwc-lowest30', $this->composer()->compose( $wc, $p, $data, BadgeContext::LOOP ), 'default stays a line' );
	}

	public function test_tooltip_falls_back_to_the_line_without_a_struck_through_low(): void {
		$p  = $this->product( [ 'id' => 1, 'regular_price' => '100', 'sale_price' => '90' ] );
		$wc = '<del aria-hidden="true"><span class="amount">100,00 €</span></del> <ins><span class="amount">90,00 €</span></ins>';
		// No real reduction against the 30-day low: no struck-through price to explain.
		$html = $this->composer( $this->tooltip() )->compose( $wc, $p, new BadgeData( 1, [], new OmnibusView( 90.0, '90,00 €', null, 'history' ), true, 90.0, 100.0 ), BadgeContext::SINGLE );
		self::assertStringNotContainsString( 'scwc-info', $html );
		self::assertStringContainsString( 'scwc-lowest30', $html );
		// The strikethrough is the regular price (replace setting off): a tooltip would explain the wrong number.
		$off  = $this->tooltip()->with( 'display.omnibus_replace_del', false );
		$html = $this->composer( $off )->compose( $wc, $p, new BadgeData( 1, [], new OmnibusView( 95.0, '95,00 €', 5, 'history' ), true, 90.0, 100.0 ), BadgeContext::SINGLE );
		self::assertStringNotContainsString( 'scwc-info', $html );
		self::assertStringContainsString( 'scwc-lowest30', $html );
		// Cart-like contexts never get it.
		$html = $this->composer( $this->tooltip() )->compose( $wc, $p, new BadgeData( 1, [], new OmnibusView( 95.0, '95,00 €', 5, 'history' ), true, 90.0, 100.0 ), BadgeContext::CART );
		self::assertStringNotContainsString( 'scwc-info', $html );
	}

	public function test_no_strikethrough_when_lowest_30_is_not_above_current_price(): void {
		$p  = $this->product(
			[
				'id'            => 1,
				'regular_price' => '100',
				'sale_price'    => '90',
				'price_suffix'  => ' <small>s PDV</small>',
			]
		);
		$wc = '<del aria-hidden="true"><span class="amount">100,00 €</span></del> <ins><span class="amount">90,00 €</span></ins>';
		// An earlier promotion at 80 (or 90) ended less than 30 days before this one started.
		foreach ( [ 80.0, 90.0 ] as $lowest ) {
			$data = new BadgeData( 1, [], new OmnibusView( $lowest, '', null, 'history' ), true, 90.0, 100.0 );
			$html = $this->composer()->compose( $wc, $p, $data, BadgeContext::SINGLE );
			self::assertStringStartsWith( '<span class="woocommerce-Price-amount amount">90,00&nbsp;€</span> <small>s PDV</small>', $html );
			self::assertStringNotContainsString( '<del', $html );
			self::assertStringContainsString( 'scwc-lowest30', $html, 'the 30-day low is still disclosed' );
		}
		$off = ( new Settings( Defaults::all() ) )->with( 'display.omnibus_replace_del', false );
		self::assertStringStartsWith( $wc, $this->composer( $off )->compose( $wc, $p, $data, BadgeContext::SINGLE ), 'setting off leaves WooCommerce markup alone' );
	}

	public function test_cart_and_checkout_price_html_is_never_rebuilt(): void {
		$p = $this->product(
			[
				'id'            => 1,
				'regular_price' => '20',
				'sale_price'    => '10',
			]
		);
		foreach ( [ BadgeContext::CART, BadgeContext::MINI_CART, BadgeContext::CHECKOUT ] as $context ) {
			foreach ( [ true, false ] as $isReduction ) {
				$data = new BadgeData( 1, [], new OmnibusView( $isReduction ? 15.0 : 8.0, '', null, 'history' ), true, 10.0, 20.0 );
				$html = $this->composer()->compose( '<span class="amount">30,00 €</span>', $p, $data, $context );
				self::assertStringStartsWith( '<span class="amount">30,00 €</span> <span class="scwc-badge', $html, $context );
			}
		}
	}

	public function test_strikethrough_uses_lowest_30_even_when_not_below_regular(): void {
		$p    = $this->product( [ 'id' => 1, 'regular_price' => '48.93', 'sale_price' => '43.94' ] );
		$wc   = '<del>48,93</del> <ins>43,94</ins>';
		$data = new BadgeData( 1, [], new OmnibusView( 63.10, '63,10 €', 30, 'history' ), true, 43.94, 48.93 );
		self::assertStringStartsWith( '<del aria-hidden="true"><span class="woocommerce-Price-amount amount">63,10&nbsp;€</span></del>', $this->composer()->compose( $wc, $p, $data, BadgeContext::SINGLE ), 'strikethrough and percentage share the 30-day low' );
		$off = ( new Settings( Defaults::all() ) )->with( 'display.omnibus_replace_del', false );
		self::assertStringStartsWith( $wc, $this->composer( $off )->compose( $wc, $p, $data, BadgeContext::SINGLE ) );
	}

	public function test_del_never_rebuilt_for_variable_parents(): void {
		$p    = $this->product( [ 'id' => 1, 'type' => 'variable' ] );
		$data = new BadgeData( 1, [ $this->anchor() ], new OmnibusView( 15.0, '15,00 €', 33, 'history' ), true, 10.0, 20.0 );
		self::assertStringStartsWith( '<span class="price">range</span>', $this->composer()->compose( '<span class="price">range</span>', $p, $data, BadgeContext::LOOP ) );
	}
}
