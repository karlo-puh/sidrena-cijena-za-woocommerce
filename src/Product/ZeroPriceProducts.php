<?php
/**
 * Published products with a regular price of 0 € (the product itself or one of its variations).
 *
 * 0 € is a valid price – and anchor – for a free product (e.g. a PDF), but it is also what a forgotten price
 * looks like. A snapshot records it as a 0 € anchor either way, so the admin gets a count and a filtered
 * product list to check them first. Independent of anchors: the list does not change with anchors or "na".
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\Product;

final class ZeroPriceProducts {

	public const QUERY_VAR = 'scwc_price';
	public const QUERY_VAL = 'zero';
	public const TRANSIENT = 'scwc_zero_price_ids';

	/**
	 * @param \wpdb|object $wpdb Database.
	 */
	public function __construct( private readonly object $wpdb ) {}

	public function register(): void {
		add_action( 'pre_get_posts', [ $this, 'filterList' ] );
		add_action( 'restrict_manage_posts', [ $this, 'keepFilter' ] );
	}

	/**
	 * Product IDs (variations reported as their parent) with a regular price of 0 €.
	 *
	 * @return int[]
	 */
	public function ids(): array {
		$posts = $this->wpdb->posts;
		$meta  = $this->wpdb->postmeta;
		$tr    = $this->wpdb->prefix . 'term_relationships';
		$tt    = $this->wpdb->prefix . 'term_taxonomy';
		$terms = $this->wpdb->prefix . 'terms';
		$sql   = $this->wpdb->prepare(
			"SELECT DISTINCT IF(p.post_type = 'product_variation', p.post_parent, p.ID) FROM {$posts} p
INNER JOIN {$meta} rp ON rp.post_id = p.ID AND rp.meta_key = %s AND rp.meta_value <> ''
LEFT JOIN {$posts} parent ON parent.ID = p.post_parent AND p.post_type = 'product_variation'
WHERE p.post_type IN ('product','product_variation') AND p.post_status = 'publish'
AND CAST(rp.meta_value AS DECIMAL(20,6)) = 0
AND ( p.post_type = 'product' OR parent.post_status = 'publish' )
AND NOT EXISTS (
  SELECT 1 FROM {$tr} tr
  INNER JOIN {$tt} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
  INNER JOIN {$terms} t ON t.term_id = tt.term_id
  WHERE tr.object_id = p.ID AND t.name IN ('variable','grouped')
)",
			'_regular_price'
		);
		return array_values( array_map( 'intval', (array) $this->wpdb->get_col( $sql ) ) );
	}

	/**
	 * @return int[]
	 */
	public function cachedIds( int $ttl = 12 * HOUR_IN_SECONDS ): array {
		$value = get_transient( self::TRANSIENT );
		if ( is_array( $value ) ) {
			return array_map( 'intval', $value );
		}
		$ids = $this->ids();
		set_transient( self::TRANSIENT, $ids, $ttl );
		return $ids;
	}

	public function count(): int {
		return count( $this->cachedIds() );
	}

	public function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Whether a product save can change the 0 € list: the product is listed, or now has a regular price of 0 €.
	 * (Not every save, so a big catalogue is not recounted after each edit.)
	 *
	 * @param int                    $id      Product or variation ID.
	 * @param \WC_Product|mixed|null $product Saved product.
	 */
	public function affectedBySave( int $id, $product = null ): bool {
		$cached = get_transient( self::TRANSIENT );
		if ( ! is_array( $cached ) ) {
			return false; // Nothing cached yet.
		}
		$listed = $product instanceof \WC_Product && $product->get_parent_id() ? $product->get_parent_id() : $id;
		$price  = $product instanceof \WC_Product ? (string) $product->get_regular_price( 'edit' ) : '';
		return in_array( $listed, array_map( 'intval', $cached ), true ) || ( is_numeric( $price ) && 0.0 === (float) $price );
	}

	public static function listUrl(): string {
		return admin_url( 'edit.php?post_type=product&' . self::QUERY_VAR . '=' . self::QUERY_VAL );
	}

	/**
	 * Products list (wp-admin → Proizvodi) narrowed to the 0 € products when the link is followed.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function filterList( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'product' !== $query->get( 'post_type' ) || ! $this->requested() ) {
			return;
		}
		$ids   = $this->ids(); // Fresh: this list is where prices get fixed.
		$found = $query->get( 'post__in' ); // WooCommerce's product search sets the matches here ('' when no search).
		if ( is_array( $found ) && [] !== $found ) {
			$ids = array_values( array_intersect( $ids, array_map( 'intval', $found ) ) );
		}
		$query->set( 'post__in', [] !== $ids ? $ids : [ 0 ] );
	}

	/** The products search submits the same form: keep the 0 € filter for it. */
	public function keepFilter( string $postType = '' ): void {
		if ( 'product' === $postType && $this->requested() ) {
			echo '<input type="hidden" name="' . esc_attr( self::QUERY_VAR ) . '" value="' . esc_attr( self::QUERY_VAL ) . '">';
		}
	}

	private function requested(): bool {
		return isset( $_GET[ self::QUERY_VAR ] ) && self::QUERY_VAL === $_GET[ self::QUERY_VAR ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
	}
}
