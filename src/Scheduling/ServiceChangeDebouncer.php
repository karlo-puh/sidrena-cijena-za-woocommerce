<?php
/**
 * Queues a full price-list build shortly after bulk changes (snapshot, import, permanently deleted product, saved
 * settings) – debounced and unique. Single product changes go through ChangeTracker instead.
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\Scheduling;

use SidrenaCijena\Settings\Settings;

final class ServiceChangeDebouncer {

	public function __construct(
		private readonly Scheduler $scheduler,
		private readonly Settings $settings,
	) {}

	public function register(): void {
		add_action( 'woocommerce_delete_product', [ $this, 'touch' ] );
		add_action( 'woocommerce_delete_product_variation', [ $this, 'touch' ] );
		add_action( 'update_option_' . Settings::OPTION, [ $this, 'touch' ] ); // E.g. the OIB entered after upgrading.
		add_action( 'scwc_snapshot_completed', [ $this, 'touch' ] );
		add_action( 'scwc_import_completed', [ $this, 'touch' ] );
	}

	public function touch(): void {
		if ( ! (bool) $this->settings->get( 'price_list.enabled', true ) || 'never' === $this->settings->get( 'price_list.regenerate_on_change', 'all' ) ) {
			return;
		}
		$this->scheduler->scheduleGenerationSoon( (int) $this->settings->get( 'price_list.debounce_seconds', 300 ), 'bulk' );
	}
}
