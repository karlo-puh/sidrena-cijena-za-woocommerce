<?php
/**
 * Captures the first regular price of newly created products when offered publicly.
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\Reference;

use SidrenaCijena\Product\ProductAdapter;
use SidrenaCijena\Support\Clock;
use SidrenaCijena\Support\DateFormat;
use WC_Product;

final class FirstOfferCapture {

	public const PENDING_KEY = '_scwc_first_offer_pending';

	/** @var array<int,true> Status-only publications, including scheduled products. */
	private array $pending = [];

	/** @var callable(int):?WC_Product */
	private $loader;

	/** @param callable(int):?WC_Product $loader Product loader. */
	public function __construct(
		private readonly ReferencePriceRegistry $registry,
		private readonly ReferencePriceRepository $references,
		private readonly ProductAdapter $adapter,
		private readonly Clock $clock,
		callable $loader,
	) {
		$this->loader = $loader;
	}

	public function register(): void {
		// Includes the auto-draft created by the classic WooCommerce product editor.
		add_action( 'wp_insert_post', [ $this, 'onInserted' ], 10, 3 );
		add_action( 'woocommerce_after_product_object_save', [ $this, 'onSaved' ], 20 );
		add_action( 'transition_post_status', [ $this, 'onStatusChanged' ], 10, 3 );
		add_action( 'shutdown', [ $this, 'flush' ] );
	}

	/**
	 * @param int      $id     Post ID.
	 * @param \WP_Post $post   Inserted post.
	 * @param bool     $update Whether this is an update.
	 */
	public function onInserted( $id, $post, $update ): void {
		if ( ! $update && in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
			// Bind the marker to this ID so copying meta cannot re-arm another product.
			update_post_meta( $id, self::PENDING_KEY, (string) $id );
		}
	}

	/**
	 * @param string   $new  New status.
	 * @param string   $old  Previous status.
	 * @param \WP_Post $post Product or variation post.
	 */
	public function onStatusChanged( $new, $old, $post ): void {
		if ( 'publish' === $new && 'publish' !== $old && in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
			$this->pending[ (int) $post->ID ] = true;
		}
	}

	/** @param WC_Product $product Product after WooCommerce has saved prices and meta. */
	public function onSaved( WC_Product $product ): void {
		if ( 'publish' !== $product->get_status( 'edit' ) ) {
			return;
		}
		if ( $product instanceof \WC_Product_Variable ) {
			foreach ( $product->get_children() as $childId ) {
				$child = ( $this->loader )( (int) $childId );
				if ( $child instanceof WC_Product ) {
					$this->onSaved( $child );
				}
			}
			return;
		}
		$id = $product->get_id();
		if ( 'grouped' === $product->get_type() || (string) $id !== (string) get_post_meta( $id, self::PENDING_KEY, true ) ) {
			return;
		}
		$parent = $product->get_parent_id() > 0 ? ( $this->loader )( $product->get_parent_id() ) : null;
		if ( $product->is_type( 'variation' ) && ( ! $parent || 'publish' !== $parent->get_status( 'edit' ) ) ) {
			return;
		}
		$price = $product->get_regular_price( 'edit' );
		if ( '' === $product->get_price( 'edit' ) || ! is_numeric( $price ) || (float) $price < 0 ) {
			return;
		}

		$type = $this->registry->primary();
		// Read persisted meta: another save handler may have supplied a reference.
		$existing = (string) get_post_meta( $id, $type->metaKey( 'price' ), true );
		$na       = '1' === (string) get_post_meta( $id, $type->metaKey( 'na' ), true );
		$snapshot = $this->adapter->fromProduct( $product, $parent );
		$date     = $this->references->get( $type, $snapshot, $parent ? $this->adapter->fromProduct( $parent ) : null )->date;
		$today    = $this->clock->nowLocal()->format( DateFormat::ISO );
		if ( '' === $existing && ! $na && null !== $date && $date->format( DateFormat::ISO ) <= $today ) {
			$this->references->set( $type, $id, $price, $today, ReferencePriceRepository::SOURCE_FIRST_OFFER );
			delete_post_meta( $id, self::PENDING_KEY );
			$product->read_meta_data( true );
			do_action( 'scwc_first_offer_captured', $id );
			return;
		}
		// Consume even when a supplied reference was preserved or the anchor date is in the future.
		delete_post_meta( $id, self::PENDING_KEY );
	}

	public function flush(): void {
		$ids           = array_keys( $this->pending );
		$this->pending = [];
		foreach ( $ids as $id ) {
			$product = ( $this->loader )( $id );
			if ( $product instanceof WC_Product ) {
				$this->onSaved( $product );
			}
		}
	}
}
