<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\Admin;

use SidrenaCijena\Admin\Fields;
use SidrenaCijena\Settings\Defaults;
use SidrenaCijena\Tests\TestCase;

final class FieldsTest extends TestCase {
	/**
	 * Leaf paths of the defaults; list arrays (formats, category_overrides) count as leaves.
	 *
	 * @param array<string,mixed> $node
	 * @return string[]
	 */
	private function leafPaths( array $node, string $prefix = '' ): array {
		$out = [];
		foreach ( $node as $key => $value ) {
			$path = '' === $prefix ? (string) $key : "{$prefix}.{$key}";
			if ( is_array( $value ) && ! array_is_list( $value ) ) {
				$out = array_merge( $out, $this->leafPaths( $value, $path ) );
			} else {
				$out[] = $path;
			}
		}
		return $out;
	}

	public function test_every_default_path_has_exactly_one_definition_and_no_unknown_paths(): void {
		// outlet.storage_number is kept only for saved 1.x data; 2.0 numbers files itself (broj pohrane).
		$expected = array_values( array_diff( $this->leafPaths( Defaults::all() ), [ 'outlet.storage_number' ] ) );
		$defined  = array_map( static fn( array $d ) => $d['path'], Fields::all() );
		sort( $expected );
		$sorted = $defined;
		sort( $sorted );
		self::assertSame( $expected, $sorted );
		self::assertSame( count( $defined ), count( array_unique( $defined ) ) );
	}

	public function test_tabs_partition_all_definitions_by_section(): void {
		$all = 0;
		foreach ( Fields::TABS as $tab => $label ) {
			$defs = Fields::forTab( $tab );
			$all += count( $defs );
			foreach ( $defs as $def ) {
				self::assertContains( explode( '.', $def['path'] )[0], Fields::sectionsForTab( $tab ), $def['path'] );
				self::assertNotSame( '', $def['label'] );
				self::assertContains( $def['type'], [ 'text', 'number', 'checkbox', 'select', 'time', 'date', 'datetime-local', 'multicheck', 'textarea', 'hidden', 'readonly', 'category_overrides', 'outlets' ] );
			}
		}
		self::assertSame( count( Fields::all() ), $all );
		self::assertSame( [], Fields::forTab( 'nope' ) );
	}

	public function test_enum_settings_are_selects_or_multicheck_with_all_allowed_options(): void {
		$byPath = [];
		foreach ( Fields::all() as $def ) {
			$byPath[ $def['path'] ] = $def;
		}
		foreach ( Defaults::enums() as $path => $values ) {
			self::assertContains( $byPath[ $path ]['type'], [ 'select', 'multicheck' ], $path );
			self::assertSame( $values, array_keys( $byPath[ $path ]['options'] ), $path );
		}
		self::assertArrayNotHasKey( 'price_list.formats', $byPath, 'CSV only' );
		self::assertArrayNotHasKey( 'price_list.tax_mode', $byPath, 'list prices always include tax' );
		self::assertSame( 'text', $byPath['price_list.seller_id']['type'] );
		self::assertSame( 'checkbox', $byPath['price_list.shared_list']['type'] );
		self::assertSame( 'textarea', $byPath['display.format']['type'] );
		self::assertSame( 'textarea', $byPath['display.format_compact']['type'] );
		self::assertSame( 'category_overrides', $byPath['reference_prices.anchor.category_overrides']['type'] );
		self::assertSame( 'hidden', $byPath['reference_prices.anchor.enabled']['type'], 'anchor is always enabled' );
		self::assertSame( 'checkbox', $byPath['reference_prices.base.enabled']['type'] );
		self::assertSame( 'readonly', $byPath['reference_prices.anchor.auto_snapshot_done']['type'] );
		self::assertSame( 'datetime-local', $byPath['reference_prices.anchor.auto_snapshot_at']['type'] );
		self::assertSame( 15, $byPath['price_list.retention_days']['min'] );
		self::assertSame( 'time', $byPath['price_list.generate_time']['type'] );
		self::assertStringContainsString( '2. 5. 2025.', $byPath['reference_prices.anchor.date']['description'] );
	}

	public function test_input_name_and_id_derive_from_dotted_path(): void {
		self::assertSame( 'scwc_settings[outlet][address]', Fields::inputName( 'outlet.address' ) );
		self::assertSame( 'scwc_settings[reference_prices][anchor][date]', Fields::inputName( 'reference_prices.anchor.date' ) );
		self::assertSame( 'scwc_settings_reference_prices_anchor_date', Fields::inputId( 'reference_prices.anchor.date' ) );
	}
}
