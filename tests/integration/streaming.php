<?php
/** Large output test: real writers, 100,000 rows, no catalogue-sized PHP array. */

use SidrenaCijena\PriceList\CsvWriter;
use SidrenaCijena\PriceList\Item;
use SidrenaCijena\PriceList\ItemStream;
use SidrenaCijena\PriceList\Outlet;
use SidrenaCijena\PriceList\ServiceItem;
use SidrenaCijena\PriceList\XmlWriter;
use SidrenaCijena\Reference\ReferencePriceRegistry;
use SidrenaCijena\Settings\Settings;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'http://scwc-test.invalid' !== home_url() ) {
	exit( 'Run only in the disposable scwc-test environment.' );
}
$dir = sys_get_temp_dir() . '/scwc-stream-' . bin2hex( random_bytes( 6 ) );
mkdir( $dir );
$count = 100000;
$start = microtime( true );
$baseline = memory_get_usage( true );
if ( function_exists( 'memory_reset_peak_usage' ) ) { memory_reset_peak_usage(); }
try {
	$rows = new ItemStream( $dir . '/rows.ndjson' );
	$rows->append( ( static function () use ( $count ) {
		for ( $i = 0; $i < $count; ++$i ) {
			$refs = [ 'anchor' => [ 'amount' => 25.5, 'date' => '2026-09-10' ] ];
			yield $i % 2 ? new ServiceItem( 'Usluga ć & ' . $i, 23.45, false, '', $refs )
				: new Item( 'Proizvod ž & ' . $i, 'SKU-' . $i, 'Brand', 'kom', null, 23.45, false, '', $refs, null, '', true, 'https://example.invalid/' . $i );
		}
	} )(), 0 );
	$outlet = new Outlet( 'webshop', 'Test 1', 'WEB', '1', 'Test', home_url() );
	$types = array_values( ReferencePriceRegistry::fromSettings( Settings::fromOption() )->enabled() );
	foreach ( [ 'csv' => new CsvWriter(), 'xml' => new XmlWriter() ] as $format => $writer ) {
		$stats = $writer->write( $rows, $outlet, new DateTimeImmutable(), $dir . '/output.' . $format, $types );
		if ( 50000 !== $stats->products || 50000 !== $stats->services ) { throw new RuntimeException( 'Incorrect output statistics.' ); }
	}
	$fh = fopen( $dir . '/output.csv', 'rb' );
	fgetcsv( $fh, 0, ';' );
	$actual = 0;
	while ( false !== fgetcsv( $fh, 0, ';' ) ) { ++$actual; }
	fclose( $fh );
	if ( $actual !== $count ) { throw new RuntimeException( 'CSV row count mismatch.' ); }
	$xml = new XMLReader();
	$xml->open( $dir . '/output.xml' );
	$products = $services = 0;
	while ( $xml->read() ) {
		if ( XMLReader::ELEMENT === $xml->nodeType ) {
			if ( 'Proizvod' === $xml->name ) { ++$products; }
			if ( 'Usluga' === $xml->name ) { ++$services; }
		}
	}
	$xml->close();
	if ( 50000 !== $products || 50000 !== $services ) { throw new RuntimeException( 'XML row count mismatch.' ); }
	$growth = memory_get_peak_usage( true ) - $baseline;
	if ( $growth > 16 * 1024 * 1024 ) { throw new RuntimeException( 'Writer memory grew by more than 16 MiB.' ); }
	WP_CLI::success( sprintf( '100,000 rows verified in both formats; additional peak memory %.1f MiB; %.2f seconds (local test, not hosting benchmark).', $growth / 1048576, microtime( true ) - $start ) );
} finally {
	foreach ( glob( $dir . '/*' ) as $path ) { unlink( $path ); }
	rmdir( $dir );
}
