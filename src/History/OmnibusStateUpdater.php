<?php
/**
 * Maintains the per-product 30-day reference meta across sale transitions.
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\History;

use DateTimeImmutable;
use DateTimeZone;
use SidrenaCijena\Product\MetaKeys;
use SidrenaCijena\Product\ProductSnapshot;
use SidrenaCijena\Settings\Settings;
use SidrenaCijena\Support\Clock;

class OmnibusStateUpdater {

	private const DT = 'Y-m-d H:i:s';

	public function __construct(
		private readonly LowestPriceCalculator $calculator,
		private readonly Settings $settings,
		private readonly Clock $clock,
	) {}

	public function apply( ProductSnapshot $p, string $transition ): void {
		$hasMeta = '' !== (string) $p->meta( MetaKeys::OMNIBUS_REF_PRICE );
		if ( ! $p->isOnSale ) {
			if ( $hasMeta || Transition::SALE_ENDED === $transition ) {
				$this->clear( $p->id );
			}
			return;
		}
		$fresh = in_array( $transition, [ Transition::SALE_STARTED, Transition::SALE_RAISED, Transition::FIRST ], true );
		if ( ! $fresh && $hasMeta ) {
			return; // Progressive reductions keep the original reference.
		}
		$saleStart = $this->saleStart( $p, $transition );
		$result    = $this->calculator->forSaleStart( $p->id, $saleStart );
		if ( null === $result->amount ) {
			if ( 'regular' === $this->settings->get( 'history.omnibus_fallback', 'regular' ) && is_numeric( $p->regular ) && (float) $p->regular > 0 ) {
				$this->store( $p->id, (float) $p->regular, $saleStart, LowestResult::REGULAR_FALLBACK );
			} else {
				$this->clear( $p->id );
			}
			return;
		}
		$this->store( $p->id, $result->amount, $saleStart, $result->source );
	}

	/**
	 * Start of the reduction the reference belongs to. A raised sale price starts a new window at the
	 * moment of the raise. Otherwise a past WooCommerce schedule date, else the time the transition
	 * was recorded – the very same instant as the new price row, so that row is never inside the
	 * window (taking a second "now" here let the new sale price count as its own 30-day low).
	 */
	private function saleStart( ProductSnapshot $p, string $transition ): DateTimeImmutable {
		$now      = $this->clock->now();
		$recorded = $this->calculator->lastRecordedAt( $p->id );
		if ( Transition::SALE_RAISED !== $transition && $p->saleFrom instanceof DateTimeImmutable && $p->saleFrom < $now ) {
			return $p->saleFrom->setTimezone( new DateTimeZone( 'UTC' ) );
		}
		return ( $recorded && $recorded <= $now ) ? $recorded->setTimezone( new DateTimeZone( 'UTC' ) ) : $now;
	}

	private function store( int $id, float $amount, DateTimeImmutable $saleStart, string $source ): void {
		update_post_meta( $id, MetaKeys::OMNIBUS_REF_PRICE, number_format( $amount, 4, '.', '' ) );
		update_post_meta( $id, MetaKeys::OMNIBUS_SALE_START, $saleStart->setTimezone( new DateTimeZone( 'UTC' ) )->format( self::DT ) );
		update_post_meta( $id, MetaKeys::OMNIBUS_SOURCE, $source );
	}

	private function clear( int $id ): void {
		delete_post_meta( $id, MetaKeys::OMNIBUS_REF_PRICE );
		delete_post_meta( $id, MetaKeys::OMNIBUS_SALE_START );
		delete_post_meta( $id, MetaKeys::OMNIBUS_SOURCE );
	}
}
