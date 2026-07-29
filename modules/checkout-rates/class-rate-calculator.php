<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\CheckoutRates;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rate-building: a SmartShip /cost `costs[]` array + method config -> WC rate rows.
 *
 * @package Webbership\Smartship\Modules\CheckoutRates
 */
final class RateCalculator {

  public static function build_rates( array $costs, array $config ): array {
    $mode     = 'include' === ( $config['courier_filter_mode'] ?? 'exclude' ) ? 'include' : 'exclude';
    $selected = array_map( 'intval', (array) ( $config['excluded_couriers'] ?? [] ) );
    $labels   = (array) ( $config['labels'] ?? [] );
    $rates    = [];
    foreach ( $costs as $c ) {
      if ( ! is_array( $c ) ) {
        continue;
      }
      $cid = (int) ( $c['courier_id'] ?? 0 );
      if ( $cid <= 0 ) {
        continue;
      }
      // A row without a numeric cost must NOT become a free (0) rate — skip it.
      // If every row is invalid, the empty result triggers the caller's fallback.
      if ( ! isset( $c['cost'] ) || ! is_numeric( $c['cost'] ) ) {
        continue;
      }
      if ( 'exclude' === $mode && in_array( $cid, $selected, true ) ) {
        continue;
      }
      // Include mode with nothing selected must offer everything (an admin who
      // flips the mode before picking couriers must not break checkout).
      if ( 'include' === $mode && [] !== $selected && ! in_array( $cid, $selected, true ) ) {
        continue;
      }
      $label = ( isset( $labels[ $cid ] ) && '' !== (string) $labels[ $cid ] )
        ? (string) $labels[ $cid ]
        : sanitize_text_field( (string) ( $c['courier_name'] ?? ( 'Courier ' . $cid ) ) );
      $rates[] = [
        'id'         => 'webbership_smartship:' . $cid,
        'label'      => $label,
        'cost'       => self::apply_markup( (float) ( $c['cost'] ?? 0 ), $config ),
        'courier_id' => $cid,
      ];
    }
    return $rates;
  }

  /**
   * Weight-aware fallback rate for when SmartShip can't be quoted (down, no key,
   * headless, or an unquotable destination). Priced base + per_kg * package weight
   * so it never undercuts a heavy parcel the way the old flat fallback did.
   */
  public static function fallback_rate( array $config, float $weight_kg ): array {
    $base   = max( 0.0, (float) ( $config['fallback_amount'] ?? 0 ) );
    $per_kg = max( 0.0, (float) ( $config['fallback_per_kg_amount'] ?? 0 ) );
    return [
      'id'    => 'webbership_smartship:fallback',
      'label' => '' !== (string) ( $config['fallback_title'] ?? '' ) ? (string) $config['fallback_title'] : __( 'Shipping', 'webbership-smartship' ),
      'cost'  => round( $base + $per_kg * max( 0.0, $weight_kg ), 2 ),
    ];
  }

  public static function apply_markup( float $cost, array $config ): float {
    $type   = (string) ( $config['markup_type'] ?? 'none' );
    $amount = (float) ( $config['markup_amount'] ?? 0 );
    if ( 'flat' === $type ) {
      $cost += $amount;
    } elseif ( 'percent' === $type ) {
      $cost += $cost * ( $amount / 100 );
    }
    return max( 0.0, round( $cost, 2 ) );
  }
}
