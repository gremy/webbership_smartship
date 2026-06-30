<?php
declare(strict_types=1);

namespace Webbership\Smartship\Support;

use Webbership\Smartship\Api\SmartShipClient;
use Webbership\Smartship\Settings\Settings;
use Webbership\Smartship\Modules\Awb\Data\AwbPayload;

defined( 'ABSPATH' ) || exit;

/**
 * Shared, cached SameDay-style /cost fetch for a checkout package's RO destination.
 *
 * Extracted from the live-rates ShippingMethod so the EasyBox method reuses the SAME
 * cached transient (rate cache + failure-cache) — one /cost round-trip, not two.
 *
 * @package Webbership\Smartship\Support
 */
final class CostService {

  /**
   * Cached /cost costs[] for the package's RO destination, or null on any short-circuit
   * (non-resolvable city, failure-cache hot, no sender, /cost fail, non-array costs).
   *
   * @param array          $package WooCommerce shipping package (destination + contents).
   * @param SmartShipClient $client  SmartShip client (duck-typed in tests).
   * @return array|null The normalized costs[] (each row {courier_id,courier_name,cost,...}) or null.
   */
  public static function costs_for( array $package, $client ): ?array {
    $dest = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : [];

    $resolved = ( new CityResolver( $client, SmartShipClient::RATE_TIMEOUT ) )->resolve( (string) ( $dest['state'] ?? '' ), (string) ( $dest['city'] ?? '' ) );
    if ( empty( $resolved['city_id'] ) ) {
      return null;
    }

    $city_id = (int) $resolved['city_id'];
    $weight  = (int) ceil( max( 1.0, self::package_weight( $package ) ) );

    // Validate the sender BEFORE the caches (Phase 3 order): a missing/invalid
    // sender must yield fallback even when the rate cache for this city is hot.
    $sender = self::sender_block( $client );
    if ( empty( $sender ) ) {
      return null;
    }

    $key    = 'webbership_ss_rate_' . md5( $city_id . '|' . $weight . '|' . Settings::sender_id() . '|' . Settings::api_key() );
    $cached = get_transient( $key );
    if ( is_array( $cached ) ) {
      return $cached;
    }
    if ( get_transient( 'webbership_ss_rate_fail' ) ) {
      return null;
    }

    $body = [
      'recipient' => [ 'name' => 'Estimate', 'address' => (string) ( $dest['address'] ?? '' ), 'email' => 'estimate@example.com', 'city' => $city_id, 'phone' => '0700000000', 'country' => 'RO', 'sector' => '0' ],
      'sender'    => $sender,
      'content'   => [ 'package_content' => 'Estimate', 'parcels' => 1, 'weight' => $weight, 'cash_on_delivery' => 0, 'length' => 10, 'width' => 10, 'height' => 10 ],
    ];
    $res = $client->cost( $body, SmartShipClient::RATE_TIMEOUT );
    if ( empty( $res['ok'] ) ) {
      set_transient( 'webbership_ss_rate_fail', 1, MINUTE_IN_SECONDS );
      return null;
    }
    $costs = $res['costs'] ?? ( $res['response']['costs'] ?? [] );
    // A malformed ok-response (costs not an array) must fall back, not fatal a downstream array consumer.
    if ( ! is_array( $costs ) ) {
      set_transient( 'webbership_ss_rate_fail', 1, MINUTE_IN_SECONDS );
      return null;
    }
    set_transient( $key, $costs, 10 * MINUTE_IN_SECONDS );
    return $costs;
  }

  /** The matching courier's cost as a float, or null if that courier isn't in costs[]. */
  public static function courier_cost( array $costs, int $courier_id ): ?float {
    foreach ( $costs as $c ) {
      if ( is_array( $c ) && (int) ( $c['courier_id'] ?? 0 ) === $courier_id && isset( $c['cost'] ) ) {
        return (float) $c['cost'];
      }
    }
    return null;
  }

  /** Sender block from the configured sender, cached a day to stay off the checkout hot path. */
  private static function sender_block( $client ): array {
    $id = Settings::sender_id();
    if ( $id <= 0 ) {
      return [];
    }
    $tk    = 'webbership_ss_sender_block_' . $id;
    $block = get_transient( $tk );
    if ( is_array( $block ) ) {
      return $block;
    }
    $res = $client->get_senders( SmartShipClient::RATE_TIMEOUT );
    foreach ( (array) ( $res['senders'] ?? [] ) as $s ) {
      if ( (int) ( $s['id'] ?? 0 ) === $id ) {
        $block = AwbPayload::sender_from_account( $s );
        // A usable sender block needs at least a name and a (nonzero int) city id;
        // an incomplete one would make /cost fail anyway, so reject (and don't cache) it.
        if ( empty( $block['name'] ) || empty( $block['city'] ) ) {
          return [];
        }
        set_transient( $tk, $block, DAY_IN_SECONDS );
        return $block;
      }
    }
    return [];
  }

  private static function package_weight( array $package ): float {
    $weight = 0.0;
    foreach ( (array) ( $package['contents'] ?? [] ) as $item ) {
      $product = isset( $item['data'] ) && is_object( $item['data'] ) ? $item['data'] : null;
      $qty     = (int) ( $item['quantity'] ?? 1 );
      if ( $product && method_exists( $product, 'get_weight' ) && '' !== (string) $product->get_weight() ) {
        $weight += (float) $product->get_weight() * max( 1, $qty );
      }
    }
    return $weight;
  }
}
