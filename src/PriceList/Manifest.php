<?php
/**
 * manifest.json: list of generated price-list files, newest first.
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\PriceList;

class Manifest {

	public const FILENAME = 'manifest.json';

	/** @var array<int,array<string,mixed>>|null Null until loaded. */
	private ?array $entries = null;

	public function __construct( private readonly Storage $storage ) {}

	/** (Re)read the manifest from disk; a missing or corrupt file is treated as empty. */
	public function load(): void {
		$this->entries = [];
		$path          = $this->path();
		if ( ! is_file( $path ) ) {
			return;
		}
		$json = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $json ) || ! isset( $json['generated'] ) || ! is_array( $json['generated'] ) ) {
			return;
		}
		foreach ( $json['generated'] as $entry ) {
			if ( is_array( $entry ) && isset( $entry['name'] ) && is_string( $entry['name'] ) ) {
				$this->entries[] = $entry;
			}
		}
	}

	/**
	 * @return array<int,array<string,mixed>> Newest first.
	 */
	public function entries(): array {
		if ( null === $this->entries ) {
			$this->load();
		}
		return $this->entries ?? [];
	}

	/**
	 * Prepend an entry (replacing any entry with the same name) and save.
	 *
	 * @param array<string,mixed> $entry Entry with at least name/format/generated_at_utc.
	 */
	public function add( array $entry ): void {
		$this->addAll( [ $entry ] );
	}

	/** @param array<int,array<string,mixed>> $newEntries Completed files from one generation. */
	public function addAll( array $newEntries ): void {
		$this->load();
		$entries = $this->entries();
		foreach ( $newEntries as $entry ) {
			$entries = array_values( array_filter( $entries, static fn( array $e ) => $e['name'] !== $entry['name'] ) );
			array_unshift( $entries, $entry );
		}
		$this->entries = $entries;
		try {
			$this->save();
		} catch ( \Throwable $e ) {
			$this->load();
			throw $e;
		}
	}

	/**
	 * Newest entry of a format. With $outletKey only that outlet's files count; entries without an
	 * outlet key (pre-1.1 manifests) belong to $primaryKey. With $kind only 2.0 files of that kind count.
	 *
	 * @return array<string,mixed>|null
	 */
	public function latest( string $format, ?string $outletKey = null, string $primaryKey = '', ?string $kind = null ): ?array {
		foreach ( $this->entries() as $entry ) {
			if ( ( $entry['format'] ?? '' ) !== $format || ( null !== $kind && ( $entry['kind'] ?? '' ) !== $kind ) ) {
				continue;
			}
			if ( null === $outletKey || self::belongsTo( $entry, $outletKey, $primaryKey ) ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * File served at latest_{kind}.csv, or (kind '') at the pre-2.0 latest.csv / latest.xml: the goods list (the
	 * services list in a services-only shop); before the first 2.0 run, the old file of the requested format.
	 *
	 * @return array<string,mixed>|null
	 */
	public function current( string $outletKey, string $primaryKey, string $kind, string $format = 'csv' ): ?array {
		if ( '' !== $kind ) {
			return $this->latest( 'csv', $outletKey, $primaryKey, $kind );
		}
		return $this->latest( 'csv', $outletKey, $primaryKey, CsvWriter::GOODS )
			?? $this->latest( 'csv', $outletKey, $primaryKey, CsvWriter::SERVICES )
			?? $this->latest( $format, $outletKey, $primaryKey );
	}

	/** Running number (broj pohrane) for the next file of an outlet and kind. */
	public function nextNumber( string $outletKey, string $kind ): int {
		$max = 0;
		foreach ( $this->entries() as $entry ) {
			if ( ( $entry['outlet'] ?? '' ) === $outletKey && ( $entry['kind'] ?? '' ) === $kind ) {
				$max = max( $max, (int) ( $entry['number'] ?? 0 ) );
			}
		}
		return $max + 1;
	}

	/**
	 * Entries of one outlet, newest first. Entries without an outlet key (pre-1.1 manifests) belong to the primary.
	 *
	 * @param string $outletKey  Outlet key.
	 * @param string $primaryKey Key of the primary outlet.
	 * @return array<int,array<string,mixed>>
	 */
	public function entriesFor( string $outletKey, string $primaryKey ): array {
		return array_values( array_filter( $this->entries(), static fn( array $e ) => self::belongsTo( $e, $outletKey, $primaryKey ) ) );
	}

	/**
	 * Distinct outlet keys present in the manifest (legacy entries mapped to $primaryKey), in first-seen order.
	 *
	 * @return string[]
	 */
	public function outletKeys( string $primaryKey ): array {
		$keys = [];
		foreach ( $this->entries() as $entry ) {
			$key          = (string) ( $entry['outlet'] ?? '' );
			$key          = '' === $key ? $primaryKey : $key;
			$keys[ $key ] = true;
		}
		return array_keys( $keys );
	}

	/**
	 * @param array<string,mixed> $entry Manifest entry.
	 */
	private static function belongsTo( array $entry, string $outletKey, string $primaryKey ): bool {
		$key = (string) ( $entry['outlet'] ?? '' );
		return $key === $outletKey || ( '' === $key && '' !== $primaryKey && $outletKey === $primaryKey );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function entry( string $name ): ?array {
		foreach ( $this->entries() as $entry ) {
			if ( ( $entry['name'] ?? '' ) === $name ) {
				return $entry;
			}
		}
		return null;
	}

	public function has( string $name ): bool {
		return null !== $this->entry( $name );
	}

	public function remove( string $name ): void {
		$entries = $this->entries();
		$kept    = array_values( array_filter( $entries, static fn( array $e ) => ( $e['name'] ?? '' ) !== $name ) );
		if ( count( $kept ) === count( $entries ) ) {
			return;
		}
		$this->entries = $kept;
		$this->save();
	}

	public function save(): void {
		$json = wp_json_encode( [ 'generated' => array_values( $this->entries() ) ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			throw new \RuntimeException( 'Unable to encode price-list manifest.' );
		}
		$this->storage->writeAtomic( $this->path(), $json );
	}

	private function path(): string {
		return $this->storage->path( self::FILENAME );
	}
}
