<?php
/**
 * One services row of the price list (Pravilnik o načinu objave cjenika proizvoda, NN 132/2026, čl. 3(3)).
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\PriceList;

final class ServiceItem {

	public function __construct(
		public readonly int $productId,
		public readonly string $name,
		public readonly float $price,
		public readonly bool $isSpecialSale,
		public readonly ?float $basePrice,
	) {}
}
