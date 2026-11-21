<?php
/**
 * Writes one price-list file (goods or services) as CSV: UTF-8, ";" separator, columns in the order of
 * Pravilnik o načinu objave cjenika proizvoda (NN 132/2026), čl. 3 i 6.
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\PriceList;

use RuntimeException;
use SidrenaCijena\Support\Money;

class CsvWriter {

	public const GOODS    = 'roba';
	public const SERVICES = 'usluge';
	public const KINDS    = [ self::GOODS, self::SERVICES ];

	private const BOM = "\xEF\xBB\xBF";

	public function __construct( private readonly bool $bom = true ) {}

	/**
	 * @param iterable<Item|ServiceItem> $items Rows of both kinds; only those of $kind are written.
	 * @param string                     $kind  self::GOODS or self::SERVICES.
	 */
	public function write( iterable $items, string $kind, string $path ): int {
		$fh = fopen( $path, 'wb' );
		if ( false === $fh ) {
			throw new RuntimeException( esc_html( sprintf( /* translators: %s: file path */ __( 'Nije moguće pisati u datoteku %s.', 'sidrena-cijena-za-woocommerce' ), $path ) ) );
		}
		try {
			if ( $this->bom && strlen( self::BOM ) !== fwrite( $fh, self::BOM ) ) {
				throw new RuntimeException( 'Unable to write price-list CSV.' );
			}
			$this->line( $fh, (array) apply_filters( 'scwc_price_list_csv_header', $this->header( $kind ), $kind ) );
			$rows = 0;
			foreach ( $items as $item ) {
				if ( self::GOODS === $kind && $item instanceof Item ) {
					$row = $this->goodsRow( $item );
				} elseif ( self::SERVICES === $kind && $item instanceof ServiceItem ) {
					$row = $this->serviceRow( $item );
				} else {
					continue;
				}
				$this->line( $fh, (array) apply_filters( 'scwc_price_list_csv_row', $row, $item ) );
				++$rows;
			}
			if ( ! fflush( $fh ) ) {
				throw new RuntimeException( 'Unable to flush price-list CSV.' );
			}
			return $rows;
		} finally {
			fclose( $fh );
		}
	}

	/**
	 * @return string[]
	 */
	public function header( string $kind ): array {
		return self::SERVICES === $kind
			? [ 'naziv', 'maloprodajna_cijena', 'posebni_oblik_prodaje', 'bazna_cijena' ]
			: [ 'naziv', 'sifra', 'marka', 'neto_kolicina', 'jedinica_mjere', 'maloprodajna_cijena', 'posebni_oblik_prodaje', 'cijena_za_jedinicu_mjere', 'barkod', 'ean_barkod', 'bazna_cijena' ];
	}

	/**
	 * @return string[]
	 */
	private function goodsRow( Item $item ): array {
		return [
			$item->name,
			$item->sku,
			$item->brand,
			null === $item->netQuantity ? '' : rtrim( rtrim( Money::toDecimal( $item->netQuantity, 3 ), '0' ), '.' ),
			$item->unit,
			$this->money( $item->price ),
			$item->isSpecialSale ? 'da' : 'ne',
			$this->money( $item->unitPrice ),
			$item->barcode,
			$item->ean,
			$this->money( $item->basePrice ),
		];
	}

	/**
	 * @return string[]
	 */
	private function serviceRow( ServiceItem $item ): array {
		return [ $item->name, $this->money( $item->price ), $item->isSpecialSale ? 'da' : 'ne', $this->money( $item->basePrice ) ];
	}

	/**
	 * @param resource $fh     Open file handle.
	 * @param string[] $fields Row.
	 */
	private function line( $fh, array $fields ): void {
		if ( false === fputcsv( $fh, array_map( 'strval', $fields ), ';', '"', '', "\n" ) ) {
			throw new RuntimeException( 'Unable to write price-list CSV row.' );
		}
	}

	private function money( ?float $amount ): string {
		return null === $amount ? '' : Money::toDecimal( $amount );
	}
}
