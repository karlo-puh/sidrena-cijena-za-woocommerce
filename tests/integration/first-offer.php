<?php
/** Run only on a disposable WordPress + WooCommerce install with: wp eval-file tests/integration/first-offer.php */

use SidrenaCijena\Admin\ProductSave;
use SidrenaCijena\Plugin;
use SidrenaCijena\Reference\FirstOfferCapture;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This integration test requires WP-CLI and a disposable database.' );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	WP_CLI::log( 'PASS ' . $message );
};
$anchor = static fn( int $id ): string => (string) get_post_meta( $id, '_scwc_ref_anchor_price', true );
$date   = static fn( int $id ): string => (string) get_post_meta( $id, '_scwc_ref_anchor_date', true );
$today  = wp_date( 'Y-m-d' );
// Keep the test independent of the calendar on which CI executes it.
add_filter( 'scwc_reference_date', static fn() => new DateTimeImmutable( '2020-01-01' ) );

$product = new WC_Product_Simple();
$product->set_name( 'First offer integration test' );
$product->set_status( 'draft' );
$product->set_regular_price( '10' );
$id = $product->save();
$assert( '' === $anchor( $id ), 'Draft price is not captured.' );
$product->set_regular_price( '25' );
$product->set_sale_price( '20' );
$product->set_status( 'publish' );
$product->save();
$assert( '25' === $anchor( $id ) && $today === $date( $id ), 'CRUD publication stores first regular price and local date.' );
$assert( '25' === $product->get_meta( '_scwc_ref_anchor_price' ), 'Saved WC object sees the new reference immediately.' );
$product->set_regular_price( '40' );
$product->save();
$assert( '25' === $anchor( $id ), 'Later edits preserve the first price.' );

// Exercise the actual classic-editor pricing handler after WordPress changes the post status.
require_once WC_ABSPATH . 'includes/admin/meta-boxes/class-wc-meta-box-product-data.php';
Plugin::instance()->get( ProductSave::class )->register(); // WP-CLI is not an admin request.
$adminId = wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'auto-draft', 'post_title' => 'Admin product' ] );
$saveAdmin = static function ( $postId, $post ) use ( $adminId ): void {
	if ( (int) $postId === $adminId ) {
		WC_Meta_Box_Product_Data::save( $postId, $post );
	}
};
$_POST = [ 'product-type' => 'simple', '_regular_price' => '42', '_sale_price' => '', 'scwc_general_fields' => '1' ];
add_action( 'save_post_product', $saveAdmin, 10, 2 );
wp_update_post( [ 'ID' => $adminId, 'post_status' => 'publish' ] );
remove_action( 'save_post_product', $saveAdmin, 10 );
$_POST = [];
$assert( '42' === $anchor( $adminId ) && $today === $date( $adminId ), 'Classic editor captures the final saved price after publication.' );
$html = do_shortcode( '[sidrena_cijena id="' . $adminId . '" key="anchor"]' );
$assert( str_contains( $html, '42' ) && str_contains( $html, 'scwc-badge' ), 'Stored reference renders through the existing shortcode.' );

// The built-in CSV importer must exercise its real mapping and CRUD save path.
require_once WC_ABSPATH . 'includes/import/abstract-wc-product-importer.php';
require_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
$temporary = tempnam( sys_get_temp_dir(), 'scwc-import-' );
$csv = $temporary . '.csv';
rename( $temporary, $csv );
file_put_contents( $csv, "Type,SKU,Name,Published,Regular price\nsimple,scwc-import-test,Imported book,1,36\n" );
$params = [ 'parse' => true, 'mapping' => [ 'Type' => 'type', 'SKU' => 'sku', 'Name' => 'name', 'Published' => 'published', 'Regular price' => 'regular_price' ] ];
$result = ( new WC_Product_CSV_Importer( $csv, $params ) )->import();
$importId = wc_get_product_id_by_sku( 'scwc-import-test' );
$assert( empty( $result['failed'] ) && $importId > 0 && '36' === $anchor( $importId ), 'Native WooCommerce CSV import captures the initial price.' );
file_put_contents( $csv, "Type,SKU,Name,Published,Regular price\nsimple,scwc-import-test,Imported book,1,50\n" );
$params['update_existing'] = true;
$result = ( new WC_Product_CSV_Importer( $csv, $params ) )->import();
unlink( $csv );
$assert( empty( $result['failed'] ) && '36' === $anchor( $importId ), 'CSV updates do not change the first reference.' );

$parent = new WC_Product_Variable();
$parent->set_name( 'Variable book' );
$parent->set_status( 'draft' );
$parent->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id( $parent->get_id() );
$variation->set_status( 'publish' );
$variation->set_regular_price( '65' );
$variation->save();
$assert( '' === $anchor( $variation->get_id() ), 'Variation waits for its parent to be published.' );
$parent = wc_get_product( $parent->get_id() );
$parent->set_status( 'publish' );
$parent->save();
$assert( '65' === $anchor( $variation->get_id() ), 'Publishing the variable parent captures child reference.' );
$another = new WC_Product_Variation();
$another->set_parent_id( $parent->get_id() );
$another->set_regular_price( '75' );
$another->set_status( 'publish' );
$another->save();
$assert( '75' === $anchor( $another->get_id() ), 'New variation on a published product captures its own reference.' );

$scheduled = new WC_Product_Simple();
$scheduled->set_name( 'Scheduled product' );
$scheduled->set_status( 'future' );
$scheduled->set_date_created( time() + DAY_IN_SECONDS );
$scheduled->set_regular_price( '55' );
$scheduled->save();
wp_publish_post( $scheduled->get_id() );
Plugin::instance()->get( FirstOfferCapture::class )->flush();
$assert( '55' === $anchor( $scheduled->get_id() ) && $today === $date( $scheduled->get_id() ), 'WordPress scheduled publication stores the actual publication date.' );

// Simulate a pre-existing product: no observation marker, even if the post is now published.
$legacy = new WC_Product_Simple();
$legacy->set_status( 'draft' );
$legacy->set_regular_price( '80' );
$legacy->save();
delete_post_meta( $legacy->get_id(), FirstOfferCapture::PENDING_KEY );
$legacy->set_status( 'publish' );
$legacy->save();
$assert( '' === $anchor( $legacy->get_id() ), 'Existing missing references are not fabricated from current prices.' );

$manual = new WC_Product_Simple();
$manual->set_status( 'publish' );
$manual->set_regular_price( '90' );
$manual->update_meta_data( '_scwc_ref_anchor_price', '70' );
$manual->update_meta_data( '_scwc_ref_anchor_date', '2020-01-01' );
$manual->save();
$assert( '70' === $anchor( $manual->get_id() ) && '2020-01-01' === $date( $manual->get_id() ), 'Supplied reference and date are preserved.' );
WP_CLI::success( 'First-offer integration checks passed on WordPress ' . get_bloginfo( 'version' ) . ' / WooCommerce ' . WC_VERSION );
