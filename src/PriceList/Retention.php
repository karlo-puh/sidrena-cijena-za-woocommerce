<?php
/**
 * Deletes a price-list file the set number of days (at least 15, NN 132/2026 čl. 3(6)) after a newer file of the
 * same outlet and kind replaced it. The current file of each outlet and kind is never deleted. Pre-2.0 files (no
 * kind; combined CSV/XML) are replaced by the outlet's first 2.0 file.
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\PriceList;

use DateTimeImmutable;
use DateTimeZone;
use SidrenaCijena\Support\Clock;

class Retention {

	public const MIN_DAYS = 15;

	public function __construct(
		private readonly Manifest $manifest,
		private readonly Storage $storage,
		private readonly Clock $clock,
	) {}

	/**
	 * @param int      $days       Days a replaced file stays available (floored to MIN_DAYS).
	 * @param string   $primaryKey Key of the primary outlet; entries without an outlet key count as its files.
	 * @param string[] $knownKeys  Keys of configured outlets; files of other (removed) outlets count as replaced.
	 * @return string[] Removed file names.
	 */
	public function prune( int $days, string $primaryKey = '', array $knownKeys = [] ): array {
		$cutoff  = $this->clock->now()->modify( sprintf( '-%d days', max( self::MIN_DAYS, $days ) ) );
		$first20 = []; // Per outlet: time of its oldest 2.0 file, which replaced the pre-2.0 files.
		$current = []; // Per group: time of the entries being looked at.
		$newer   = []; // Per group: time of the next newer file, i.e. when the current ones were replaced.
		$removed = [];
		foreach ( $this->manifest->entries() as $entry ) { // Newest first.
			$name = (string) ( $entry['name'] ?? '' );
			$time = $this->parseUtc( (string) ( $entry['generated_at_utc'] ?? '' ) );
			if ( '' === $name || null === $time ) {
				continue;
			}
			if ( ! $this->storage->exists( $name ) ) {
				$removed[] = $name;
				continue;
			}
			$outlet = (string) ( $entry['outlet'] ?? '' );
			$outlet = '' === $outlet ? $primaryKey : $outlet;
			$kind   = (string) ( $entry['kind'] ?? '' );
			$group  = $outlet . '|' . $kind;
			if ( '' !== $kind ) {
				$first20[ $outlet ] = $time;
			}
			if ( ! isset( $current[ $group ] ) ) {
				$newer[ $group ]   = '' === $kind ? ( $first20[ $outlet ] ?? null ) : null;
				$current[ $group ] = $time;
			} elseif ( $time < $current[ $group ] ) {
				$newer[ $group ]   = $current[ $group ]; // Files of one run (old CSV + XML) share a time.
				$current[ $group ] = $time;
			}
			$replaced = $newer[ $group ];
			if ( null === $replaced && [] !== $knownKeys && ! in_array( $outlet, $knownKeys, true ) ) {
				$replaced = $time; // Outlet no longer configured.
			}
			if ( null !== $replaced && $replaced < $cutoff ) {
				$this->storage->delete( $name );
				$removed[] = $name;
			}
		}
		foreach ( $removed as $name ) {
			$this->manifest->remove( $name );
		}
		return $removed;
	}

	private function parseUtc( string $value ): ?DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		return false === $date ? null : $date;
	}
}
