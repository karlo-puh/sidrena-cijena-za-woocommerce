<?php
/**
 * Price-list runs (Pravilnik o načinu objave cjenika proizvoda, NN 132/2026):
 * - full build: collects every product in queue-sized batches (daily check, bulk changes), keeps the rows;
 * - change run: rebuilds only the products marked by ChangeTracker and merges them into the kept rows.
 * Both write one CSV per outlet and kind (goods/services) and publish a file only when it differs from the
 * current one; replaced files are pruned 15 days (setting) after their replacement.
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\PriceList;

use RuntimeException;
use SidrenaCijena\Settings\Settings;
use SidrenaCijena\Support\Clock;
use Throwable;

class Generator {

	public const OPTION_LAST = 'scwc_last_generation';
	public const ROWS        = 'rows-full.ndjson';
	public const ROWS_CONFIG = 'rows-full.config'; // Settings fingerprint of the kept rows.
	/** Products rebuilt per change run; more marked than FULL_ABOVE → one full build instead. */
	public const CHANGE_BATCH = 200;
	public const FULL_ABOVE   = 2000;

	/** @var callable():Outlet[] */
	private $outletProvider;

	/**
	 * @param callable():Outlet[] $outletProvider Supplies all outlets (primary first) at run time.
	 */
	public function __construct(
		private readonly Settings $settings,
		callable $outletProvider,
		private readonly Collector $collector,
		private readonly CsvWriter $writer,
		private readonly FilenameBuilder $filenames,
		private readonly Storage $storage,
		private readonly Manifest $manifest,
		private readonly Retention $retention,
		private readonly Clock $clock,
		private readonly ChangeTracker $changes,
	) {
		$this->outletProvider = $outletProvider;
	}

	/** Synchronous CLI entry point; web requests use the same steps through Action Scheduler. */
	public function run( string $reason = 'manual' ): GenerationResult {
		do {
			$result = $this->step( $reason );
			$reason = '__resume'; // Later steps continue this run (a repeated 'bulk' would restart it).
			if ( null === $result && function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime(); // Only this CLI process's runtime cache, never persistent Redis.
			}
		} while ( null === $result && $this->pending() );
		return $result ?? $this->noRun( $reason, __( 'Nema generiranja za nastavak.', 'sidrena-cijena-za-woocommerce' ) );
	}

	public function pending(): bool {
		return is_file( $this->storage->tmpPath( 'generation.json' ) );
	}

	/** One collection batch, one output file, or a whole change run. Null means no completed run. */
	public function step( string $reason = 'manual' ): ?GenerationResult {
		if ( ! (bool) $this->settings->get( 'price_list.enabled', true ) ) {
			return $this->noRun( $reason, __( 'Cjenik je isključen u postavkama.', 'sidrena-cijena-za-woocommerce' ) );
		}
		$lock = null;
		try {
			$lock = $this->storage->lock();
			if ( null === $lock ) {
				return new GenerationResult( [], $this->clock->nowLocal(), $reason, __( 'Generiranje je već u tijeku.', 'sidrena-cijena-za-woocommerce' ), 0, 0, true );
			}
			if ( '__resume' === $reason && ! $this->pending() ) {
				return null; // A delayed continuation must never start another run.
			}
			$result = $this->advance( $reason );
		} catch ( Throwable $e ) {
			$result = $this->noRun( $reason, $e->getMessage() );
		} finally {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
		}
		if ( null !== $result ) {
			$this->storeSummary( $result );
			if ( $result->ok() ) {
				do_action( 'scwc_price_list_generated', $result );
			}
		}
		return $result;
	}

	private function advance( string $reason ): ?GenerationResult {
		$targets   = $this->targets();
		$kept      = $this->storage->tmpPath( self::ROWS_CONFIG );
		$rowsFresh = is_file( $this->storage->tmpPath( self::ROWS ) ) && is_file( $kept ) && file_get_contents( $kept ) === $this->fingerprint( $targets );
		if ( 'change' === $reason && ! $this->pending() && $rowsFresh ) {
			$marked = $this->changes->count();
			if ( 0 === $marked ) {
				// Nothing left to rebuild; a batch merged earlier may still be unpublished (publish skips if unchanged).
				return $this->publish( $this->writeAll( $targets, $this->storage->tmpPath( self::ROWS ) ), $reason );
			}
			if ( $marked <= self::FULL_ABOVE ) {
				return $this->changeRun( $targets, $reason );
			}
		}
		return $this->fullBuild( $targets, $reason );
	}

	/**
	 * Rebuilds the marked products only and merges them into the rows kept from the last run.
	 *
	 * @param array<int,array{outlet:?Outlet,key:string}> $targets Outputs per outlet.
	 */
	private function changeRun( array $targets, string $reason ): ?GenerationResult {
		$marks = $this->changes->take( self::CHANGE_BATCH );
		$items = $this->collector->itemsFor( array_keys( $marks ) );
		ksort( $items );
		$merged = $this->storage->tmpPath( 'rows.ndjson' );
		$this->merge( $this->storage->tmpPath( self::ROWS ), $items, $merged );
		if ( $this->changes->count() > count( $marks ) && ! $this->nearDeadline() ) {
			// More products are waiting (an import or bulk edit): merge this batch, publish once after the last one.
			$this->keepRows( $merged );
			$this->changes->clear( $marks );
			$this->changes->scheduleFollowUp();
			return null; // Not a completed check yet.
		}
		$result = $this->publish( $this->writeAll( $targets, $merged ), $reason );
		$this->keepRows( $merged );
		$this->changes->clear( $marks );
		return $result;
	}

	/** 10:45–11:00: publish every batch, so an import running into the deadline cannot hold finished prices. */
	private function nearDeadline(): bool {
		$time = $this->clock->nowLocal()->format( 'H:i' );
		return $time >= '10:45' && $time < '11:00';
	}

	private function keepRows( string $path ): void {
		if ( ! rename( $path, $this->storage->tmpPath( self::ROWS ) ) ) {
			throw new RuntimeException( 'Unable to keep price-list rows.' );
		}
	}

	/**
	 * Streams the kept rows (ordered by product ID) into $out, replacing, dropping or inserting the rebuilt ones.
	 *
	 * @param array<int,Item|ServiceItem|null> $items Rebuilt rows by product ID, ascending; null = no longer listed.
	 */
	private function merge( string $in, array $items, string $out ): void {
		$src = fopen( $in, 'rb' );
		$dst = fopen( $out, 'wb' );
		if ( false === $src || false === $dst ) {
			throw new RuntimeException( 'Unable to update price-list rows.' );
		}
		try {
			$write = static function ( string $line ) use ( $dst ): void {
				if ( strlen( $line ) !== fwrite( $dst, $line ) ) {
					throw new RuntimeException( 'Unable to write price-list rows.' ); // E.g. disk full: keep the old rows.
				}
			};
			$put   = static function ( $item ) use ( $write ): void {
				if ( null !== $item ) {
					$write( ItemStream::encode( $item ) );
				}
			};
			$ids   = array_keys( $items );
			$i     = 0;
			$n     = count( $ids );
			while ( false !== ( $line = fgets( $src ) ) ) {
				$id = ItemStream::idOf( $line );
				while ( $i < $n && $ids[ $i ] < $id ) {
					$put( $items[ $ids[ $i++ ] ] ); // New in the list: goes before the next higher ID.
				}
				if ( $i < $n && $ids[ $i ] === $id ) {
					$put( $items[ $ids[ $i++ ] ] ); // Replaced, or dropped when null.
					continue;
				}
				$write( $line );
			}
			for ( ; $i < $n; $i++ ) {
				$put( $items[ $ids[ $i ] ] );
			}
			if ( ! fflush( $dst ) ) {
				throw new RuntimeException( 'Unable to write price-list rows.' );
			}
		} finally {
			fclose( $src );
			fclose( $dst );
		}
	}

	/** @param array<int,array{outlet:?Outlet,key:string}> $targets Outputs per outlet. */
	private function fullBuild( array $targets, string $reason ): ?GenerationResult {
		$path        = $this->storage->tmpPath( 'generation.json' );
		$fingerprint = $this->fingerprint( $targets );
		$state       = $this->pending() ? json_decode( (string) file_get_contents( $path ), true ) : null;
		if ( ! is_array( $state ) || ! isset( $state['config'], $state['day'], $state['reason'], $state['cursor'], $state['bytes'], $state['collected'], $state['started'] ) ) {
			$state = null; // A damaged checkpoint must not prevent every future daily check.
		}
		$rowsPath = $this->storage->tmpPath( 'rows.ndjson' );
		if ( null !== $state && $state['bytes'] > 0 && ( ! is_file( $rowsPath ) || filesize( $rowsPath ) < $state['bytes'] ) ) {
			$state = null;
		}
		if ( null !== $state && ( $state['config'] !== $fingerprint || $state['day'] !== $this->clock->nowLocal()->format( 'Y-m-d' ) ) ) {
			$state = null; // Restart stale or reconfigured work rather than publishing yesterday's partial catalogue.
		}
		if ( null !== $state && 'bulk' === $reason ) {
			$state = null; // An import or snapshot during a build may have changed products it already collected.
		}
		if ( null === $state ) {
			$state = [
				'day'       => $this->clock->nowLocal()->format( 'Y-m-d' ),
				'reason'    => '__resume' === $reason ? 'scheduled' : $reason,
				'config'    => $fingerprint,
				'cursor'    => 0,
				'bytes'     => 0,
				'collected' => false,
				'started'   => microtime( true ),
				'attempts'  => 0,
			];
		}
		if ( ( $state['attempts'] ?? 0 ) >= 3 ) {
			$this->clearState();
			throw new RuntimeException( 'Price-list step failed three times. Check scheduled-action logs and server limits before retrying.' );
		}
		$state['attempts'] = ( $state['attempts'] ?? 0 ) + 1;
		$this->storage->writeAtomic( $path, json_encode( $state, JSON_THROW_ON_ERROR ) );
		if ( ! $state['collected'] ) {
			$batch              = $this->collector->batch( $state['cursor'] );
			$state['bytes']     = ( new ItemStream( $rowsPath ) )->append( $batch['items'], $state['bytes'] );
			$state['cursor']    = $batch['cursor'];
			$state['collected'] = $batch['done'];
			$state['attempts']  = 0;
			$this->storage->writeAtomic( $path, json_encode( $state, JSON_THROW_ON_ERROR ) );
			return null;
		}
		$result = $this->publish( $this->writeAll( $targets, $rowsPath ), (string) $state['reason'] );
		$this->keepRows( $rowsPath );
		$this->storage->writeAtomic( $this->storage->tmpPath( self::ROWS_CONFIG ), $fingerprint );
		$this->changes->clearBefore( (float) $state['started'] ); // Marks set during the build stay for a change run.
		$this->clearState();
		if ( $this->changes->count() > 0 ) {
			$this->changes->scheduleFollowUp();
		}
		return $result;
	}

	/**
	 * @param array<int,array{outlet:?Outlet,key:string}> $targets Outputs per outlet (outlet null = all outlets).
	 * @return array<int,array<string,mixed>> Written temp files with their hash and row count.
	 */
	private function writeAll( array $targets, string $rowsPath ): array {
		$out = [];
		foreach ( $targets as $target ) {
			foreach ( CsvWriter::KINDS as $kind ) {
				$tmp   = $this->storage->tmpPath( 'out-' . $target['key'] . '-' . $kind . '.csv' );
				$rows  = $this->writer->write( new ItemStream( $rowsPath ), $kind, $tmp );
				$hash  = hash_file( 'sha256', $tmp );
				$out[] = $target + [
					'kind' => $kind,
					'tmp'  => $tmp,
					'rows' => $rows,
					'hash' => false === $hash ? '' : $hash,
				];
			}
		}
		return $out;
	}

	/**
	 * Publishes every written file that differs from the current one of its outlet and kind.
	 *
	 * @param array<int,array<string,mixed>> $outputs From writeAll().
	 */
	private function publish( array $outputs, string $reason ): GenerationResult {
		$this->manifest->load();
		$now       = $this->clock->nowLocal();
		$seller    = (string) $this->settings->get( 'price_list.seller_id', '' );
		$published = [];
		$counts    = [];
		foreach ( $outputs as $o ) {
			$counts[ $o['kind'] ] = max( $counts[ $o['kind'] ] ?? 0, $o['rows'] );
			$current              = $this->manifest->latest( 'csv', $o['key'], $o['key'], $o['kind'] );
			$config               = $this->config( $o['outlet'] );
			$same                 = null !== $current && ( $current['sha256'] ?? '' ) === $o['hash'] && ( $current['config'] ?? '' ) === $config && $this->storage->exists( (string) $current['name'] );
			if ( $same || ( 0 === $o['rows'] && null === $current ) ) {
				unlink( $o['tmp'] ); // Unchanged, or no services at all: nothing to publish.
				continue;
			}
			$number = $this->manifest->nextNumber( $o['key'], $o['kind'] );
			do {
				$name = $this->filenames->build( $seller, $o['outlet'], $o['kind'], $number++, $now );
			} while ( $this->storage->exists( $name ) );
			rename( $o['tmp'], $this->storage->tmpPath( $name ) );
			$this->storage->publish( $name );
			if ( ! $this->storage->exists( $name ) || hash_file( 'sha256', $this->storage->path( $name ) ) !== $o['hash'] ) {
				throw new RuntimeException( 'Incomplete price-list output.' );
			}
			$published[] = [
				'name'             => $name,
				'format'           => 'csv',
				'kind'             => $o['kind'],
				'outlet'           => $o['key'],
				'number'           => $number - 1,
				'generated_at'     => $now->format( DATE_ATOM ),
				'generated_at_utc' => $now->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				'reason'           => $reason,
				'products'         => CsvWriter::GOODS === $o['kind'] ? $o['rows'] : 0,
				'services'         => CsvWriter::SERVICES === $o['kind'] ? $o['rows'] : 0,
				'size'             => $this->storage->size( $name ),
				'sha256'           => $o['hash'],
				'config'           => $config,
			];
		}
		if ( [] !== $published ) {
			$this->manifest->addAll( $published );
		}
		return $this->finish( $published, $reason, $counts[ CsvWriter::GOODS ] ?? 0, $counts[ CsvWriter::SERVICES ] ?? 0 );
	}

	/**
	 * Prunes the archive (also when nothing was published) and reports the run.
	 *
	 * @param array<int,array<string,mixed>> $published Manifest entries published by this run.
	 */
	private function finish( array $published, string $reason, int $products = 0, int $services = 0 ): GenerationResult {
		$outlets = ( $this->outletProvider )();
		$this->retention->prune(
			(int) $this->settings->get( 'price_list.retention_days', 15 ),
			(string) ( $outlets[0]->key ?? '' ),
			array_map( static fn( Outlet $o ): string => $o->key, $outlets )
		);
		$files = array_map( fn( array $file ): array => $file + [ 'url' => $this->storage->url( $file['name'] ) ], $published );
		return new GenerationResult( $files, $this->clock->nowLocal(), $reason, null, $products, $services );
	}

	/**
	 * @return array<int,array{outlet:?Outlet,key:string}>
	 */
	private function targets(): array {
		if ( '' === (string) $this->settings->get( 'price_list.seller_id', '' ) ) {
			throw new RuntimeException( esc_html__( 'Unesite OIB / matični broj trgovca (Sidrena cijena → Cjenik).', 'sidrena-cijena-za-woocommerce' ) );
		}
		$outlets = array_values( array_filter( ( $this->outletProvider )(), static fn( $o ) => $o instanceof Outlet && $o->isComplete() ) );
		if ( [] === $outlets || ! $outlets[0]->isPrimary() ) {
			throw new RuntimeException( esc_html__( 'Podaci o prodajnom objektu nisu potpuni.', 'sidrena-cijena-za-woocommerce' ) );
		}
		if ( (bool) $this->settings->get( 'price_list.shared_list', false ) ) {
			return [
				[
					'outlet' => null,
					'key'    => $outlets[0]->key,
				],
			];
		}
		return array_map(
			static fn( Outlet $o ): array => [
				'outlet' => $o,
				'key'    => $o->key,
			],
			$outlets
		);
	}

	/**
	 * Settings and outlets the rows were built with; a change forces a full build.
	 *
	 * @param array<int,array{outlet:?Outlet,key:string}> $targets Outputs per outlet.
	 */
	private function fingerprint( array $targets ): string {
		return hash( 'sha256', (string) wp_json_encode( [ $this->settings->all(), $targets ] ) );
	}

	/** What the file name is built from: a change here publishes a new file even with identical rows. */
	private function config( ?Outlet $outlet ): string {
		return hash( 'sha256', implode( '|', [ (string) $this->settings->get( 'price_list.seller_id', '' ), null === $outlet ? FilenameBuilder::ALL_OUTLETS : $outlet->form . '|' . $outlet->address . '|' . $outlet->label ] ) );
	}

	private function clearState(): void {
		foreach ( [ 'generation.json', 'rows.ndjson' ] as $name ) {
			$path = $this->storage->tmpPath( $name );
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	private function noRun( string $reason, string $error ): GenerationResult {
		return new GenerationResult( [], $this->clock->nowLocal(), $reason, $error, 0, 0 );
	}

	private function storeSummary( GenerationResult $result ): void {
		$previous = get_option( self::OPTION_LAST, [] );
		$previous = is_array( $previous ) ? $previous : [];
		$now      = $result->generatedAt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$summary  = [
			'at'       => $now, // Last completed check (published or not).
			'at_local' => $result->generatedAt->format( DATE_ATOM ),
			'reason'   => $result->reason,
			'error'    => (string) $result->error,
		];
		if ( [] !== $result->files ) {
			$summary['published_at'] = $now;
			$summary['files']        = array_column( $result->files, 'name' );
		} else {
			$summary['published_at'] = (string) ( $previous['published_at'] ?? ( $previous['at'] ?? '' ) );
			$summary['files']        = (array) ( $previous['files'] ?? [] );
		}
		$summary['products'] = $result->products ?: (int) ( $previous['products'] ?? 0 );
		$summary['services'] = $result->services ?: (int) ( $previous['services'] ?? 0 );
		update_option( self::OPTION_LAST, $summary );
	}
}
