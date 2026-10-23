<?php
/**
 * File storage for generated price lists (uploads/scwc-cjenik with a tmp/ staging dir).
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\PriceList;

use RuntimeException;

class Storage {

	public const SUBDIR = 'scwc-cjenik';

	public function __construct(
		private readonly string $dir,
		private readonly string $url,
	) {}

	public static function fromUploads(): self {
		$uploads = wp_upload_dir( null, false );
		return new self(
			rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . self::SUBDIR,
			rtrim( (string) $uploads['baseurl'], '/' ) . '/' . self::SUBDIR,
		);
	}

	/** Create the directory layout (idempotent). */
	public function ensure(): void {
		foreach ( [ $this->dir, $this->dir . '/tmp' ] as $dir ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				throw new RuntimeException( esc_html( sprintf( /* translators: %s: directory path */ __( 'Nije moguće stvoriti mapu %s.', 'sidrena-cijena-za-woocommerce' ), $dir ) ) );
			}
		}
		$index = $this->dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
	}

	/** Checked, atomic replacement. Callers serialize writes with the generation lock. */
	public function writeAtomic( string $path, string $contents ): void {
		$tmp = $path . '.new';
		try {
			if ( strlen( $contents ) !== file_put_contents( $tmp, $contents ) || ! rename( $tmp, $path ) ) {
				throw new RuntimeException( 'Unable to save price-list state.' );
			}
		} finally {
			if ( is_file( $tmp ) ) {
				unlink( $tmp );
			}
		}
	}

	/** @return resource|null A process-owned lock, released automatically even after a fatal error. */
	public function lock() {
		$this->ensure();
		$handle = fopen( $this->path( 'generation.lock' ), 'c' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Unable to open price-list lock.' );
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return null;
		}
		return $handle;
	}

	public function dir(): string {
		return $this->dir;
	}

	public function tmpPath( string $name ): string {
		return $this->dir . '/tmp/' . $name;
	}

	public function path( string $name ): string {
		return $this->dir . '/' . $name;
	}

	/** Atomically move a finished tmp file to its final name. */
	public function publish( string $name ): void {
		if ( ! rename( $this->tmpPath( $name ), $this->path( $name ) ) ) {
			throw new RuntimeException( esc_html( sprintf( /* translators: %s: file name */ __( 'Nije moguće objaviti datoteku %s.', 'sidrena-cijena-za-woocommerce' ), $name ) ) );
		}
	}

	public function exists( string $name ): bool {
		return is_file( $this->path( $name ) );
	}

	public function size( string $name ): int {
		if ( ! $this->exists( $name ) ) {
			return 0;
		}
		$size = filesize( $this->path( $name ) );
		return false === $size ? 0 : $size;
	}

	public function delete( string $name ): void {
		if ( $this->exists( $name ) ) {
			unlink( $this->path( $name ) );
		}
	}

	public function url( string $name ): string {
		return rtrim( $this->url, '/' ) . '/' . rawurlencode( $name );
	}

	/**
	 * Published price-list file names (basenames matching FilenameBuilder::PATTERN), sorted.
	 *
	 * @return string[]
	 */
	public function listFiles(): array {
		if ( ! is_dir( $this->dir ) ) {
			return [];
		}
		$names = [];
		foreach ( scandir( $this->dir ) ?: [] as $entry ) {
			if ( FilenameBuilder::isValid( $entry ) && is_file( $this->dir . '/' . $entry ) ) {
				$names[] = $entry;
			}
		}
		sort( $names, SORT_STRING );
		return $names;
	}
}
