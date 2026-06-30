<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\EasyBox;

defined( 'ABSPATH' ) || exit;

/**
 * Pure EasyBox price math.
 *
 * EasyBox ≈ the SameDay home rate × a factor (default 0.80 — a ~20% discount
 * measured live against SmartShip's own estimate; see the EasyBox pricing model
 * in docs/plans/2026-06-30-webbership-smartship-easybox-hybrid.md). No WP/DB here
 * so it is unit-testable standalone.
 *
 * @package Webbership\Smartship\Modules\EasyBox
 */
final class EasyBoxPricing {
  public const DEFAULT_FACTOR = 0.80;
  public const METHOD_ID      = 'webbership_smartship_easybox';

  /**
   * Normalize the shipping method's instance settings into a price config.
   * `easybox_factor` is stored as a percentage (80 = 80% of SameDay) and clamped
   * to a sane [10%, 300%] band so a typo can't produce a 0 or absurd price.
   */
  public static function config( array $instance ): array {
    // A blank or non-numeric field means "not set" → default, NOT 0% (which would
    // clamp to 10% and badly under-charge). An explicit numeric '0' still clamps.
    $raw    = isset( $instance['easybox_factor'] ) ? trim( (string) $instance['easybox_factor'] ) : '';
    $pct    = ( '' !== $raw && is_numeric( $raw ) ) ? (float) $raw : self::DEFAULT_FACTOR * 100;
    $factor = max( 0.10, min( 3.00, $pct / 100 ) );
    return [
      'title'          => sanitize_text_field( (string) ( $instance['title'] ?? __( 'EasyBox locker', 'webbership-smartship' ) ) ),
      'factor'         => $factor,
      'fallback'       => max( 0.0, (float) ( $instance['fallback_amount'] ?? 0 ) ),
      'fallback_title' => sanitize_text_field( (string) ( $instance['fallback_title'] ?? __( 'EasyBox locker', 'webbership-smartship' ) ) ),
    ];
  }

  /** EasyBox price = SameDay home cost × factor (never below 0). */
  public static function price( float $sameday_cost, array $config ): float {
    return round( max( 0.0, $sameday_cost ) * (float) $config['factor'], 2 );
  }

  /** The flat fallback rate row, used when no live SameDay rate is available. */
  public static function fallback( array $config ): array {
    return [
      'id'    => self::METHOD_ID,
      'label' => $config['fallback_title'],
      'cost'  => (float) $config['fallback'],
    ];
  }
}
