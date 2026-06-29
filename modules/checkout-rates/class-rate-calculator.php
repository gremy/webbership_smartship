<?php
declare(strict_types=1);

namespace Ovride\Smartship\Modules\CheckoutRates;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rate-building: a SmartShip /cost `costs[]` array + method config -> WC rate rows.
 *
 * @package Ovride\Smartship\Modules\CheckoutRates
 */
final class RateCalculator {

  public static function build_rates( array $costs, array $config ): array {
    $allow  = array_map( 'intval', (array) ( $config['couriers'] ?? [] ) );
    $labels = (array) ( $config['labels'] ?? [] );
    $rates  = [];
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
      if ( ! empty( $allow ) && ! in_array( $cid, $allow, true ) ) {
        continue;
      }
      $label = ( isset( $labels[ $cid ] ) && '' !== (string) $labels[ $cid ] )
        ? (string) $labels[ $cid ]
        : (string) ( $c['courier_name'] ?? ( 'Courier ' . $cid ) );
      $rates[] = [
        'id'         => 'ovride_smartship:' . $cid,
        'label'      => $label,
        'cost'       => self::apply_markup( (float) ( $c['cost'] ?? 0 ), $config ),
        'courier_id' => $cid,
      ];
    }
    return $rates;
  }

  public static function fallback_rate( array $config ): array {
    return [
      'id'    => 'ovride_smartship:fallback',
      'label' => (string) ( $config['fallback_title'] ?? 'Shipping' ),
      'cost'  => max( 0.0, (float) ( $config['fallback_amount'] ?? 0 ) ),
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
