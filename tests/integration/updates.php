<?php
/** Run only on a disposable WordPress install with: wp eval-file tests/integration/updates.php --user=test */

use SidrenaCijena\Updates\GitHubUpdater;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This integration test requires WP-CLI and a disposable database.' );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	WP_CLI::log( 'PASS ' . $message );
};
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$basename = plugin_basename( SCWC_PLUGIN_FILE );
$package  = 'https://github.com/karlo-puh/sidrena-cijena-za-woocommerce/releases/download/v99.0.0/plugin.zip';
$requests = 0;
$mockHttp = static function ( $response, $args, $url ) use ( &$requests, $package ) {
	if ( str_contains( $url, 'api.github.com/repos/karlo-puh/sidrena-cijena-za-woocommerce/releases/latest' ) ) {
		++$requests;
		return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [
			'tag_name' => 'v99.0.0',
			'assets'   => [ [ 'name' => 'plugin.zip', 'browser_download_url' => $package ] ],
		] ) ];
	}
	if ( str_contains( $url, 'api.wordpress.org/plugins/update-check/' ) ) {
		return [ 'response' => [ 'code' => 200 ], 'body' => '{"plugins":{},"translations":[],"no_update":{}}' ];
	}
	return $response;
};
add_filter( 'pre_http_request', $mockHttp, 10, 3 );
// The test targets the real core plugin-update callback, not theme or core releases.
remove_action( 'load-update-core.php', 'wp_update_themes' );
remove_action( 'load-update-core.php', 'wp_version_check' );
$assert( 10 === has_action( 'load-update-core.php', 'wp_update_plugins' ), 'Core plugin-update callback is registered at priority 10.' );

foreach ( [ [ 'version' => SCWC_VERSION ], [] ] as $cached ) {
	$checked = [];
	foreach ( get_plugins() as $file => $plugin ) {
		$checked[ $file ] = $plugin['Version'];
	}
	set_site_transient( 'update_plugins', (object) [ 'last_checked' => time(), 'checked' => $checked, 'response' => [] ] );
	set_site_transient( GitHubUpdater::TRANSIENT, $cached, HOUR_IN_SECONDS );
	$before = $requests;
	unset( $_GET['force-check'] );
	do_action( 'load-update-core.php' );
	$assert( $before === $requests, 'Normal Updates page visit preserves the release cache.' );

	$_GET['force-check'] = '1';
	do_action( 'load-update-core.php' );
	$update = get_site_transient( 'update_plugins' )->response[ $basename ] ?? null;
	$assert( null !== $update && '99.0.0' === $update->new_version && $package === $update->package, 'Check again discovers the new ZIP through the native WordPress update check.' );
	$assert( $before + 1 === $requests, 'Each manual check makes one GitHub lookup, including after a cached failure.' );
}

unset( $_GET['force-check'] );
remove_filter( 'pre_http_request', $mockHttp, 10 );
delete_site_transient( GitHubUpdater::TRANSIENT );
delete_site_transient( 'update_plugins' );
