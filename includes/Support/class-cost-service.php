<?php
declare(strict_types=1);

namespace Webbership\Smartship\Support;

use Webbership\Smartship\Api\SmartShipClient;
use Webbership\Smartship\Settings\Settings;
use Webbership\Smartship\Modules\Awb\Data\AwbPayload;

defined( 'ABSPATH' ) || exit;

/**
 * Shared, cached SameDay-style /cost fetch for a checkout package's destination
 * (Romania or international).
 *
 * Extracted from the live-rates ShippingMethod so both methods share this one
 * implementation. The EasyBox method calls it with a locker id, which prices
 * ONLY the SameDay EasyBox line for that locker and caches it under its own
 * locker-scoped key (see the $locker_id doc below) — a separate /cost round-trip
 * and cache entry from the locker-less quote used by the live-rates method.
 *
 * @package Webbership\Smartship\Support
 */
final class CostService {

  /**
   * Cached /cost costs[] for the package's destination, or null on any short-circuit
   * (non-resolvable city, failure-cache hot, no sender, /cost fail, non-array costs).
   *
   * RO destinations are resolved to SmartShip's numeric city id via CityResolver
   * (SmartShip's geolocation lookup only covers Romania). Non-RO destinations pass
   * the WooCommerce address fields straight through — SmartShip's exact contract
   * for non-RO recipients isn't documented, so this is a best-effort shape; a
   * rejection from the API is just a failed /cost call, which already falls back.
   *
   * Passing $locker_id > 0 quotes the SameDay EasyBox line for that locker instead
   * (content.locker_id set — see the EasyBox delivery flow in
   * docs/reference/smartship-api.md). Per the API, a locker-scoped /cost call
   * returns ONLY the courier-12 line, so this is a separate cache entry (the locker
   * id is folded into the cache-key hash) from the locker-less quote for the same
   * destination/weight — never confuse the two.
   *
   * @param array          $package   WooCommerce shipping package (destination + contents).
   * @param SmartShipClient $client   SmartShip client (duck-typed in tests).
   * @param int            $locker_id EasyBox locker id to quote, or 0 for the normal (non-locker) quote.
   * @return array|null The normalized costs[] (each row {courier_id,courier_name,cost,...}) or null.
   */
  public static function costs_for( array $package, $client, int $locker_id = 0 ): ?array {
    $dest    = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : [];
    $country = strtoupper( (string) ( $dest['country'] ?? '' ) );
    if ( '' === $country ) {
      return null;
    }

    if ( 'RO' === $country ) {
      $resolved = ( new CityResolver( $client, SmartShipClient::RATE_TIMEOUT ) )->resolve( (string) ( $dest['state'] ?? '' ), (string) ( $dest['city'] ?? '' ), (string) ( $dest['address'] ?? '' ) );
      if ( empty( $resolved['city_id'] ) ) {
        return null;
      }
      $recipient_city = (int) $resolved['city_id'];
      $sector         = AwbPayload::canonical_sector( $resolved['sector'] ?? '0' );
      // Bucharest sector is intentionally omitted from the cache key: every sector
      // shares the same city id and cost, so one cached quote covers all of them.
      $cache_locality = (string) $recipient_city;
    } else {
      $recipient_city = (string) ( $dest['city'] ?? '' );
      if ( '' === $recipient_city ) {
        return null;
      }
      $sector         = '0';
      $cache_locality = strtolower( trim( $recipient_city ) ) . '|' . strtolower( trim( (string) ( $dest['postcode'] ?? '' ) ) );
    }

    $weight = (int) ceil( max( 1.0, AwbPayload::to_kg( self::package_weight( $package ) ) ) );

    // Validate the sender BEFORE the caches (Phase 3 order): a missing/invalid
    // sender must yield fallback even when the rate cache for this city is hot.
    $sender = self::sender_block( $client );
    if ( empty( $sender ) ) {
      return null;
    }

    // Country-scoped so RO and international quotes for the same city/weight
    // combination never collide (they hit different SmartShip pricing anyway).
    // Locker-scoped so a locker quote and the normal quote for the same
    // destination/weight never share a cache entry (a locker call only ever
    // returns the courier-12 line, never the full courier list).
    $hash   = md5( $country . '|' . $cache_locality . '|' . $weight . '|' . Settings::sender_id() . '|' . Settings::api_key() . ( $locker_id > 0 ? '|locker' . $locker_id : '' ) );
    $key    = 'webbership_ss_rate_' . $hash;
    // Scoped per destination (same hash as the rate-cache key) so one bad destination
    // doesn't suppress live rates for every other customer's checkout for 60s.
    $fail_key = 'webbership_ss_rate_fail_' . $hash;
    $cached = get_transient( $key );
    if ( is_array( $cached ) ) {
      return $cached;
    }
    if ( get_transient( $fail_key ) ) {
      return null;
    }

    $content = [ 'package_content' => 'Estimate', 'parcels' => 1, 'weight' => $weight, 'cash_on_delivery' => 0, 'length' => 10, 'width' => 10, 'height' => 10 ];
    if ( $locker_id > 0 ) {
      $content['locker_id'] = $locker_id;
    }
    $body = [
      'recipient' => [ 'name' => 'Estimate', 'address' => (string) ( $dest['address'] ?? '' ), 'email' => 'estimate@example.com', 'city' => $recipient_city, 'phone' => '0700000000', 'country' => $country, 'sector' => $sector ],
      'sender'    => $sender,
      'content'   => $content,
    ];
    $res = $client->cost( $body, SmartShipClient::RATE_TIMEOUT );
    if ( empty( $res['ok'] ) ) {
      set_transient( $fail_key, 1, MINUTE_IN_SECONDS );
      return null;
    }
    $costs = $res['costs'] ?? ( $res['response']['costs'] ?? [] );
    // A malformed ok-response (costs not an array) must fall back, not fatal a downstream array consumer.
    if ( ! is_array( $costs ) ) {
      set_transient( $fail_key, 1, MINUTE_IN_SECONDS );
      return null;
    }
    CourierRegistry::learn( $costs );
    set_transient( $key, $costs, 10 * MINUTE_IN_SECONDS );
    return $costs;
  }

  /** Package weight in kg (unit-converted, unfloored — 0 when no item carries a weight). */
  public static function package_weight_kg( array $package ): float {
    return AwbPayload::to_kg( self::package_weight( $package ) );
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
