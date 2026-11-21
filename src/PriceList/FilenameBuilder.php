<?php
/**
 * Price-list file names per Pravilnik o načinu objave cjenika proizvoda (NN 132/2026), čl. 6(3)–(4):
 * {OIB|MB}_{oblik}_{adresa}_{oznaka}_{ROBA|USLUGE}_{broj pohrane}_{GGGGMMDD_SSMM}.csv, or with one list for all
 * outlets {OIB|MB}_ZA_SVA_PRODAJNA_MJESTA_{ROBA|USLUGE}_{broj pohrane}_{GGGGMMDD_SSMM}.csv.
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\PriceList;

use DateTimeImmutable;
use SidrenaCijena\Support\Slugifier;

class FilenameBuilder {

	public const PATTERN     = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(xml|csv)$/';
	public const ALL_OUTLETS = 'ZA_SVA_PRODAJNA_MJESTA';

	/**
	 * @param string            $sellerId  OIB (legal entity) or matični broj (natural person).
	 * @param Outlet|null       $outlet    Null when one list applies to all outlets.
	 * @param string            $kind      CsvWriter::GOODS or CsvWriter::SERVICES.
	 * @param int               $number    Broj pohrane: running number of the published file.
	 * @param DateTimeImmutable $localTime Publication time in the site's timezone.
	 */
	public function build( string $sellerId, ?Outlet $outlet, string $kind, int $number, DateTimeImmutable $localTime ): string {
		$parts = [ $this->part( $sellerId ) ];
		if ( null === $outlet ) {
			$parts[] = self::ALL_OUTLETS;
		} else {
			$parts[] = $this->part( $outlet->form );
			$parts[] = $this->part( $outlet->address );
			$parts[] = $this->part( $outlet->label );
		}
		$parts[] = CsvWriter::SERVICES === $kind ? 'USLUGE' : 'ROBA';
		$parts[] = (string) max( 1, $number );
		$parts[] = $localTime->format( 'Ymd_Hi' );
		return implode( '_', $parts ) . '.csv';
	}

	public static function isValid( string $name ): bool {
		return 1 === preg_match( self::PATTERN, $name );
	}

	private function part( string $value ): string {
		$slug = Slugifier::filenamePart( $value );
		return '' === $slug ? 'na' : $slug;
	}
}
