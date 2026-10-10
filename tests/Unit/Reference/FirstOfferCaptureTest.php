<?php
declare(strict_types=1);

namespace SidrenaCijena\Tests\Unit\Reference;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use SidrenaCijena\Product\BarcodeResolver;
use SidrenaCijena\Product\BrandResolver;
use SidrenaCijena\Product\ProductAdapter;
use SidrenaCijena\Reference\CategoryOverrideResolver;
use SidrenaCijena\Reference\FirstOfferCapture;
use SidrenaCijena\Reference\ReferenceDateResolver;
use SidrenaCijena\Reference\ReferencePriceRegistry;
use SidrenaCijena\Reference\ReferencePriceRepository;
use SidrenaCijena\Reference\ReferencePriceType;
use SidrenaCijena\Tests\Support\FixedClock;
use SidrenaCijena\Tests\TestCase;

final class FirstOfferCaptureTest extends TestCase {

	private FirstOfferCapture $capture;
	private FixedClock $clock;
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		\WC_Product::$registry = [];
		$this->clock = new FixedClock( '2026-10-10 22:30:00' ); // October 11 in Zagreb.
		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key = '', bool $single = false ) => '' === $key ? array_map( fn( $v ) => [ $v ], $this->meta[$id] ?? [] ) : ( $this->meta[$id][$key] ?? '' ) );
		Functions\when( 'update_post_meta' )->alias( function ( int $id, string $key, $value ): bool {
			$this->meta[$id][$key] = $value;
			return true;
		} );
		Functions\when( 'delete_post_meta' )->alias( function ( int $id, string $key ): bool {
			unset( $this->meta[$id][$key] );
			return true;
		} );
		$this->capture = new FirstOfferCapture(
			new ReferencePriceRegistry( [ 'anchor' => new ReferencePriceType( 'anchor', 'Cijena', '2026-09-10', [], true, 'SidrenaCijena', 'sidrena_cijena' ) ] ),
			new ReferencePriceRepository( new ReferenceDateResolver( new CategoryOverrideResolver( fn() => [] ) ) ),
			new ProductAdapter( new BrandResolver(), new BarcodeResolver() ),
			$this->clock,
			fn( int $id ) => \WC_Product::$registry[$id] ?? null,
		);
	}

	private function created( \WC_Product $product ): void {
		$this->capture->onInserted( $product->get_id(), (object) [ 'post_type' => $product->is_type( 'variation' ) ? 'product_variation' : 'product' ], false );
	}

	private function reference( int $id ): array {
		return array_intersect_key( $this->meta[$id] ?? [], array_flip( [ '_scwc_ref_anchor_price', '_scwc_ref_anchor_date', '_scwc_ref_anchor_source', '_scwc_ref_anchor_na' ] ) );
	}

	public function test_hooks_cover_wordpress_creation_and_woocommerce_saves(): void {
		Actions\expectAdded( 'wp_insert_post' )->once()->with( \Mockery::type( 'callable' ), 10, 3 );
		Actions\expectAdded( 'woocommerce_after_product_object_save' )->once();
		Actions\expectAdded( 'transition_post_status' )->once();
		Actions\expectAdded( 'shutdown' )->once();
		$this->capture->register();
	}

	public function test_new_crud_product_freezes_regular_price_and_local_date_even_on_sale(): void {
		$product = $this->product( [ 'id' => 1, 'regular_price' => '25', 'sale_price' => '20', 'date_created' => new \WC_DateTime( '2020-01-01' ) ] );
		$this->created( $product );
		$this->capture->onSaved( $product );
		self::assertSame( [ '_scwc_ref_anchor_price' => '25', '_scwc_ref_anchor_source' => 'first_offer', '_scwc_ref_anchor_date' => '2026-10-11' ], $this->reference( 1 ) );
		self::assertSame( '25', $product->get_meta( '_scwc_ref_anchor_price' ), 'Current product must not retain stale meta.' );
		$this->clock->set( '2026-10-15 12:00:00' );
		$product->set_prop( 'regular_price', '40' );
		$this->capture->onSaved( $product );
		self::assertSame( '25', $this->meta[1]['_scwc_ref_anchor_price'] );
		self::assertSame( '2026-10-11', $this->meta[1]['_scwc_ref_anchor_date'] );
	}

	public function test_classic_editor_auto_draft_waits_for_publication_and_final_price(): void {
		$product = $this->product( [ 'id' => 2, 'status' => 'auto-draft', 'regular_price' => '' ] );
		$this->created( $product );
		$product->set_prop( 'status', 'draft' );
		$product->set_prop( 'regular_price', '10' );
		$this->capture->onSaved( $product );
		$this->capture->flush();
		self::assertSame( [], $this->reference( 2 ) );

		$this->clock->set( '2026-10-15 12:00:00' );
		$this->capture->onStatusChanged( 'publish', 'draft', (object) [ 'ID' => 2, 'post_type' => 'product' ] );
		self::assertSame( [], $this->reference( 2 ), 'Status hooks precede the pricing save.' );
		$product->set_prop( 'status', 'publish' );
		$product->set_prop( 'regular_price', '35' );
		$this->capture->onSaved( $product );
		$this->capture->flush();
		self::assertSame( '35', $this->meta[2]['_scwc_ref_anchor_price'] );
		self::assertSame( '2026-10-15', $this->meta[2]['_scwc_ref_anchor_date'] );
	}

	public function test_scheduled_publication_without_wc_save_is_captured_at_shutdown(): void {
		$product = $this->product( [ 'id' => 3, 'status' => 'future', 'regular_price' => '35' ] );
		$this->created( $product );
		$this->capture->onStatusChanged( 'publish', 'future', (object) [ 'ID' => 3, 'post_type' => 'product' ] );
		$product->set_prop( 'status', 'publish' );
		$this->capture->flush();
		self::assertSame( '35', $this->meta[3]['_scwc_ref_anchor_price'] );
	}

	public function test_existing_products_and_preexisting_drafts_are_not_backfilled(): void {
		foreach ( [ 'publish', 'draft' ] as $offset => $status ) {
			$id = 4 + $offset;
			$product = $this->product( [ 'id' => $id, 'status' => $status, 'regular_price' => '50' ] );
			$this->capture->onInserted( $id, (object) [ 'post_type' => 'product' ], true );
			$this->capture->onStatusChanged( 'publish', $status, (object) [ 'ID' => $id, 'post_type' => 'product' ] );
			$product->set_prop( 'status', 'publish' );
			$this->capture->onSaved( $product );
			$this->capture->flush();
			self::assertSame( [], $this->reference( $id ) );
		}
	}

	/** @dataProvider suppliedReferences */
	public function test_manual_imported_and_na_references_are_preserved( array $reference ): void {
		$product = $this->product( [ 'id' => 6, 'regular_price' => '80' ] );
		$this->created( $product );
		$this->meta[6] = array_merge( $this->meta[6], $reference ); // Persisted after WC object was loaded.
		$this->capture->onSaved( $product );
		self::assertSame( $reference, $this->reference( 6 ) );
		$this->meta[6] = [];
		$this->capture->onSaved( $product );
		self::assertSame( [], $this->reference( 6 ), 'Clearing a reference must not reset the first offer.' );
	}

	public static function suppliedReferences(): array {
		return [
			[ [ '_scwc_ref_anchor_price' => '49', '_scwc_ref_anchor_date' => '2026-09-10', '_scwc_ref_anchor_source' => 'import' ] ],
			[ [ '_scwc_ref_anchor_price' => '0', '_scwc_ref_anchor_source' => 'manual' ] ],
			[ [ '_scwc_ref_anchor_na' => '1' ] ],
		];
	}

	public function test_variation_waits_for_parent_publication_and_leaves_old_variations_alone(): void {
		$parent = $this->product( [ 'id' => 10, 'type' => 'variable', 'status' => 'draft', 'children' => [ 11, 12 ] ] );
		$variation = $this->product( [ 'id' => 11, 'type' => 'variation', 'parent_id' => 10, 'regular_price' => '65' ] );
		$this->product( [ 'id' => 12, 'type' => 'variation', 'parent_id' => 10, 'regular_price' => '70' ] );
		$this->created( $parent );
		$this->created( $variation );
		$this->capture->onSaved( $variation );
		self::assertSame( [], $this->reference( 11 ) );
		$parent->set_prop( 'status', 'publish' );
		$this->capture->onSaved( $parent );
		self::assertSame( '65', $this->meta[11]['_scwc_ref_anchor_price'] );
		self::assertSame( [], $this->reference( 10 ) );
		self::assertSame( [], $this->reference( 12 ) );
	}

	public function test_new_variation_on_published_parent_gets_its_own_reference(): void {
		$this->product( [ 'id' => 10, 'type' => 'variable', 'meta' => [ '_scwc_ref_anchor_price' => '20' ] ] );
		$variation = $this->product( [ 'id' => 11, 'type' => 'variation', 'parent_id' => 10, 'regular_price' => '65', 'stock_status' => 'onbackorder' ] );
		$this->created( $variation );
		$this->capture->onSaved( $variation );
		self::assertSame( '65', $this->meta[11]['_scwc_ref_anchor_price'] );
	}

	public function test_published_product_without_price_waits_for_first_priced_save(): void {
		$product = $this->product( [ 'id' => 7, 'regular_price' => '', 'price' => '' ] );
		$this->created( $product );
		$this->capture->onSaved( $product );
		self::assertSame( [], $this->reference( 7 ) );
		$product->set_prop( 'regular_price', '0' );
		$product->set_prop( 'price', '0' );
		$this->capture->onSaved( $product );
		self::assertSame( '0', $this->meta[7]['_scwc_ref_anchor_price'] );
	}

	public function test_republication_cannot_reset_reference(): void {
		$product = $this->product( [ 'id' => 8, 'regular_price' => '25' ] );
		$this->created( $product );
		$this->capture->onSaved( $product );
		$this->meta[8] = [];
		$this->capture->onStatusChanged( 'publish', 'draft', (object) [ 'ID' => 8, 'post_type' => 'product' ] );
		$product->set_prop( 'regular_price', '60' );
		$this->capture->flush();
		self::assertSame( [], $this->reference( 8 ) );
	}

	public function test_copied_pending_marker_does_not_make_an_existing_product_new(): void {
		$product = $this->product( [ 'id' => 9, 'regular_price' => '25' ] );
		$this->meta[9][ FirstOfferCapture::PENDING_KEY ] = '8';
		$this->capture->onSaved( $product );
		self::assertSame( [], $this->reference( 9 ) );
	}

	public function test_future_anchor_date_is_not_backdated_with_the_first_price(): void {
		$this->clock->set( '2026-09-01 12:00:00' );
		$product = $this->product( [ 'id' => 15, 'regular_price' => '25' ] );
		$this->created( $product );
		$this->capture->onSaved( $product );
		$this->clock->set( '2026-10-01 12:00:00' );
		$product->set_prop( 'regular_price', '60' );
		$this->capture->onSaved( $product );
		self::assertSame( [], $this->reference( 15 ) );
	}
}
