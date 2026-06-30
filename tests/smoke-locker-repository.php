<?php
declare(strict_types=1);
// Run: php tests/smoke-locker-repository.php
define( 'ABSPATH', __DIR__ );
function __( $t, $d = 'default' ) { return $t; }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

// In-memory transient store so we can prove the normalized cache (no client re-hit).
$GLOBALS['ss_store'] = [];
function get_transient( $k ) { return $GLOBALS['ss_store'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['ss_store'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['ss_store'][ $k ] ); return true; }

function assert_true( bool $c, string $m ): void { if ( ! $c ) { throw new RuntimeException( $m ); } }
function assert_same( $e, $a, string $m ): void { if ( $e !== $a ) { throw new RuntimeException( $m . ': expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) ); } }

require_once __DIR__ . '/../modules/easybox/class-locker-repository.php';

use Webbership\Smartship\Modules\EasyBox\LockerRepository;

/**
 * Duck-typed SmartShipClient: only get_easybox(), counting calls. Live row shape
 * (lat/lng are strings; sts:1 = active). Includes an inactive row and an id-less row.
 */
class FakeLockerClient {
  public int $calls = 0;
  /** @var array */
  public $easybox;
  public function __construct( array $easybox ) { $this->easybox = $easybox; }
  public function get_easybox( int $t = 0 ): array {
    $this->calls++;
    return [ 'ok' => true, 'status' => 200, 'easybox' => $this->easybox ];
  }
}

$active = [
  'locker_id'   => 2,
  'name'        => 'easybox OMV Belu',
  'county'      => 'Bucuresti',
  'county_id'   => 1,
  'city'        => 'Sectorul 4',
  'address'     => 'Sos. Oltenitei, Nr. 2',
  'postal_code' => '044501',
  'lat'         => '44.402018',
  'lng'         => '26.097853',
  'sts'         => 1,
  'payment'     => 1,
];
$inactive = [ 'locker_id' => 9, 'name' => 'closed', 'sts' => 0, 'lat' => '1', 'lng' => '2' ];
$idless   = [ 'name' => 'no id', 'sts' => 1, 'lat' => '3', 'lng' => '4' ];

// 1) all() keeps only the active, well-formed row; drops inactive + id-less.
$GLOBALS['ss_store'] = [];
$client = new FakeLockerClient( [ $active, $inactive, $idless ] );
$out = LockerRepository::all( $client );
assert_true( is_array( $out ), 'all: returns array' );
assert_same( 1, count( $out ), 'all: only the active well-formed row survives' );
$row = $out[0];
assert_same( 2, $row['id'], 'map: locker_id -> id int' );
assert_true( is_int( $row['id'] ), 'map: id is int' );
assert_same( 'easybox OMV Belu', $row['name'], 'map: name' );
assert_same( 'Sectorul 4', $row['city'], 'map: city' );
assert_same( 'Bucuresti', $row['county'], 'map: county' );
assert_same( 1, $row['county_id'], 'map: county_id int' );
assert_same( 'Sos. Oltenitei, Nr. 2', $row['address'], 'map: address' );
assert_same( '044501', $row['postal_code'], 'map: postal_code (leading zero kept)' );
assert_true( is_float( $row['lat'] ), 'map: lat is float' );
assert_true( abs( $row['lat'] - 44.402018 ) < 0.000001, 'map: lat value' );
assert_true( is_float( $row['lng'] ), 'map: lng is float' );
assert_true( abs( $row['lng'] - 26.097853 ) < 0.000001, 'map: lng value' );
assert_same( 1, $row['payment'], 'map: payment int' );
assert_same(
  [ 'id', 'name', 'city', 'county', 'county_id', 'address', 'postal_code', 'lat', 'lng', 'payment' ],
  array_keys( $row ),
  'map: complete shape, no extra keys'
);

// 2) Second call hits the normalized cache -> client NOT called again.
assert_same( 1, $client->calls, 'cache: one upstream call so far' );
$out2 = LockerRepository::all( $client );
assert_same( 1, count( $out2 ), 'cache: same lockers returned' );
assert_same( 1, $client->calls, 'cache: no second upstream call' );

// 3) Empty list -> [] and is NOT cached (a transient blip must not pin "no lockers").
$GLOBALS['ss_store'] = [];
$emptyClient = new FakeLockerClient( [] );
$out = LockerRepository::all( $emptyClient );
assert_same( [], $out, 'empty: returns []' );
assert_true( false === get_transient( 'webbership_ss_lockers' ), 'empty: nothing cached' );
// A subsequent call with a now-populated client returns the lockers (no stale empty cache).
$populated = new FakeLockerClient( [ $active ] );
$out = LockerRepository::all( $populated );
assert_same( 1, count( $out ), 'empty-then-populated: lockers now returned' );
assert_same( 1, $populated->calls, 'empty-then-populated: upstream consulted (empty not cached)' );

echo "smoke-locker-repository: all assertions passed\n";
