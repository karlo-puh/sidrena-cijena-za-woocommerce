<?php
/**
 * What a recorded price change means.
 *
 * @package SidrenaCijena
 */

declare(strict_types=1);

namespace SidrenaCijena\History;

final class Transition {
	public const NONE         = 'none';
	public const FIRST        = 'first';
	public const CHANGED      = 'changed';
	public const SALE_STARTED = 'sale_started';
	public const SALE_LOWERED = 'sale_lowered';
	/** The on-sale price went up: the 30 days before it include the lower price, so a new reference applies. */
	public const SALE_RAISED = 'sale_raised';
	public const SALE_ENDED  = 'sale_ended';
}
