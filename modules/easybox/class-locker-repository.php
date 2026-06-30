<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\EasyBox;

defined( 'ABSPATH' ) || exit;

/**
 * Cached, normalized SameDay EasyBox locker list.
 *
 * The upstream list is ~6,800 rows of strings; normalize once and cache the result
 * so repeated checkouts don't re-map it. Active lockers only (`sts == 1`).
 * `$client` is duck-typed (only `get_easybox()` is called) to keep this WP/HTTP-free
 * and unit-testable with a fake — same pattern as CostService.
 *
 * @package Webbership\Smartship\Modules\EasyBox
 */
final class LockerRepository {
  public const CACHE_KEY = 'webbership_ss_lockers';

  /** @return array<int,array<string,mixed>> Normalized active lockers. */
  public static function all( $client ): array {
    $cached = get_transient( self::CACHE_KEY );
    if ( is_array( $cached ) ) {
      return $cached;
    }

    // Single-flight: on a cache miss, only the first concurrent request fetches
    // upstream (a 20s call); the rest get an empty list for this one request rather
    // than each blocking on their own /geolocation/easybox call. The picker UI
    // already renders an empty list as a normal "no lockers found" state, and the
    // next request retries once the winner's fetch has populated the real cache.
    // ponytail: wp_cache_add() is only atomic on a persistent object cache (Redis is
    // configured for this store); without one this lock is a same-request no-op and
    // every request still fetches, same as before this fix.
    if ( ! wp_cache_add( self::CACHE_KEY . '_lock', 1, '', 10 ) ) {
      return [];
    }

    $res  = $client->get_easybox();
    $rows = ( is_array( $res ) && isset( $res['easybox'] ) && is_array( $res['easybox'] ) ) ? $res['easybox'] : [];

    $lockers = [];
    foreach ( $rows as $row ) {
      if ( ! is_array( $row ) ) {
        continue;
      }
      if ( (int) ( $row['sts'] ?? 0 ) !== 1 ) {
        continue;
      }
      $id = (int) ( $row['locker_id'] ?? 0 );
      if ( $id <= 0 ) {
        continue;
      }
      $lockers[] = [
        'id'          => $id,
        'name'        => (string) ( $row['name'] ?? '' ),
        'city'        => (string) ( $row['city'] ?? '' ),
        'county'      => (string) ( $row['county'] ?? '' ),
        'county_id'   => (int) ( $row['county_id'] ?? 0 ),
        'address'     => (string) ( $row['address'] ?? '' ),
        'postal_code' => (string) ( $row['postal_code'] ?? '' ),
        'lat'         => (float) ( $row['lat'] ?? 0 ),
        'lng'         => (float) ( $row['lng'] ?? 0 ),
        'payment'     => (int) ( $row['payment'] ?? 0 ),
      ];
    }

    // Never cache an empty list: a transient API blip must not pin "no lockers" for a day.
    if ( $lockers ) {
      set_transient( self::CACHE_KEY, $lockers, DAY_IN_SECONDS );
    }

    return $lockers;
  }
}
