<?php
/**
 * Streams price-list rows over all published products (variations expanded, parents excluded).
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\PriceList;

use Generator;
use SidrenaCijena\Product\ProductAdapter;
use SidrenaCijena\Product\ProductSnapshot;
use WC_Product;

class Collector {

	/** @var callable(int,int,?int[]):int[] */
	private $pager;
	/** @var callable(int):?WC_Product */
	private $loader;
	/** @var callable(ProductSnapshot):void|null */
	private $observer;

	/**
	 * @param callable(int,int,?int[]):int[]          $pager    Returns published product/variation IDs after a cursor, up to perPage (optionally only among the given IDs).
	 * @param callable(int):?WC_Product              $loader   Product loader.
	 * @param callable(ProductSnapshot):void|null    $observer Called for every non-container snapshot seen.
	 */
	public function __construct(
		callable $pager,
		callable $loader,
		private readonly ProductAdapter $adapter,
		private readonly ItemFactory $factory,
		private readonly int $perPage = 100,
		?callable $observer = null,
	) {
		$this->pager    = $pager;
		$this->loader   = $loader;
		$this->observer = $observer;
	}

	/**
	 * Collect at most one small batch. The cursor counts scanned IDs, including excluded rows.
	 * Variations are paged independently, so a large variable product cannot expand a batch.
	 *
	 * @return array{items:array<Item|ServiceItem>,cursor:int,done:bool}
	 */
	public function batch( int $after ): array {
		$ids     = ( $this->pager )( $after, max( 1, $this->perPage ), null );
		$items   = [];
		$started = microtime( true );
		foreach ( $ids as $id ) {
			$after = (int) $id;
			$item  = $this->itemFor( $after );
			if ( null !== $item ) {
				$items[] = $item;
			}
			// Yield to the queue between products; never interrupt a price-history write.
			if ( microtime( true ) - $started >= 10 ) {
				break;
			}
		}
		return [
			'items'  => $items,
			'cursor' => $after,
			'done'   => [] === $ids,
		];
	}

	/** @return Generator<int,Item|ServiceItem> */
	public function items(): Generator {
		$after = 0;
		do {
			$batch = $this->batch( $after );
			foreach ( $batch['items'] as $item ) {
				yield $item;
			}
			$after = $batch['cursor'];
		} while ( ! $batch['done'] );
	}

	/**
	 * Rows for the given products only (change runs). Same visibility as the full build: an ID the pager would not
	 * list (unpublished, deleted, other language) or a container product maps to null, i.e. "not in the list".
	 *
	 * @param int[] $ids Product or variation IDs.
	 * @return array<int,Item|ServiceItem|null> By ID.
	 */
	public function itemsFor( array $ids ): array {
		$ids    = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$listed = [] === $ids ? [] : array_flip( ( $this->pager )( 0, count( $ids ), $ids ) );
		$out    = [];
		foreach ( $ids as $id ) {
			$out[ $id ] = isset( $listed[ $id ] ) ? $this->itemFor( $id ) : null;
		}
		return $out;
	}

	/** @return Item|ServiceItem|null */
	private function itemFor( int $id ) {
		$product = ( $this->loader )( $id );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$parent = $product->get_parent_id() ? ( $this->loader )( $product->get_parent_id() ) : null;
		if ( $product instanceof \WC_Product_Variation && ! ( $parent instanceof WC_Product && 'publish' === $parent->get_status() ) ) {
			return null;
		}
		return $this->make( $product, $parent, $parent ? $this->adapter->fromProduct( $parent ) : null );
	}

	/**
	 * @return Item|ServiceItem|null
	 */
	private function make( WC_Product $product, ?WC_Product $parent, ?ProductSnapshot $parentSnapshot ) {
		$snapshot = $this->adapter->fromProduct( $product, $parent );
		if ( $snapshot->isContainer() ) {
			return null;
		}
		if ( null !== $this->observer ) {
			( $this->observer )( $snapshot );
		}
		return $this->factory->make( $product, $snapshot, $parentSnapshot );
	}

	/**
	 * Keyset paging through WP_Query; deleting an earlier product cannot skip a row.
	 * Product values still come from WooCommerce CRUD, including third-party product types.
	 *
	 * @return callable(int,int,?int[]):int[]
	 */
	public static function wcPager(): callable {
		return static function ( int $after, int $limit, ?array $only = null ): array {
			global $wpdb;
			$query = new \WP_Query();
			$where = static function ( string $sql, \WP_Query $current ) use ( $query, $after, $wpdb ): string {
				return $current === $query ? $sql . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after ) : $sql;
			};
			// Scope the cursor to this query, while retaining native language and visibility filters.
			add_filter( 'posts_where', $where, 10, 2 );
			try {
				$ids = $query->query(
					[
						'post_type'        => [ 'product', 'product_variation' ],
						'post_status'      => 'publish',
						'posts_per_page'   => max( 1, $limit ),
						'orderby'          => 'ID',
						'order'            => 'ASC',
						'fields'           => 'ids',
						'no_found_rows'    => true,
						'suppress_filters' => false,
					] + ( null === $only ? [] : [ 'post__in' => $only ] )
				);
			} finally {
				remove_filter( 'posts_where', $where, 10 );
			}
			if ( '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'Unable to read price-list products.' );
			}
			return array_map( 'intval', $ids );
		};
	}
}
