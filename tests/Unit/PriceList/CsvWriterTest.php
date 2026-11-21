<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\PriceList;

use Brain\Monkey\Filters;
use SidrenaCijena\PriceList\CsvWriter;
use SidrenaCijena\PriceList\Item;
use SidrenaCijena\PriceList\ServiceItem;
use SidrenaCijena\Tests\TestCase;

final class CsvWriterTest extends TestCase {
	private const BOM = "\xEF\xBB\xBF";
	private string $path;

	protected function setUp(): void {
		parent::setUp();
		$this->path = sys_get_temp_dir() . '/scwc-csv-' . uniqid() . '.csv';
	}

	protected function tearDown(): void {
		@unlink( $this->path );
		parent::tearDown();
	}

	private function item( string $name = 'Proizvod', bool $sale = false, ?float $qty = 0.5 ): Item {
		return new Item( 1, $name, 'SKU-1', 'Marka', $qty, 'kg', 12.99, $sale, 25.98, 'INT-7', '3850001234567', 14.99 );
	}

	private function service(): ServiceItem {
		return new ServiceItem( 2, 'Montaža', 40.0, true, null );
	}

	/**
	 * @param iterable<Item|ServiceItem> $items
	 * @return array{0:int,1:string[]} Rows written and lines without BOM.
	 */
	private function write( iterable $items, string $kind, ?CsvWriter $writer = null ): array {
		$rows = ( $writer ?? new CsvWriter() )->write( $items, $kind, $this->path );
		$raw  = (string) file_get_contents( $this->path );
		self::assertStringStartsWith( self::BOM, $raw );
		self::assertStringNotContainsString( "\r", $raw );
		return [ $rows, explode( "\n", rtrim( substr( $raw, 3 ), "\n" ) ) ];
	}

	public function test_goods_file_has_legal_header_and_only_goods_rows(): void {
		[ $rows, $lines ] = $this->write( [ $this->item(), $this->service() ], CsvWriter::GOODS );
		self::assertSame( 'naziv;sifra;marka;neto_kolicina;jedinica_mjere;maloprodajna_cijena;posebni_oblik_prodaje;cijena_za_jedinicu_mjere;barkod;ean_barkod;bazna_cijena', $lines[0] );
		self::assertSame( 'Proizvod;SKU-1;Marka;0.5;kg;12.99;ne;25.98;INT-7;3850001234567;14.99', $lines[1] );
		self::assertCount( 2, $lines );
		self::assertSame( 1, $rows );
	}

	public function test_services_file_has_its_own_header_and_only_service_rows(): void {
		[ $rows, $lines ] = $this->write( [ $this->item(), $this->service() ], CsvWriter::SERVICES );
		self::assertSame( [ 'naziv;maloprodajna_cijena;posebni_oblik_prodaje;bazna_cijena', 'Montaža;40.00;da;' ], $lines );
		self::assertSame( 1, $rows );
	}

	public function test_net_quantity_formatting_and_special_sale(): void {
		[ , $lines ] = $this->write( [ $this->item( 'A', true, 1000.0 ), $this->item( 'B', false, 0.125 ), $this->item( 'C', false, null ) ], CsvWriter::GOODS );
		self::assertStringStartsWith( 'A;SKU-1;Marka;1000;kg;12.99;da;', $lines[1] );
		self::assertStringStartsWith( 'B;SKU-1;Marka;0.125;kg;12.99;ne;', $lines[2] );
		self::assertStringStartsWith( 'C;SKU-1;Marka;;kg;', $lines[3] );
	}

	public function test_bom_can_be_disabled_and_delimiter_is_always_semicolon(): void {
		( new CsvWriter( false ) )->write( [ $this->item() ], CsvWriter::GOODS, $this->path );
		$raw = (string) file_get_contents( $this->path );
		self::assertStringStartsWith( 'naziv;sifra;', $raw );
	}

	public function test_quotes_fields_with_delimiter_quote_and_newline(): void {
		$this->write( [ $this->item( "Kabel; 2m \"crni\"\ndrugi red" ) ], CsvWriter::GOODS );
		$fh = fopen( $this->path, 'r' );
		fseek( $fh, 3 );
		fgetcsv( $fh, 0, ';', '"', '' );
		$row = fgetcsv( $fh, 0, ';', '"', '' );
		fclose( $fh );
		self::assertSame( "Kabel; 2m \"crni\"\ndrugi red", $row[0] );
	}

	public function test_header_and_row_filters(): void {
		Filters\expectApplied( 'scwc_price_list_csv_header' )->once()->with( \Mockery::type( 'array' ), CsvWriter::GOODS )->andReturnUsing( fn( array $h ) => array_merge( $h, [ 'extra' ] ) );
		Filters\expectApplied( 'scwc_price_list_csv_row' )->once()->with( \Mockery::type( 'array' ), \Mockery::type( Item::class ) )->andReturnUsing( fn( array $r ) => array_merge( $r, [ 'X' ] ) );
		[ , $lines ] = $this->write( [ $this->item() ], CsvWriter::GOODS );
		self::assertStringEndsWith( ';bazna_cijena;extra', $lines[0] );
		self::assertStringEndsWith( ';14.99;X', $lines[1] );
	}
}
