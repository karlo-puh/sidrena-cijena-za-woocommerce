<?php
/**
 * Orchestrates one price-list generation: lock, collect, write per format, publish, manifest, prune.
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\PriceList;

use RuntimeException;
use SidrenaCijena\Reference\ReferencePriceRegistry;
use SidrenaCijena\Settings\Settings;
use SidrenaCijena\Support\Clock;
use Throwable;

class Generator {

	public const OPTION_LAST = 'scwc_last_generation';

	/** @var callable():Outlet[] */
	private $outletProvider;

	/**
	 * @param callable():Outlet[]  $outletProvider Supplies all outlets (primary first) at run time.
	 * @param array<string,Writer> $writers        Writers keyed by format (xml, csv, ...).
	 */
	public function __construct(
		private readonly Settings $settings,
		callable $outletProvider,
		private readonly Collector $collector,
		private readonly array $writers,
		private readonly FilenameBuilder $filenames,
		private readonly Storage $storage,
		private readonly Manifest $manifest,
		private readonly Retention $retention,
		private readonly Clock $clock,
		private readonly ReferencePriceRegistry $registry,
	) {
		$this->outletProvider = $outletProvider;
	}

	/** Synchronous CLI entry point; web requests use the same steps through Action Scheduler. */
	public function run( string $reason = 'manual' ): GenerationResult {
		do {
			$result = $this->step( $reason );
			if ( null === $result && function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime(); // Only this CLI process's runtime cache, never persistent Redis.
			}
		} while ( null === $result && $this->pending() );
		return $result ?? new GenerationResult( [], $this->clock->nowLocal(), $reason, __( 'Nema generiranja za nastavak.', 'sidrena-cijena-za-woocommerce' ), 0, 0 );
	}

	public function pending(): bool {
		return is_file( $this->storage->tmpPath( 'generation.json' ) );
	}

	/** One collection batch or one streamed output file. Null means no completed generation. */
	public function step( string $reason = 'manual' ): ?GenerationResult {
		$local = $this->clock->nowLocal();
		if ( ! (bool) $this->settings->get( 'price_list.enabled', true ) ) {
			return new GenerationResult( [], $local, $reason, __( 'Cjenik je isključen u postavkama.', 'sidrena-cijena-za-woocommerce' ), 0, 0 );
		}
		$lock = null;
		try {
			$lock = $this->storage->lock();
			if ( null === $lock ) {
				return new GenerationResult( [], $local, $reason, __( 'Generiranje je već u tijeku.', 'sidrena-cijena-za-woocommerce' ), 0, 0, true );
			}
			if ( '__resume' === $reason && ! $this->pending() ) {
				return null; // A delayed continuation must never start another generation.
			}
			$result = $this->advance( $reason, $local );
		} catch ( Throwable $e ) {
			$result = new GenerationResult( [], $local, $reason, $e->getMessage(), 0, 0 );
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

	private function advance( string $reason, \DateTimeImmutable $local ): ?GenerationResult {
		$outlets = ( $this->outletProvider )();
		$primary = $outlets[0] ?? null;
		if ( ! $primary instanceof Outlet || ! $primary->isComplete() ) {
			throw new RuntimeException( esc_html__( 'Podaci o prodajnom objektu nisu potpuni.', 'sidrena-cijena-za-woocommerce' ) );
		}
		$path        = $this->storage->tmpPath( 'generation.json' );
		$fingerprint = hash( 'sha256', (string) wp_json_encode( [ $this->settings->all(), $outlets ] ) );
		$state       = $this->pending() ? json_decode( (string) file_get_contents( $path ), true ) : null;
		if ( ! is_array( $state ) || ! isset( $state['config'], $state['at'], $state['reason'], $state['cursor'], $state['bytes'], $state['collected'], $state['files'] ) ) {
			$state = null; // A damaged checkpoint must not prevent every future daily export.
		}
		$rowsPath = $this->storage->tmpPath( 'rows.ndjson' );
		if ( null !== $state && ( $state['bytes'] > 0 || $state['collected'] ) && ( ! is_file( $rowsPath ) || filesize( $rowsPath ) < $state['bytes'] ) ) {
			$this->clear( $state );
			$state = null;
		}
		if ( null !== $state && ( $state['config'] !== $fingerprint || substr( $state['at'], 0, 10 ) !== $local->format( 'Y-m-d' ) ) ) {
			// Restart stale or reconfigured work rather than publishing yesterday's partial catalogue.
			$this->clear( $state );
			$state = null;
		}
		if ( null === $state ) {
			// File names have second precision. Never overwrite a previously published generation.
			$this->manifest->load();
			while ( $this->storage->exists( $this->filenames->build( $primary, $local, $this->formats()[0] ?? 'xml' ) ) ) {
				$local = $local->modify( '+1 second' );
			}
			$state = [
				'at'        => $local->format( DATE_ATOM ),
				'reason'    => '__resume' === $reason ? 'scheduled' : $reason,
				'config'    => $fingerprint,
				'cursor'    => 0,
				'bytes'     => 0,
				'collected' => false,
				'files'     => [],
				'attempts'  => 0,
			];
		}
		if ( ( $state['attempts'] ?? 0 ) >= 3 ) {
			$this->clear( $state );
			throw new RuntimeException( 'Price-list step failed three times. Check scheduled-action logs and server limits before retrying.' );
		}
		$state['attempts'] = ( $state['attempts'] ?? 0 ) + 1;
		$this->storage->writeAtomic( $path, json_encode( $state, JSON_THROW_ON_ERROR ) );
		$local = new \DateTimeImmutable( $state['at'] );
		$rows  = new ItemStream( $this->storage->tmpPath( 'rows.ndjson' ) );
		if ( ! $state['collected'] ) {
			$batch              = $this->collector->batch( $state['cursor'] );
			$state['bytes']     = $rows->append( $batch['items'], $state['bytes'] );
			$state['cursor']    = $batch['cursor'];
			$state['collected'] = $batch['done'];
			$state['attempts']  = 0;
			$this->storage->writeAtomic( $path, json_encode( $state, JSON_THROW_ON_ERROR ) );
			return null;
		}

		$types = array_values( $this->registry->enabled() );
		$index = 0;
		foreach ( $outlets as $outlet ) {
			if ( ! $outlet instanceof Outlet || ! $outlet->isComplete() ) {
				continue;
			}
			foreach ( $this->formats() as $format ) {
				$writer = $this->writers[ $format ] ?? null;
				if ( ! $writer instanceof Writer || $index++ < count( $state['files'] ) ) {
					continue;
				}
				$name  = $this->filenames->build( $outlet, $local, $format );
				$stats = $writer->write( $rows, $outlet, $local, $this->storage->tmpPath( $name ), $types );
				$size  = filesize( $this->storage->tmpPath( $name ) );
				$hash  = hash_file( 'sha256', $this->storage->tmpPath( $name ) );
				if ( false === $size || false === $hash ) {
					throw new RuntimeException( 'Unable to verify price-list output.' );
				}
				$state['files'][]  = [
					'name'             => $name,
					'format'           => $format,
					'outlet'           => $outlet->key,
					'generated_at'     => $local->format( DATE_ATOM ),
					'generated_at_utc' => $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
					'reason'           => $state['reason'],
					'products'         => $stats->products,
					'services'         => $stats->services,
					'size'             => $size,
					'sha256'           => $hash,
				];
				$state['attempts'] = 0;
				$this->storage->writeAtomic( $path, json_encode( $state, JSON_THROW_ON_ERROR ) );
				return null;
			}
		}
		if ( [] === $state['files'] ) {
			throw new RuntimeException( 'No price-list output format is enabled.' );
		}
		// Readers switch only after every file is complete. Renames are retryable after interruption.
		foreach ( $state['files'] as $file ) {
			$name = $file['name'];
			if ( is_file( $this->storage->tmpPath( $name ) ) ) {
				$this->storage->publish( $name );
			}
			if ( ! $this->storage->exists( $name ) || hash_file( 'sha256', $this->storage->path( $name ) ) !== $file['sha256'] ) {
				throw new RuntimeException( 'Incomplete price-list output.' );
			}
		}
		$this->manifest->addAll( $state['files'] );
		$files  = array_map( fn( array $file ): array => $file + [ 'url' => $this->storage->url( $file['name'] ) ], $state['files'] );
		$result = new GenerationResult( $files, $local, $state['reason'], null, max( array_column( $files, 'products' ) ), max( array_column( $files, 'services' ) ) );
		$this->clear( $state );
		$this->retention->prune(
			(int) $this->settings->get( 'price_list.retention_days', 35 ),
			$primary->key,
			array_map( static fn( Outlet $o ): string => $o->key, $outlets )
		);
		return $result;
	}

	/** @param array<string,mixed> $state Run checkpoint. */
	private function clear( array $state ): void {
		foreach ( array_merge( [ 'generation.json', 'rows.ndjson' ], array_column( $state['files'], 'name' ) ) as $name ) {
			$path = $this->storage->tmpPath( $name );
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	/**
	 * @return string[] Enabled formats in settings order.
	 */
	private function formats(): array {
		$formats = $this->settings->get( 'price_list.formats', [ 'xml', 'csv' ] );
		return array_values( array_unique( array_map( 'strval', is_array( $formats ) ? $formats : [] ) ) );
	}

	private function storeSummary( GenerationResult $result ): void {
		update_option(
			self::OPTION_LAST,
			[
				'at'       => $result->generatedAt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				'at_local' => $result->generatedAt->format( DATE_ATOM ),
				'reason'   => $result->reason,
				'files'    => array_column( $result->files, 'name' ),
				'products' => $result->products,
				'services' => $result->services,
				'error'    => (string) $result->error,
			]
		);
	}
}
