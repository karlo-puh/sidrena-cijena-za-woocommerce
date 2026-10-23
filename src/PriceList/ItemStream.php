<?php
/**
 * Replayable rows on disk. Only one product is decoded at a time.
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\PriceList;

use RuntimeException;

/** @implements \IteratorAggregate<int,Item|ServiceItem> */
final class ItemStream implements \IteratorAggregate {

	public function __construct( private readonly string $path ) {}

	/**
	 * Truncate an interrupted append back to its committed offset before retrying.
	 *
	 * @param iterable<Item|ServiceItem> $items Rows for this batch.
	 */
	public function append( iterable $items, int $offset ): int {
		$fh = fopen( $this->path, 'c+b' );
		if ( false === $fh ) {
			throw new RuntimeException( 'Unable to open price-list rows.' );
		}
		try {
			$stat = fstat( $fh );
			if ( false === $stat || $stat['size'] < $offset || ! ftruncate( $fh, $offset ) || 0 !== fseek( $fh, $offset ) ) {
				throw new RuntimeException( 'Incomplete price-list checkpoint.' );
			}
			foreach ( $items as $item ) {
				$json = json_encode(
					[
						'service' => $item instanceof ServiceItem,
						'row'     => get_object_vars( $item ),
					],
					JSON_THROW_ON_ERROR
				) . "\n";
				if ( strlen( $json ) !== fwrite( $fh, $json ) ) {
					throw new RuntimeException( 'Unable to write price-list rows.' );
				}
			}
			$end = ftell( $fh );
			if ( false === $end || ! fflush( $fh ) ) {
				throw new RuntimeException( 'Unable to flush price-list rows.' );
			}
			return $end;
		} finally {
			fclose( $fh );
		}
	}

	/** @return \Generator<int,Item|ServiceItem> */
	public function getIterator(): \Generator {
		$fh = fopen( $this->path, 'rb' );
		if ( false === $fh ) {
			throw new RuntimeException( 'Missing price-list rows.' );
		}
		try {
			while ( false !== ( $line = fgets( $fh ) ) ) {
				$data = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
				yield $data['service'] ? new ServiceItem( ...$data['row'] ) : new Item( ...$data['row'] );
			}
			if ( ! feof( $fh ) ) {
				throw new RuntimeException( 'Unable to read price-list rows.' );
			}
		} finally {
			fclose( $fh );
		}
	}
}
