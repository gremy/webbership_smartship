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

  /**
   * @return array<int,array<string,mixed>>|null Normalized active lockers, or
   *   null when the upstream fetch failed (or lost the single-flight race) — the
   *   caller must treat null as a retryable failure, NOT as "zero lockers".
   */
  public static function all( $client ): ?array {
    $cached = get_transient( self::CACHE_KEY );
    if ( is_array( $cached ) ) {
      return $cached;
    }

    // Single-flight: on a cache miss, only the first concurrent request fetches
    // upstream (a 20s call); the rest get null (retryable) for this one request
    // rather than each blocking on their own /geolocation/easybox call. The next
    // request retries once the winner's fetch has populated the real cache.
    // ponytail: wp_cache_add() is only atomic on a persistent object cache (Redis is
    // configured for this store); without one this lock is a same-request no-op and
    // every request still fetches, same as before this fix.
    if ( ! wp_cache_add( self::CACHE_KEY . '_lock', 1, '', 10 ) ) {
      return null;
    }

    $res = $client->get_easybox();
    if ( empty( $res['ok'] ) ) {
      // Upstream API failure — retryable, must not be mistaken for a real empty list.
      return null;
    }
    $rows = ( isset( $res['easybox'] ) && is_array( $res['easybox'] ) ) ? $res['easybox'] : [];

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

  /**
   * Pick a deterministic representative locker for a county-level EasyBox `/cost`
   * quote: the first active locker whose `county` name matches the destination
   * (diacritics-insensitive). `$lockers` is expected to already be active-only
   * (i.e. the output of all()). Pure/testable — no WP/HTTP calls beyond
   * remove_accents() when it's available.
   *
   * ponytail: one representative per county, not one quote per locker — SmartShip's
   * 2026-07-29 EasyBox pricing has no evidence of varying within a county. Reprice
   * per-locker if that ever changes.
   *
   * @param array<int,array<string,mixed>> $lockers Normalized locker rows (see all()).
   * @return array<string,mixed>|null The matching locker row, or null when $lockers is
   *   empty OR no locker's county matches — the caller must fall back rather than
   *   quote off a locker in an unrelated county.
   */
  public static function representative_locker( array $lockers, string $county_name ): ?array {
    if ( empty( $lockers ) ) {
      return null;
    }
    $needle = self::normalize_county( $county_name );
    if ( '' === $needle ) {
      return null;
    }
    foreach ( $lockers as $locker ) {
      if ( self::normalize_county( (string) ( $locker['county'] ?? '' ) ) === $needle ) {
        return $locker;
      }
    }
    return null;
  }

  /** Diacritics- and case-insensitive county name key, for matching against WC's RO state names. */
  private static function normalize_county( string $name ): string {
    $name = trim( $name );
    if ( '' === $name ) {
      return '';
    }
    $name = function_exists( 'remove_accents' ) ? remove_accents( $name ) : strtr( $name, [
      'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
      'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
    ] );
    return strtolower( $name );
  }
}
