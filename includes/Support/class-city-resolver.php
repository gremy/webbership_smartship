<?php
declare(strict_types=1);

namespace Ovride\Smartship\Support;

defined( 'ABSPATH' ) || exit;

use Ovride\Smartship\Api\SmartShipClient;

/**
 * Resolves a WooCommerce RO address (county ISO code + city name) to SmartShip
 * integer ids. Returns confident=false whenever a part can't be matched unambiguously.
 *
 * @package Ovride\Smartship\Support
 */
final class CityResolver {
  /** WooCommerce RO state (ISO 3166-2) code => county name (as SmartShip spells it). */
  private const COUNTY_NAMES = [
    'AB' => 'Alba', 'AR' => 'Arad', 'AG' => 'Arges', 'BC' => 'Bacau', 'BH' => 'Bihor',
    'BN' => 'Bistrita-Nasaud', 'BT' => 'Botosani', 'BV' => 'Brasov', 'BR' => 'Braila',
    'B' => 'Bucuresti', 'BZ' => 'Buzau', 'CS' => 'Caras-Severin', 'CL' => 'Calarasi',
    'CJ' => 'Cluj', 'CT' => 'Constanta', 'CV' => 'Covasna', 'DB' => 'Dambovita',
    'DJ' => 'Dolj', 'GL' => 'Galati', 'GR' => 'Giurgiu', 'GJ' => 'Gorj', 'HR' => 'Harghita',
    'HD' => 'Hunedoara', 'IL' => 'Ialomita', 'IS' => 'Iasi', 'IF' => 'Ilfov',
    'MM' => 'Maramures', 'MH' => 'Mehedinti', 'MS' => 'Mures', 'NT' => 'Neamt',
    'OT' => 'Olt', 'PH' => 'Prahova', 'SM' => 'Satu Mare', 'SJ' => 'Salaj', 'SB' => 'Sibiu',
    'SV' => 'Suceava', 'TR' => 'Teleorman', 'TM' => 'Timis', 'TL' => 'Tulcea',
    'VS' => 'Vaslui', 'VL' => 'Valcea', 'VN' => 'Vrancea',
  ];

  /**
   * @var SmartShipClient SmartShip geo client (or any object exposing
   *                      get_counties()/get_cities(); duck-typed for testing).
   */
  private $client;

  /** @var int Request timeout for the geo calls (checkout passes RATE_TIMEOUT). */
  private $timeout;

  public function __construct( $client, int $timeout = SmartShipClient::TIMEOUT ) {
    $this->client  = $client;
    $this->timeout = $timeout;
  }

  public function resolve( string $county_code, string $city_name ): array {
    $miss = [ 'county_id' => null, 'city_id' => null, 'confident' => false ];

    $name = self::COUNTY_NAMES[ strtoupper( trim( $county_code ) ) ] ?? null;
    if ( null === $name ) {
      return $miss;
    }

    $counties = $this->client->get_counties( $this->timeout );
    if ( empty( $counties['ok'] ) ) {
      return $miss;
    }
    $county_id = null;
    $target    = self::normalize( $name );
    foreach ( (array) ( $counties['counties'] ?? [] ) as $c ) {
      if ( isset( $c['county'] ) && self::normalize( (string) $c['county'] ) === $target ) {
        $county_id = (int) $c['id'];
        break;
      }
    }
    if ( null === $county_id ) {
      return $miss;
    }

    $cities = $this->client->get_cities( $county_id, $this->timeout );
    if ( empty( $cities['ok'] ) ) {
      return [ 'county_id' => $county_id, 'city_id' => null, 'confident' => false ];
    }
    $is_bucuresti = ( 'bucuresti' === $target );
    $wanted       = self::normalize_city( $city_name, $is_bucuresti );

    // In Bucuresti only a sector is a valid destination. Refuse to match a bare
    // "Bucuresti" even if SmartShip returns a literal city row by that name.
    if ( $is_bucuresti && 'bucuresti' === $wanted ) {
      return [ 'county_id' => $county_id, 'city_id' => null, 'confident' => false ];
    }

    $city_id = null;
    foreach ( (array) ( $cities['cities'] ?? [] ) as $ct ) {
      if ( isset( $ct['city'] ) && self::normalize_city( (string) $ct['city'], $is_bucuresti ) === $wanted ) {
        $city_id = (int) $ct['id'];
        break;
      }
    }

    return [
      'county_id' => $county_id,
      'city_id'   => $city_id,
      'confident' => ( null !== $city_id ),
    ];
  }

  /**
   * Normalize a city name for comparison. In Bucuresti, fold "sectorul N" and
   * "sector N" together so WC's "Sector 4" matches SmartShip's "Sectorul 4".
   */
  private static function normalize_city( string $s, bool $is_bucuresti ): string {
    $s = self::normalize( $s );
    if ( $is_bucuresti ) {
      $s = str_replace( 'sectorul', 'sector', $s );
    }
    return $s;
  }

  /** lowercase, strip RO diacritics, collapse whitespace. */
  public static function normalize( string $s ): string {
    $s = strtr( $s, [
      'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
      'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ş' => 's', 'Ț' => 't', 'Ţ' => 't',
    ] );
    $s = strtolower( trim( $s ) );
    return (string) preg_replace( '/\s+/', ' ', $s );
  }
}
