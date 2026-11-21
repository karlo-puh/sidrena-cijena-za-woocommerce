<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\PriceList;

use DateTimeImmutable;
use DateTimeZone;
use SidrenaCijena\PriceList\CsvWriter;
use SidrenaCijena\PriceList\FilenameBuilder;
use SidrenaCijena\PriceList\Outlet;
use SidrenaCijena\Tests\TestCase;

final class FilenameBuilderTest extends TestCase {
	private function time(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-10-01 06:00:12', new DateTimeZone( 'Europe/Zagreb' ) );
	}

	public function test_builds_parts_in_order_with_kind_number_and_local_minutes(): void {
		$outlet = new Outlet( 'webshop', 'Ulica 1, 10000 Zagreb', 'WEB1', '1', 'Trgovina', 'https://example.hr/' );
		$name   = ( new FilenameBuilder() )->build( '12345678901', $outlet, CsvWriter::GOODS, 7, $this->time() );
		self::assertSame( '12345678901_webshop_ulica-1-10000-zagreb_web1_ROBA_7_20261001_0600.csv', $name );
		self::assertTrue( FilenameBuilder::isValid( $name ) );
	}

	public function test_services_kind_and_one_list_for_all_outlets(): void {
		$name = ( new FilenameBuilder() )->build( '12345678901', null, CsvWriter::SERVICES, 2, $this->time() );
		self::assertSame( '12345678901_ZA_SVA_PRODAJNA_MJESTA_USLUGE_2_20261001_0600.csv', $name );
		self::assertTrue( FilenameBuilder::isValid( $name ) );
	}

	public function test_transliterates_diacritics_and_empty_parts_become_na(): void {
		$outlet = new Outlet( '', 'Trg Ivana Pavla II 3, Šibenik', 'Đuro & Sons', '', '', '' );
		self::assertSame(
			'1_na_trg-ivana-pavla-ii-3-sibenik_duro-sons_ROBA_1_20261001_0600.csv',
			( new FilenameBuilder() )->build( '1', $outlet, CsvWriter::GOODS, 0, $this->time() )
		);
	}

	public function test_pattern_validation(): void {
		self::assertTrue( FilenameBuilder::isValid( 'a_b_20261001_060012.csv' ) );
		self::assertTrue( FilenameBuilder::isValid( 'Upper_ROBA.xml' ) );
		self::assertFalse( FilenameBuilder::isValid( '../etc/passwd.xml' ) );
		self::assertFalse( FilenameBuilder::isValid( 'manifest.json' ) );
		self::assertFalse( FilenameBuilder::isValid( '.hidden.xml' ) );
		self::assertFalse( FilenameBuilder::isValid( 'a b.csv' ) );
	}
}
