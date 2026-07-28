<?php
declare(strict_types=1);
// Run: php tests/smoke-cost-service.php
define( 'ABSPATH', __DIR__ );
function __( $t, $d = 'default' ) { return $t; }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

// In-memory transient store so we can prove caching (no client re-hit) and the failure-cache.
$GLOBALS['ss_store'] = [];
function get_transient( $k ) { return $GLOBALS['ss_store'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['ss_store'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['ss_store'][ $k ] ); return true; }

function assert_true( bool $c, string $m ): void { if ( ! $c ) { throw new RuntimeException( $m ); } }
function assert_same( $e, $a, string $m ): void { if ( $e !== $a ) { throw new RuntimeException( $m . ': expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) ); } }

// The fail-cache key is now per-destination (hashed), not a fixed name — match by prefix.
function any_fail_key_set(): bool {
  foreach ( array_keys( $GLOBALS['ss_store'] ) as $k ) {
    if ( str_starts_with( $k, 'webbership_ss_rate_fail_' ) ) { return true; }
  }
  return false;
}

require_once __DIR__ . '/../includes/Api/class-smartship-client.php';
require_once __DIR__ . '/../includes/Support/class-city-resolver.php';
require_once __DIR__ . '/../modules/awb/data/class-awb-payload.php';
require_once __DIR__ . '/../includes/Support/class-cost-service.php';

use Webbership\Smartship\Support\CostService;

// Stub Settings (sender id + api key) the service reads. Real class isn't loaded here.
if ( ! class_exists( '\\Webbership\\Smartship\\Settings\\Settings' ) ) {
  eval( 'namespace Webbership\\Smartship\\Settings; class Settings { public static $sender_id = 7; public static $api_key = "TESTKEY"; public static function sender_id(): int { return self::$sender_id; } public static function api_key(): string { return self::$api_key; } public static function iban(): string { return ""; } }' );
}

/**
 * Duck-typed SmartShipClient: only the methods CostService calls, counting cost() hits.
 * Geo methods feed CityResolver (TM + Sacalaz -> city 263804).
 */
class FakeCostClient {
  public int $cost_calls = 0;
  public int $last_cost_timeout = 0;
  public array $last_cost_body = [];
  public $cost_result;
  public function get_counties( int $t = 0 ): array {
    return [ 'ok' => true, 'status' => 200, 'counties' => [ [ 'id' => 38, 'county' => 'Timis' ] ] ];
  }
  public function get_cities( int $county_id, int $t = 0 ): array {
    return [ 'ok' => true, 'status' => 200, 'cities' => [ [ 'id' => 263804, 'city' => 'Sacalaz' ] ] ];
  }
  public function get_senders( int $t = 0 ): array {
    return [ 'ok' => true, 'status' => 200, 'senders' => [
      [ 'id' => 7, 'nume' => 'Test Sender', 'adresa' => 'Str. 1', 'email' => 's@x.ro', 'localitate_id' => 263852, 'telefon' => '0700', 'sector' => '0' ],
    ] ];
  }
  public function cost( array $body, int $t = 0 ): array {
    $this->cost_calls++;
    $this->last_cost_timeout = $t;
    $this->last_cost_body    = $body;
    return $this->cost_result;
  }
}
class FakeProduct {
  private $w; public function __construct( $w ) { $this->w = $w; }
  public function get_weight() { return $this->w; }
}
class FakeNoSenderClient extends FakeCostClient {
  public function get_senders( int $t = 0 ): array { return [ 'ok' => true, 'status' => 200, 'senders' => [] ]; }
}

$ro_pkg = [ 'destination' => [ 'country' => 'RO', 'state' => 'TM', 'city' => 'Sacalaz', 'address' => 'Str. Test 1' ], 'contents' => [] ];
$costs_payload = [
  [ 'courier_id' => 2,  'courier_name' => 'SameDay',  'cost' => 25.91 ],
  [ 'courier_id' => 16, 'courier_name' => 'SmartShip', 'cost' => 17.97 ],
];

// 1) Happy path: returns the costs[] for an RO package, and caches it.
$GLOBALS['ss_store'] = [];
$client = new FakeCostClient();
$client->cost_result = [ 'ok' => true, 'status' => 200, 'costs' => $costs_payload ];
$out = CostService::costs_for( $ro_pkg, $client );
assert_true( is_array( $out ), 'happy: returns array' );
assert_same( 2, count( $out ), 'happy: two rows' );
assert_same( 2, (int) $out[0]['courier_id'], 'happy: first row SameDay' );
assert_same( 1, $client->cost_calls, 'happy: one /cost call' );
assert_same( \Webbership\Smartship\Api\SmartShipClient::RATE_TIMEOUT, $client->last_cost_timeout, 'happy: /cost uses RATE_TIMEOUT' );

// 2) Second call hits the rate cache -> client->cost() NOT called again.
$out2 = CostService::costs_for( $ro_pkg, $client );
assert_same( 2, count( $out2 ), 'cache: same costs returned' );
assert_same( 1, $client->cost_calls, 'cache: no second /cost call' );

// 3) courier_cost finds SameDay (2) as a float; absent -> null.
assert_true( abs( CostService::courier_cost( $out, 2 ) - 25.91 ) < 0.001, 'courier_cost: SameDay 25.91' );
assert_true( null === CostService::courier_cost( $out, 99 ), 'courier_cost: absent -> null' );

// 4) /cost ok=false -> sets the 60s failure-cache and returns null.
$GLOBALS['ss_store'] = [];
$client = new FakeCostClient();
$client->cost_result = [ 'ok' => false, 'status' => 999 ];
$out = CostService::costs_for( $ro_pkg, $client );
assert_true( null === $out, 'fail: null on ok=false' );
assert_true( any_fail_key_set(), 'fail: failure-cache set' );
// And the failure-cache short-circuits the next call (no /cost hit).
$before = $client->cost_calls;
assert_true( null === CostService::costs_for( $ro_pkg, $client ), 'fail: still null while failure-cache hot' );
assert_same( $before, $client->cost_calls, 'fail: failure-cache short-circuits /cost' );

// 4b) Per-destination scoping: a package to the SAME city but a DIFFERENT weight
// (=> different rate-cache hash) must NOT be short-circuited by the fail-cache
// above — one bad destination/weight combo can no longer suppress everyone else.
$other_pkg = [
  'destination' => [ 'country' => 'RO', 'state' => 'TM', 'city' => 'Sacalaz', 'address' => 'Str. Test 1' ],
  'contents'    => [ [ 'data' => new FakeProduct( '5' ), 'quantity' => 1 ] ],
];
$client->cost_result = [ 'ok' => true, 'status' => 200, 'costs' => $costs_payload ];
$before = $client->cost_calls;
assert_true( is_array( CostService::costs_for( $other_pkg, $client ) ), 'per-dest: different weight not blocked by other destination\'s fail-cache' );
assert_true( $client->cost_calls > $before, 'per-dest: /cost was actually attempted' );

// 5) Non-array costs in an ok response -> failure-cache + null.
$GLOBALS['ss_store'] = [];
$client = new FakeCostClient();
$client->cost_result = [ 'ok' => true, 'status' => 200, 'costs' => 'garbage' ];
$out = CostService::costs_for( $ro_pkg, $client );
assert_true( null === $out, 'non-array: null' );
assert_true( any_fail_key_set(), 'non-array: failure-cache set' );

// 6) Unresolved city -> null (no /cost call).
$GLOBALS['ss_store'] = [];
$client = new FakeCostClient();
$client->cost_result = [ 'ok' => true, 'status' => 200, 'costs' => $costs_payload ];
$unresolved = [ 'destination' => [ 'country' => 'RO', 'state' => 'TM', 'city' => 'Nowhere' ], 'contents' => [] ];
assert_true( null === CostService::costs_for( $unresolved, $client ), 'unresolved: null' );
assert_same( 0, $client->cost_calls, 'unresolved: no /cost call' );

// 7) Invalid/missing sender -> null EVEN with a hot rate cache (sender is validated
//    before the caches; matches Phase 3 so a removed sender always yields fallback).
$GLOBALS['ss_store'] = [];
$client = new FakeNoSenderClient();
$client->cost_result = [ 'ok' => true, 'status' => 200, 'costs' => $costs_payload ];
// Pre-populate the rate cache for this city+weight (as if a prior valid estimate ran).
set_transient( 'webbership_ss_rate_' . md5( '263804' . '|' . '1' . '|' . '7' . '|' . 'TESTKEY' ), $costs_payload, 600 );
assert_true( null === CostService::costs_for( $ro_pkg, $client ), 'invalid sender: null despite hot rate cache' );
assert_same( 0, $client->cost_calls, 'invalid sender: no /cost call' );

// 8) International destination: no RO-only gate — a DE package reaches /cost with
// the real country (not hardcoded 'RO') and the real city name passed through.
$GLOBALS['ss_store'] = [];
$client = new FakeCostClient();
$client->cost_result = [ 'ok' => true, 'status' => 200, 'costs' => $costs_payload ];
$de_pkg = [ 'destination' => [ 'country' => 'DE', 'state' => 'BE', 'city' => 'Berlin', 'postcode' => '10115', 'address' => 'Musterstr. 1' ], 'contents' => [] ];
$out_de = CostService::costs_for( $de_pkg, $client );
assert_true( is_array( $out_de ), 'international: returns array for a DE destination' );
assert_same( 1, $client->cost_calls, 'international: /cost was called (no RO-only short-circuit)' );
assert_same( 'DE', $client->last_cost_body['recipient']['country'], 'international: recipient country is the real destination, not RO' );
assert_same( 'Berlin', $client->last_cost_body['recipient']['city'], 'international: recipient city passed through as the WC city name' );

// 9) International destination with no city -> null, no /cost call (can't quote it).
$GLOBALS['ss_store'] = [];
$client = new FakeCostClient();
$client->cost_result = [ 'ok' => true, 'status' => 200, 'costs' => $costs_payload ];
$no_city_pkg = [ 'destination' => [ 'country' => 'DE', 'city' => '' ], 'contents' => [] ];
assert_true( null === CostService::costs_for( $no_city_pkg, $client ), 'international: no city -> null' );
assert_same( 0, $client->cost_calls, 'international: no city -> no /cost call' );

// 10) No destination country at all -> null, no /cost call.
$GLOBALS['ss_store'] = [];
$client = new FakeCostClient();
$client->cost_result = [ 'ok' => true, 'status' => 200, 'costs' => $costs_payload ];
assert_true( null === CostService::costs_for( [ 'destination' => [], 'contents' => [] ], $client ), 'no country -> null' );
assert_same( 0, $client->cost_calls, 'no country -> no /cost call' );

// 11) Cache key is scoped by country: an RO package and a DE package that happen to
// share the same city TEXT and weight must NOT share a cached rate (RO resolves to
// a numeric city id anyway, but this proves the country is in the hash, not just
// something that happens to differ because of that).
$GLOBALS['ss_store'] = [];
$client = new FakeCostClient();
$client->cost_result = [ 'ok' => true, 'status' => 200, 'costs' => $costs_payload ];
CostService::costs_for( $ro_pkg, $client ); // caches under the RO hash
$before = $client->cost_calls;
$de_same_city_pkg = [ 'destination' => [ 'country' => 'DE', 'city' => 'Sacalaz' ], 'contents' => [] ];
assert_true( is_array( CostService::costs_for( $de_same_city_pkg, $client ) ), 'country-scoped cache: DE quote still fetched' );
assert_true( $client->cost_calls > $before, 'country-scoped cache: RO cache entry did not satisfy the DE request' );

echo "smoke-cost-service: all assertions passed\n";
