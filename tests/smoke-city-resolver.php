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
      10 => [ [ 'id' => 900000, 'city' => 'Bucuresti' ], [ 'id' => 900001, 'city' => 'Sectorul 1' ], [ 'id' => 900004, 'city' => 'Sectorul 4' ] ],
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

// case/diacritic: TM + "sacalaz" -> Sacalaz.
$out = $r->resolve( 'TM', 'sacalaz' );
assert_same( 263804, $out['city_id'], 'sacalaz city' );

// unknown city in a known county -> not confident, city_id null, county still resolved.
$out = $r->resolve( 'TM', 'Nonexistentville' );
assert_same( 38, $out['county_id'], 'county still resolved' );
assert_true( $out['city_id'] === null, 'unknown city -> null' );
assert_true( $out['confident'] === false, 'unknown city -> not confident' );

// Bucharest sector: B + "Sector 4" -> Sectorul 4.
$out = $r->resolve( 'B', 'Sector 4' );
assert_same( 10, $out['county_id'], 'bucuresti county' );
assert_same( 900004, $out['city_id'], 'sector 4 city' );

// Bucharest bare "Bucuresti" (no sector) -> county resolved, but NEVER a confident
// city match even when SmartShip returns a literal "Bucuresti" row: only a sector
// is a valid Bucharest destination.
$out = $r->resolve( 'B', 'Bucuresti' );
assert_same( 10, $out['county_id'], 'bucuresti county 2' );
assert_true( $out['city_id'] === null, 'bare bucuresti -> city_id null' );
assert_true( $out['confident'] === false, 'bare bucuresti -> not confident' );

// unknown county code -> nothing resolved.
$out = $r->resolve( 'ZZ', 'Whatever' );
assert_true( $out['county_id'] === null && $out['confident'] === false, 'unknown county code' );

// normalize strips diacritics.
assert_same( 'timisoara', CityResolver::normalize( 'Timişoara' ), 'normalize' );

echo "smoke-city-resolver: all assertions passed\n";
