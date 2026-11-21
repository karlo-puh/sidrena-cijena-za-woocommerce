<?php
/** Large output test: real CSV writer, 100,000 rows, no catalogue-sized PHP array. */

use SidrenaCijena\PriceList\CsvWriter;
use SidrenaCijena\PriceList\Item;
use SidrenaCijena\PriceList\ItemStream;
use SidrenaCijena\PriceList\ServiceItem;

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
			yield $i % 2 ? new ServiceItem( $i, 'Usluga ć & ' . $i, 23.45, false, 25.5 )
				: new Item( $i, 'Proizvod ž & ' . $i, 'SKU-' . $i, 'Brand', 1.0, 'kom', 23.45, false, 23.45, '', '3850000000000', 25.5 );
		}
	} )(), 0 );
	$writer = new CsvWriter();
	foreach ( CsvWriter::KINDS as $kind ) {
		$written = $writer->write( $rows, $kind, $dir . '/output-' . $kind . '.csv' );
		$fh      = fopen( $dir . '/output-' . $kind . '.csv', 'rb' );
		fgetcsv( $fh, 0, ';' );
		$actual = 0;
		while ( false !== fgetcsv( $fh, 0, ';' ) ) { ++$actual; }
		fclose( $fh );
		if ( 50000 !== $written || 50000 !== $actual ) { throw new RuntimeException( 'Row count mismatch in the ' . $kind . ' list.' ); }
	}
	$growth = memory_get_peak_usage( true ) - $baseline;
	if ( $growth > 16 * 1024 * 1024 ) { throw new RuntimeException( 'Writer memory grew by more than 16 MiB.' ); }
	WP_CLI::success( sprintf( '100,000 rows verified in the goods and services lists; additional peak memory %.1f MiB; %.2f seconds (local test, not hosting benchmark).', $growth / 1048576, microtime( true ) - $start ) );
} finally {
	foreach ( glob( $dir . '/*' ) as $path ) { unlink( $path ); }
	rmdir( $dir );
}
