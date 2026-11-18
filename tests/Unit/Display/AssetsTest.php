<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\Display;

use SidrenaCijena\Display\Assets;
use SidrenaCijena\Settings\Defaults;
use SidrenaCijena\Settings\Settings;
use SidrenaCijena\Tests\TestCase;

final class AssetsTest extends TestCase {
	/** @return string[] Enqueued style handles. */
	private function enqueued( Settings $settings ): array {
		$GLOBALS['scwc_test_styles'] = [];
		( new Assets( $settings ) )->enqueue();
		return array_column( $GLOBALS['scwc_test_styles'], 0 );
	}

	public function test_tooltip_css_loads_with_the_tooltip_even_without_plugin_css(): void {
		$settings = new Settings( Defaults::all() );
		self::assertSame( [ 'scwc-frontend' ], $this->enqueued( $settings ) );
		self::assertSame( [ 'scwc-frontend', 'scwc-tooltip' ], $this->enqueued( $settings->with( 'display.omnibus_style', 'tooltip' ) ) );
		self::assertSame( [ 'scwc-tooltip' ], $this->enqueued( $settings->with( 'display.omnibus_style', 'tooltip' )->with( 'display.load_css', false ) ) );
		self::assertSame( [], $this->enqueued( $settings->with( 'display.load_css', false ) ) );
	}

	public function test_tooltip_script_loads_only_with_the_tooltip(): void {
		$GLOBALS['scwc_test_scripts'] = [];
		( new Assets( new Settings( Defaults::all() ) ) )->enqueue();
		self::assertSame( [], $GLOBALS['scwc_test_scripts'] );
		( new Assets( ( new Settings( Defaults::all() ) )->with( 'display.omnibus_style', 'tooltip' ) ) )->enqueue();
		self::assertSame( 'scwc-tooltip', $GLOBALS['scwc_test_scripts'][0][0] );
		self::assertStringEndsWith( 'assets/js/tooltip.js', $GLOBALS['scwc_test_scripts'][0][1] );
	}
}
