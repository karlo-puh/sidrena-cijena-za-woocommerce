<?php
/**
 * Lowest price in the 30 days before a sale started (ZZP čl. 19).
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\History;

use DateTimeImmutable;

class LowestPriceCalculator {

	public const WINDOW_DAYS = 30;

	public function __construct( private readonly PriceHistoryRepository $repo ) {}

	/** When the latest price row was recorded, i.e. the moment of the transition just recorded. */
	public function lastRecordedAt( int $productId ): ?DateTimeImmutable {
		return $this->repo->latestFor( $productId )?->recordedAt;
	}

	/** The active price of the row before the latest one, i.e. the price just replaced by the transition. */
	public function previousActive( int $productId ): ?float {
		$rows = $this->repo->historyFor( $productId, 2 );
		return isset( $rows[1] ) ? $rows[1]->active : null;
	}

	public function forSaleStart( int $productId, DateTimeImmutable $saleStart ): LowestResult {
		$from   = $saleStart->modify( '-' . self::WINDOW_DAYS . ' days' );
		$lowest = $this->repo->lowestActiveBetween( $productId, $from, $saleStart );
		if ( null === $lowest ) {
			return new LowestResult( null, LowestResult::INSUFFICIENT );
		}
		$earliest = $this->repo->earliestRecordedAt( $productId );
		$complete = null !== $earliest && $earliest <= $from;
		return new LowestResult( $lowest, $complete ? LowestResult::HISTORY : LowestResult::PARTIAL );
	}
}
