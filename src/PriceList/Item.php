<?php
/**
 * One goods row of the price list, in the order of Pravilnik o načinu objave cjenika proizvoda (NN 132/2026), čl. 3(2).
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\PriceList;

final class Item {

	public function __construct(
		public readonly int $productId,
		public readonly string $name,
		public readonly string $sku,
		public readonly string $brand,
		public readonly ?float $netQuantity,
		public readonly string $unit,
		public readonly float $price,
		public readonly bool $isSpecialSale,
		public readonly ?float $unitPrice,
		public readonly string $barcode,
		public readonly string $ean,
		public readonly ?float $basePrice,
	) {}
}
