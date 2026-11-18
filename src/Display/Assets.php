<?php
/**
 * Front-end assets.
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\Display;

use SidrenaCijena\Settings\Settings;

final class Assets {

	public function __construct( private readonly Settings $settings ) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		if ( (bool) $this->settings->get( 'display.load_css', true ) ) {
			wp_enqueue_style( 'scwc-frontend', SCWC_PLUGIN_URL . 'assets/css/frontend.css', [], SCWC_VERSION );
		}
		if ( 'tooltip' === $this->settings->get( 'display.omnibus_style', 'line' ) ) {
			// Needed even with the plugin CSS off: it keeps the tooltip text hidden until hover/tap.
			wp_enqueue_style( 'scwc-tooltip', SCWC_PLUGIN_URL . 'assets/css/tooltip.css', [], SCWC_VERSION );
			wp_enqueue_script(
				'scwc-tooltip',
				SCWC_PLUGIN_URL . 'assets/js/tooltip.js',
				[],
				SCWC_VERSION,
				[
					'in_footer' => true,
					'strategy'  => 'defer',
				]
			);
		}
	}
}
