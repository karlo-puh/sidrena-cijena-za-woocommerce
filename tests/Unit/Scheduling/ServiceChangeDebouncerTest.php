<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\Scheduling;

use Brain\Monkey\Actions;
use SidrenaCijena\Scheduling\ActionSchedulerBackend;
use SidrenaCijena\Scheduling\Scheduler;
use SidrenaCijena\Scheduling\ServiceChangeDebouncer;
use SidrenaCijena\Settings\Defaults;
use SidrenaCijena\Settings\Settings;
use SidrenaCijena\Tests\Support\FixedClock;
use SidrenaCijena\Tests\TestCase;

final class ServiceChangeDebouncerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		scwc_test_schedule_reset();
	}

	private function debouncer( string $mode = 'all', bool $enabled = true ): ServiceChangeDebouncer {
		$settings  = ( new Settings( Defaults::all() ) )->with( 'price_list.regenerate_on_change', $mode )->with( 'price_list.enabled', $enabled );
		$scheduler = new Scheduler( new ActionSchedulerBackend(), $settings, new FixedClock( '2026-10-01 10:00:00' ) );
		return new ServiceChangeDebouncer( $scheduler, $settings );
	}

	private function generated(): array {
		return array_values( array_filter( $GLOBALS['scwc_test_schedule']['single'], fn( $s ) => Scheduler::HOOK_GENERATE === $s['hook'] ) );
	}

	public function test_registers_only_bulk_listeners(): void {
		$debouncer = $this->debouncer();
		foreach ( [ 'woocommerce_delete_product', 'woocommerce_delete_product_variation', 'update_option_scwc_settings', 'scwc_snapshot_completed', 'scwc_import_completed' ] as $hook ) {
			Actions\expectAdded( $hook )->once()->with( [ $debouncer, 'touch' ] );
		}
		Actions\expectAdded( 'scwc_price_changed' )->never();
		Actions\expectAdded( 'scwc_first_offer_captured' )->never();
		$debouncer->register();
	}

	public function test_touch_schedules_one_debounced_bulk_build(): void {
		$this->debouncer()->touch();
		$this->debouncer()->touch();
		$g = $this->generated();
		self::assertCount( 1, $g, 'repeated touches collapse into one pending run' );
		self::assertSame( [ 'reason' => 'bulk' ], $g[0]['args'] );
		self::assertSame( strtotime( '2026-10-01 10:10:00 UTC' ), $g[0]['timestamp'], 'default debounce 600 s' );
	}

	public function test_never_mode_and_disabled_price_list_do_nothing(): void {
		$this->debouncer( 'never' )->touch();
		$this->debouncer( 'all', false )->touch();
		self::assertCount( 0, $this->generated() );
	}
}
