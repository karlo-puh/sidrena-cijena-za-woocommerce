<?php
/**
 * "−X % (?)" next to the struck-through 30-day low (row above the price): the 30-day low on hover, tap or focus.
 * Used when Prikaz → "Prikaz najniže cijene u 30 dana" is "Oblačić". Override by copying to
 * yourtheme/sidrena-cijena/price-tooltip.php.
 *
 * Available: $badge (BadgeData, with ->omnibus), $context (string), $display (array).
 *
 * @package SidrenaCijena
 * @var \SidrenaCijena\Display\BadgeData $badge
 * @var string                           $context
 * @var array<string,mixed>              $display
 */

defined( 'ABSPATH' ) || exit;

$scwc_label = (string) ( $display['omnibus_label'] ?? '' );
$scwc_text  = (string) ( $badge->omnibus->amountText ?? wp_strip_all_tags( $badge->omnibus->amountHtml ) );
?>
<?php if ( ! empty( $display['show_percent'] ) && null !== $badge->omnibus->percent ) : ?>
<span class="scwc-discount">&minus;<?php echo (int) $badge->omnibus->percent; ?>&nbsp;%</span>
<?php endif; ?>
<span class="scwc-info" tabindex="0" role="button" aria-label="<?php echo esc_attr( $scwc_label . ': ' . $scwc_text ); ?>"><span class="scwc-info__icon" aria-hidden="true">?</span><span class="scwc-info__tip" role="tooltip"><?php echo esc_html( $scwc_label ); ?>: <strong><?php echo wp_kses_post( $badge->omnibus->amountHtml ); ?></strong></span></span>
