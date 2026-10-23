<?php
/** Disposable WordPress/WooCommerce/Redis checks; see bin/test-integration-docker.sh. */

use SidrenaCijena\Plugin;
use SidrenaCijena\Admin\Tools\ToolsAjax;
use SidrenaCijena\PriceList\Generator;
use SidrenaCijena\PriceList\Manifest;
use SidrenaCijena\PriceList\Storage;
use SidrenaCijena\Scheduling\Scheduler;
use SidrenaCijena\Settings\Settings;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'http://scwc-test.invalid' !== home_url() ) {
	exit( 'Run only in the disposable scwc-test environment.' );
}
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
	WP_CLI::log( 'PASS ' . $message );
};
$phase = $args[0] ?? '';
if ( 'configure' === $phase ) {
	$settings = Settings::fromOption()->with( 'outlet.address', 'Test 1' )->with( 'outlet.label', 'WEB' )
		->with( 'price_list.regenerate_on_change', 'never' )
		->with( 'outlets.additional', [ [ 'address' => 'Test 2', 'label' => 'SHOP' ] ] );
	update_option( Settings::OPTION, $settings->all() );
	Plugin::instance()->get( \SidrenaCijena\Scheduling\SchedulerBackend::class )->unscheduleAll( Scheduler::HOOK_GENERATE );
	$assert( false === as_next_scheduled_action( Scheduler::HOOK_GENERATE, [ 'reason' => 'change' ], 'scwc' ), 'Cancelling exports removes old price-change jobs as well as daily jobs.' );
	return;
}
if ( 'setup' === $phase ) {
	$assert( 'never' === Plugin::instance()->get( Settings::class )->get( 'price_list.regenerate_on_change' ), 'A fresh request loads the saved daily-only setting.' );
	$parent = new WC_Product_Variable();
	$parent->set_name( 'Large variable product' );
	$parent->set_status( 'publish' );
	$parent->save();
	for ( $i = 0; $i < 120; ++$i ) {
		$p = new WC_Product_Variation();
		$p->set_parent_id( $parent->get_id() );
		$p->set_status( 'publish' );
		$p->set_sku( 'batch-' . $i );
		$p->set_regular_price( '10.50' );
		$p->save();
	}
	foreach ( [ false, true, true ] as $virtual ) {
		$p = new WC_Product_Simple();
		$p->set_name( $virtual ? 'Usluga ć & <test>' : 'Proizvod ž "test"' );
		$p->set_status( 'publish' );
		$p->set_regular_price( '23.45' );
		$p->set_virtual( $virtual );
		$p->save();
	}
	$expected = [];
	foreach ( get_posts( [ 'post_type' => [ 'product', 'product_variation' ], 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ] ) as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p || $p->is_type( 'variable' ) || '' === $p->get_price() ) {
			continue;
		}
		if ( ! $p->is_virtual() ) {
			$expected[] = $p->get_sku() ?: (string) $id;
		}
	}
	sort( $expected );
	update_option( 'scwc_test_expected_skus', $expected );
	// Exercise the real query boundary, including WordPress filters used by language plugins.
	$pager = \SidrenaCijena\PriceList\Collector::wcPager();
	$first = $pager( 0, 3 );
	$next = $pager( max( $first ), 3 );
	$assert( 3 === count( $first ) && 3 === count( $next ) && min( $next ) > max( $first ), 'Native keyset query advances without overlap.' );
	$excluded = $first[0];
	$filter = static function ( string $where ) use ( $excluded ): string {
		global $wpdb;
		return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID <> %d", $excluded );
	};
	add_filter( 'posts_where', $filter );
	$assert( ! in_array( $excluded, $pager( 0, 3 ), true ), 'Native query filters remain effective during export.' );
	remove_filter( 'posts_where', $filter );
	$assert( $first === $pager( 0, 3 ), 'The cursor filter is removed after each query.' );
	// A real Redis-backed preview/apply round trip, with mixed-case tokens forced deterministically.
	$assert( wp_using_ext_object_cache(), 'Persistent Redis object cache is active.' );
	add_filter( 'random_password', static fn() => 'AbC123xYz789' );
	$ajax = Plugin::instance()->get( ToolsAjax::class );
	$preview = new ReflectionMethod( $ajax, 'importPreview' );
	$preview->setAccessible( true );
	$data = $preview->invoke( $ajax, [ 'csv' => "sku;cijena;datum\nbatch-0;17.50;2026-09-01\n" ] );
	$assert( 'AbC123xYz789' === $data['token'] && false !== get_transient( 'scwc_import_AbC123xYz789' ), 'Mixed-case preview is stored in Redis.' );
	update_option( 'scwc_test_import_token', $data['token'] );
	$assert( false === as_next_scheduled_action( Scheduler::HOOK_GENERATE, [ 'reason' => 'change' ], 'scwc' ), 'Creating the fixture in daily-only mode queues no change export.' );
	Plugin::instance()->get( \SidrenaCijena\Scheduling\SchedulerBackend::class )->unscheduleAll( Scheduler::HOOK_GENERATE );
	as_schedule_single_action( time(), Scheduler::HOOK_GENERATE, [ 'reason' => 'integration' ], 'scwc' );
	WP_CLI::success( 'Fixture ready: ' . count( $expected ) . ' products, 2 services, 2 outlets.' );
	return;
}
if ( 'step' === $phase ) {
	$summary = get_option( Generator::OPTION_LAST );
	if ( $summary && ! empty( $summary['files'] ) && empty( $summary['error'] ) ) {
		WP_CLI::log( 'EXPORT_COMPLETE' );
	} else {
		$state = Storage::fromUploads()->tmpPath( 'generation.json' );
		WP_CLI::log( is_file( $state ) ? 'Checkpoint: ' . file_get_contents( $state ) : 'Waiting for first action.' );
	}
	return;
}
if ( 'verify' !== $phase ) {
	throw new RuntimeException( 'Unknown test phase.' );
}
$storage = Storage::fromUploads();
$manifest = new Manifest( $storage );
$assert( ! Plugin::instance()->get( Generator::class )->pending(), 'Generation completed across fresh PHP processes.' );
$files = $manifest->entries();
$assert( 4 === count( $files ), 'Both formats are published for both outlets.' );
$expected = get_option( 'scwc_test_expected_skus' );
foreach ( $files as $file ) {
	$path = $storage->path( $file['name'] );
	$assert( hash_file( 'sha256', $path ) === $file['sha256'], 'Published checksum matches ' . $file['format'] . '/' . $file['outlet'] );
	$skus = [];
	$services = 0;
	if ( 'xml' === $file['format'] ) {
		$xml = simplexml_load_file( $path );
		$assert( false !== $xml, 'XML is well formed.' );
		foreach ( $xml->Proizvodi->Proizvod as $row ) {
			$skus[] = (string) $row->Sifra;
		}
		$services = count( $xml->Usluge->Usluga );
	} else {
		$fh = fopen( $path, 'rb' );
		fgetcsv( $fh, 0, ';' );
		while ( false !== ( $row = fgetcsv( $fh, 0, ';' ) ) ) {
			if ( 'usluga' === $row[0] ) { ++$services; } else { $skus[] = $row[2]; }
		}
		fclose( $fh );
	}
	sort( $skus );
	$assert( $expected === $skus && 2 === $services, 'Exact product IDs and services: no skipped or duplicate rows.' );
}
$assert( [] === as_get_scheduled_actions( [ 'hook' => Scheduler::HOOK_GENERATE_BATCH, 'status' => 'pending' ], 'ids' ), 'No continuation remains after publication.' );
$assert( [] === as_get_scheduled_actions( [ 'group' => 'scwc', 'status' => 'failed' ], 'ids' ), 'Actual Action Scheduler reports no failed plugin jobs.' );
$ajax = Plugin::instance()->get( ToolsAjax::class );
$apply = new ReflectionMethod( $ajax, 'importApply' );
$apply->setAccessible( true );
$result = $apply->invoke( $ajax, [ 'token' => get_option( 'scwc_test_import_token' ) ] );
$assert( 1 === $result['updated'], 'A later PHP process applies the mixed-case Redis token.' );
$assert( '17.5' === get_post_meta( wc_get_product_id_by_sku( 'batch-0' ), '_scwc_ref_anchor_price', true ), 'Imported reference price is persisted.' );
$assert( false === get_transient( 'scwc_import_AbC123xYz789' ), 'Consumed preview is removed from Redis.' );
// Daily-only mode must survive real imports and price updates, with history hooks still enabled.
$product = wc_get_product( wc_get_product_id_by_sku( 'batch-0' ) );
$product->set_regular_price( '11.50' );
$product->save();
$assert( false === as_next_scheduled_action( Scheduler::HOOK_GENERATE, [ 'reason' => 'change' ], 'scwc' ), 'Imports and product changes do not queue another daily-only export.' );
$daily = as_get_scheduled_actions( [ 'hook' => Scheduler::HOOK_GENERATE, 'status' => 'pending', 'group' => 'scwc' ] );
$assert( 1 === count( $daily ), 'Exactly one future daily export remains scheduled.' );
$action = reset( $daily );
$assert( [] === $action->get_args() && '06:00' === $action->get_schedule()->get_date()->setTimezone( wp_timezone() )->format( 'H:i' ), 'The daily export remains at 06:00 in the shop timezone.' );
$assert( '17.5' === get_post_meta( $product->get_id(), '_scwc_ref_anchor_price', true ), 'Later current-price changes preserve the historical anchor.' );
WP_CLI::success( 'Price-list integration checks passed.' );
