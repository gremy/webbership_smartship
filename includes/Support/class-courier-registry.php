<?php
declare(strict_types=1);

namespace Webbership\Smartship\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Display-only courier id -> name registry for the checkout-rates settings UI
 * (the "Couriers to exclude" multiselect labels). NEVER used to filter rates —
 * SmartShip has no couriers-list endpoint, so any courier id the live /cost
 * response returns is offered unless its id is in the instance's exclusion list,
 * known or not.
 *
 * @package Webbership\Smartship\Support
 */
final class CourierRegistry {

  /** Learned id => name pairs seen in live /cost responses, autoload off. */
  private const OPTION = 'webbership_smartship_known_couriers';

  /** Seed of couriers we already know about, for a useful list on a fresh install. */
  private const SEED = [
    1  => 'Cargus',
    2  => 'SameDay',
    3  => 'FanCourier',
    5  => 'DragonStar',
    6  => 'DPD',
    14 => 'PTT Express',
    16 => 'SmartShip Delivery',
    19 => 'FedEx',
  ];

  /** Seed merged with learned names (learned wins on id collisions — it's live data). */
  public static function known(): array {
    $learned = get_option( self::OPTION, [] );
    return ( is_array( $learned ) ? $learned : [] ) + self::SEED;
  }

  /**
   * Learn courier_id => courier_name pairs from a live /cost `costs[]` response.
   * Writes the option only when something actually changed, to avoid a DB write
   * on every quote.
   */
  public static function learn( array $costs ): void {
    $learned = get_option( self::OPTION, [] );
    if ( ! is_array( $learned ) ) {
      $learned = [];
    }
    $changed = false;
    foreach ( $costs as $c ) {
      if ( ! is_array( $c ) ) {
        continue;
      }
      $id = (int) ( $c['courier_id'] ?? 0 );
      if ( $id <= 0 ) {
        continue;
      }
      $name = sanitize_text_field( (string) ( $c['courier_name'] ?? '' ) );
      if ( '' === $name ) {
        continue;
      }
      if ( ! array_key_exists( $id, $learned ) || $learned[ $id ] !== $name ) {
        $learned[ $id ] = $name;
        $changed = true;
      }
    }
    if ( $changed ) {
      update_option( self::OPTION, $learned, false );
    }
  }
}
