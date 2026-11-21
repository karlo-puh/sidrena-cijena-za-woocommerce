<?php
/**
 * Remembers which products changed since the last price-list run (a postmeta mark with the time of the change),
 * so that a run between the daily full builds only rebuilds those rows.
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\PriceList;

use SidrenaCijena\Product\ProductSnapshot;

class ChangeTracker {

	public const META = '_scwc_pl_changed';

	/** @var callable(bool):void */
	private $schedule;
	/** @var array<int,true> Products inside wc_update_product_stock() (orders, stock syncs). */
	private array $stockUpdate = [];

	/**
	 * @param \wpdb|object    $wpdb        Database.
	 * @param callable(bool):void $schedule Queues a price-list change run: debounced, or right away (true).
	 * @param bool            $stockMatters Out-of-stock products are left out of the list, so stock changes matter.
	 */
	public function __construct( private readonly object $wpdb, callable $schedule, private readonly bool $stockMatters = false ) {
		$this->schedule = $schedule;
	}

	public function register(): void {
		// Saves through WooCommerce (admin, REST, imports, ERP plugins). The save inside wc_update_product_stock()
		// (every order) does not change the list – availability is not a column – unless out-of-stock products are
		// left out of it.
		if ( ! $this->stockMatters ) {
			foreach ( [ 'woocommerce_product_before_set_stock', 'woocommerce_variation_before_set_stock' ] as $hook ) {
				add_action( $hook, [ $this, 'onStockStart' ] );
			}
			foreach ( [ 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ] as $hook ) {
				add_action( $hook, [ $this, 'onStockEnd' ] );
			}
		}
		add_action( 'woocommerce_new_product', [ $this, 'onSaved' ], 20 );
		add_action( 'woocommerce_update_product', [ $this, 'onSaved' ], 20 );
		add_action( 'woocommerce_new_product_variation', [ $this, 'onSaved' ], 20 );
		add_action( 'woocommerce_update_product_variation', [ $this, 'onSaved' ], 20 );
		add_action( 'transition_post_status', [ $this, 'onStatus' ], 20, 3 );
		// Price changes found by the nightly sweep (made outside WooCommerce).
		add_action( 'scwc_price_changed', [ $this, 'onPriceChanged' ] );
		// A new product's base price (first offer) is written after its save.
		add_action( 'scwc_first_offer_captured', [ $this, 'onSaved' ] );
	}

	/** @param mixed $id Product or variation ID. */
	public function onSaved( $id ): void {
		if ( ! isset( $this->stockUpdate[ (int) $id ] ) ) {
			$this->mark( (int) $id );
		}
	}

	/** @param mixed $product Product whose stock is being updated. */
	public function onStockStart( $product ): void {
		if ( $product instanceof \WC_Product ) {
			$this->stockUpdate[ $product->get_id() ] = true;
		}
	}

	/** @param mixed $product Product whose stock was updated. */
	public function onStockEnd( $product ): void {
		if ( $product instanceof \WC_Product ) {
			unset( $this->stockUpdate[ $product->get_id() ] );
		}
	}

	/**
	 * @param string   $new  New status.
	 * @param string   $old  Old status.
	 * @param \WP_Post $post Post.
	 */
	public function onStatus( $new, $old, $post ): void {
		if ( $new !== $old && ( 'publish' === $new || 'publish' === $old ) && $post instanceof \WP_Post && in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
			$this->mark( (int) $post->ID );
		}
	}

	/** @param mixed $snapshot Product snapshot. */
	public function onPriceChanged( $snapshot ): void {
		if ( $snapshot instanceof ProductSnapshot ) {
			$this->mark( $snapshot->id );
		}
	}

	/** Marks a product (and the variations of a variable product, whose rows depend on it). */
	public function mark( int $id ): void {
		if ( $id <= 0 ) {
			return;
		}
		$ids = array_merge( [ $id ], array_map( 'intval', (array) $this->wpdb->get_col( $this->wpdb->prepare( "SELECT ID FROM {$this->wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'", $id ) ) ) );
		$now = sprintf( '%.4F', microtime( true ) ); // Distinct from a mark read in the same second.
		foreach ( $ids as $one ) {
			update_post_meta( $one, self::META, $now );
		}
		( $this->schedule )( false );
	}

	public function count(): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->wpdb->postmeta} WHERE meta_key = %s", self::META ) );
	}

	/**
	 * @return array<int,string> Up to $limit marked IDs (ascending) with the time of their mark.
	 */
	public function take( int $limit ): array {
		$rows = (array) $this->wpdb->get_results( $this->wpdb->prepare( "SELECT post_id, meta_value FROM {$this->wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC LIMIT %d", self::META, $limit ) );
		$out  = [];
		foreach ( $rows as $row ) {
			$out[ (int) $row->post_id ] = (string) $row->meta_value;
		}
		return $out;
	}

	/**
	 * Clears marks that a run covered; a product marked again during the run keeps its newer mark.
	 *
	 * @param array<int,string> $marks IDs with the mark value read at the start of the run.
	 */
	public function clear( array $marks ): void {
		foreach ( $marks as $id => $value ) {
			delete_post_meta( (int) $id, self::META, $value );
		}
	}

	/** After a full build: clears every mark set before it started. */
	public function clearBefore( float $timestamp ): void {
		$ids = (array) $this->wpdb->get_col( $this->wpdb->prepare( "SELECT post_id FROM {$this->wpdb->postmeta} WHERE meta_key = %s AND CAST(meta_value AS DECIMAL(14,4)) < %f", self::META, $timestamp ) );
		if ( [] === $ids ) {
			return;
		}
		$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->wpdb->postmeta} WHERE meta_key = %s AND CAST(meta_value AS DECIMAL(14,4)) < %f", self::META, $timestamp ) );
		foreach ( $ids as $id ) {
			wp_cache_delete( (int) $id, 'post_meta' );
		}
	}

	/** The next batch of a large change, or marks made during a full build: run without the debounce. */
	public function scheduleFollowUp(): void {
		( $this->schedule )( true );
	}
}
