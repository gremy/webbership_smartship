<?php
declare(strict_types=1);
// Run: php tests/smoke-city-resolver.php
define( 'ABSPATH', __DIR__ );
function __( $t, $d = 'default' ) { return $t; }

function assert_true( bool $c, string $m ): void { if ( ! $c ) { throw new RuntimeException( $m ); } }
function assert_same( $e, $a, string $m ): void { if ( $e !== $a ) { throw new RuntimeException( $m . ': expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) ); } }

// Fake client exposing the two geo methods the resolver uses.
class FakeClient {
  public function get_counties(): array {
    return [ 'ok' => true, 'status' => 200, 'counties' => [
      [ 'id' => 1, 'county' => 'Alba' ], [ 'id' => 38, 'county' => 'Timis' ], [ 'id' => 10, 'county' => 'Bucuresti' ],
    ] ];
  }
  public function get_cities( int $county_id ): array {
    $map = [
      38 => [ [ 'id' => 263804, 'city' => 'Sacalaz' ], [ 'id' => 263852, 'city' => 'Timisoara' ] ],
      // SmartShip's real geo API: Bucuresti has exactly one city row, no "Sector N" rows.
      10 => [ [ 'id' => 255154, 'city' => 'Bucuresti' ] ],
    ];
    return [ 'ok' => true, 'status' => 200, 'cities' => $map[ $county_id ] ?? [] ];
  }
}

require_once __DIR__ . '/../includes/Api/class-smartship-client.php';
require_once __DIR__ . '/../includes/Support/class-city-resolver.php';
use Webbership\Smartship\Support\CityResolver;

$r = new CityResolver( new FakeClient() );

// exact match (diacritic-insensitive): TM + "Timişoara" -> Timis county + Timisoara city.
$out = $r->resolve( 'TM', 'Timişoara' );
assert_same( 38, $out['county_id'], 'tm county' );
assert_same( 263852, $out['city_id'], 'timisoara city' );
assert_true( $out['confident'] === true, 'tm confident' );
assert_same( '0', $out['sector'], 'tm sector is 0' );

// case/diacritic: TM + "sacalaz" -> Sacalaz.
$out = $r->resolve( 'TM', 'sacalaz' );
assert_same( 263804, $out['city_id'], 'sacalaz city' );
assert_same( '0', $out['sector'], 'sacalaz sector is 0' );

// unknown city in a known county -> not confident, city_id null, county still resolved.
$out = $r->resolve( 'TM', 'Nonexistentville' );
assert_same( 38, $out['county_id'], 'county still resolved' );
assert_true( $out['city_id'] === null, 'unknown city -> null' );
assert_true( $out['confident'] === false, 'unknown city -> not confident' );
assert_same( '0', $out['sector'], 'unknown city sector is 0' );

// unknown county code -> nothing resolved.
$out = $r->resolve( 'ZZ', 'Whatever' );
assert_true( $out['county_id'] === null && $out['confident'] === false, 'unknown county code' );
assert_same( '0', $out['sector'], 'unknown county sector is 0' );

// --- Bucuresti: real SmartShip geo has ONE "Bucuresti" city row (255154), no sector rows.
// The sector is a separate field the resolver must derive from free text.

// Sector in the city name itself.
$out = $r->resolve( 'B', 'Sector 1' );
assert_same( 10, $out['county_id'], 'sector1 county' );
assert_same( 255154, $out['city_id'], 'sector1 city' );
assert_same( '1', $out['sector'], 'sector1 sector' );
assert_true( $out['confident'] === true, 'sector1 confident' );

$out = $r->resolve( 'B', 'Sectorul 4' );
assert_same( '4', $out['sector'], 'sectorul4 sector' );
assert_true( $out['confident'] === true, 'sectorul4 confident' );

// Bare "Bucuresti", no sector anywhere -> city matches, but sector unknown -> not confident.
$out = $r->resolve( 'B', 'Bucuresti' );
assert_same( 10, $out['county_id'], 'bare bucuresti county' );
assert_same( 255154, $out['city_id'], 'bare bucuresti city matched' );
assert_same( '', $out['sector'], 'bare bucuresti sector empty' );
assert_true( $out['confident'] === false, 'bare bucuresti -> not confident (no sector)' );

// Sector sourced from the extra address text, not the city name.
$out = $r->resolve( 'B', 'Bucuresti', 'Str. Foo 3, Sector 2' );
assert_same( '2', $out['sector'], 'sector from address' );
assert_true( $out['confident'] === true, 'sector from address confident' );

// Regex only accepts sectors 1-6.
$out = $r->resolve( 'B', 'Sectorul 9' );
assert_same( '', $out['sector'], 'sector 9 rejected' );
assert_true( $out['confident'] === false, 'sector 9 not confident' );

// normalize strips diacritics.
assert_same( 'timisoara', CityResolver::normalize( 'Timişoara' ), 'normalize' );

// CityResolver::sector_from() direct unit checks.
assert_same( '3', CityResolver::sector_from( 'Sector 3' ), 'sector_from Sector 3' );
assert_same( '5', CityResolver::sector_from( 'sectorul 5' ), 'sector_from sectorul 5' );
assert_same( '', CityResolver::sector_from( 'Str. Fake 10' ), 'sector_from no match' );
assert_same( '', CityResolver::sector_from( 'Sector 7' ), 'sector_from out of range' );

echo "smoke-city-resolver: all assertions passed\n";
