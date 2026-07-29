<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\EasyBox;

use Webbership\Smartship\Modules\CheckoutRates\RateCalculator;

defined( 'ABSPATH' ) || exit;

/**
 * Pure EasyBox config normalization + fallback pricing.
 *
 * Live pricing is now a real `/cost` quote with `content.locker_id` set (see
 * EasyBoxMethod::calculate_shipping() + CostService::costs_for()) — the old
 * SameDay-home-rate × factor heuristic (~20% discount, hand-measured 2026-06-30)
 * is gone now that SmartShip's 2026-07-29 docs expose a real EasyBox line on `/cost`.
 * The fallback rate (used only when no live quote is available) shares
 * RateCalculator's base + per_kg * weight math with the checkout-rates method, so
 * the plugin has one fallback formula, not two.
 *
 * @package Webbership\Smartship\Modules\EasyBox
 */
final class EasyBoxPricing {
  public const METHOD_ID = 'webbership_smartship_easybox';

  /** Normalize the shipping method's instance settings into a price config. */
  public static function config( array $instance ): array {
    return [
      'title'                  => sanitize_text_field( (string) ( $instance['title'] ?? __( 'Ridicare Sameday Point / EasyBox', 'webbership-smartship' ) ) ),
      'fallback_amount'        => max( 0.0, (float) ( $instance['fallback_amount'] ?? 0 ) ),
      'fallback_per_kg_amount' => max( 0.0, (float) ( $instance['fallback_per_kg_amount'] ?? 0 ) ),
      'fallback_title'         => sanitize_text_field( (string) ( $instance['fallback_title'] ?? __( 'Ridicare Sameday Point / EasyBox', 'webbership-smartship' ) ) ),
    ];
  }

  /**
   * The weight-aware fallback rate row, used when no live EasyBox quote is
   * available (no lockers cached, /cost failed, or the courier-12 line is missing).
   */
  public static function fallback( array $config, float $weight_kg = 0.0 ): array {
    return RateCalculator::fallback_rate( $config, $weight_kg );
  }
}
