<?php
declare(strict_types=1);

// Run: php tests/smoke-awb-metabox-guard.php
// Fix 2: a double-click on "Issue AWB" must not create a second billable shipment.

define( 'ABSPATH', __DIR__ );
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

$GLOBALS['webbership_options'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['webbership_options'][ $k ] ?? $d; }
function wp_parse_args( $a, $d ) { return array_merge( $d, is_array( $a ) ? $a : [] ); }
function __( $t, $d = 'default' ) { return $t; }
function absint( $v ) { return abs( (int) $v ); }
function current_user_can( $cap ) { return true; }
function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) { return true; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : $s; }

$GLOBALS['wc_orders'] = [];
function wc_get_order( $id ) { return $GLOBALS['wc_orders'][ $id ] ?? false; }

// Minimal SmartShipClient plumbing (no live HTTP): the easybox ajax_issue() case
// below runs the real client all the way to create_awb(), so wp_remote_request()
// must return canned bodies instead of hitting the network.
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $ttl = 0 ) { return true; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function add_query_arg( $args, $url ) {
  $sep = ( strpos( (string) $url, '?' ) === false ) ? '?' : '&';
  return $url . $sep . http_build_query( $args );
}
function wp_json_encode( $data ) { return json_encode( $data ); }
function get_bloginfo( $k ) { return 'Test Shop'; }
function home_url() { return 'https://shop.test'; }
function wp_specialchars_decode( $s, $q = null ) { return $s; }
class WP_Error {
  private $c; private $m;
  public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
  public function get_error_code() { return $this->c; }
  public function get_error_message() { return $this->m; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
$GLOBALS['ss_last_body'] = null;
function wp_remote_request( $url, $args = [] ) {
  $GLOBALS['ss_last_body'] = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null;
  if ( false !== strpos( $url, '/account/senders' ) ) {
    return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [
      'status'  => 200,
      'senders' => [ [ 'id' => 1, 'nume' => 'Test Sender', 'adresa' => 'Str. 1', 'email' => 's@x.ro', 'localitate_id' => 1, 'telefon' => '0700', 'sector' => '0' ] ],
    ] ) ];
  }
  // Backs CityResolver for the resolver-only easybox test (2d): 'TM' -> 'Timis' -> a
  // single matching city, so resolve() lands on confident=true from the order's own
  // address alone, with no posted county_id/city_id/sector at all.
  if ( false !== strpos( $url, '/geolocation/counties' ) ) {
    return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [
      'status' => 200, 'counties' => [ [ 'id' => 7, 'county' => 'Timis' ] ],
    ] ) ];
  }
  if ( false !== strpos( $url, '/geolocation/cities' ) ) {
    return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [
      'status' => 200, 'cities' => [ [ 'id' => 555, 'city' => 'Timisoara' ] ],
    ] ) ];
  }
  if ( false !== strpos( $url, '/awb/new' ) ) {
    return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'status' => 200, 'awb' => 'AWB-EB-1', 'courier_name' => 'SameDay EasyBox', 'cost' => 10 ] ) ];
  }
  return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'status' => 999 ] ) ];
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }

/** wp_send_json_*() normally wp_die()s after sending; halt via exception so the test can inspect the payload. */
class WPJsonHalt extends RuntimeException {
  public array $payload;
  public function __construct( array $payload ) { $this->payload = $payload; parent::__construct( 'wp_send_json halt' ); }
}
function wp_send_json_error( $data = null, $status_code = null ) { throw new WPJsonHalt( [ 'success' => false, 'data' => $data ] ); }
function wp_send_json_success( $data = null ) { throw new WPJsonHalt( [ 'success' => true, 'data' => $data ] ); }

function assert_same( $expected, $actual, string $msg ): void {
  if ( $expected !== $actual ) {
    throw new RuntimeException( $msg . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
  }
}

require_once __DIR__ . '/../includes/Settings/class-settings.php';
require_once __DIR__ . '/../includes/Support/class-city-resolver.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/Api/class-smartship-client.php';
require_once __DIR__ . '/../modules/awb/data/class-awb-payload.php';
require_once __DIR__ . '/../modules/awb/admin/class-awb-metabox.php';

use Webbership\Smartship\Modules\Awb\Admin\AwbMetabox;
use Webbership\Smartship\Modules\Awb\Data\AwbPayload;

class FakeOrder {
  private array $meta;
  private string $country;
  public function __construct( array $meta = [], string $country = 'RO' ) { $this->meta = $meta; $this->country = $country; }
  public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
  public function get_shipping_country() { return $this->country; }
  public function get_billing_country() { return ''; }
  public function get_shipping_city() { return ''; }
  public function get_billing_city() { return ''; }
  public function get_shipping_postcode() { return ''; }
  public function get_billing_postcode() { return ''; }
}

$metabox = new AwbMetabox();

// 1) Order already has an AWB -> ajax_issue errors before touching the SmartShip API.
$GLOBALS['wc_orders'][1] = new FakeOrder( [ '_webbership_smartship_awb' => '1234567890' ] );
$_REQUEST['order_id'] = 1;
try {
  $metabox->ajax_issue();
  throw new RuntimeException( 'expected ajax_issue to halt via wp_send_json_error' );
} catch ( WPJsonHalt $halt ) {
  assert_same( false, $halt->payload['success'], 'duplicate AWB: reports failure' );
  assert_same( 'This order already has an AWB. Cancel it first to issue a new one.', $halt->payload['data']['message'], 'duplicate AWB: message' );
}

// 2) Order has no AWB yet -> the duplicate guard does NOT fire; execution reaches
//    the next guard (no courier_id posted) instead of the duplicate-AWB message.
$GLOBALS['wc_orders'][2] = new FakeOrder( [] );
$_REQUEST['order_id'] = 2;
unset( $_POST['courier_id'] );
try {
  $metabox->ajax_issue();
  throw new RuntimeException( 'expected ajax_issue to halt via wp_send_json_error' );
} catch ( WPJsonHalt $halt ) {
  assert_same( false, $halt->payload['success'], 'no AWB yet: still reports failure (no courier chosen)' );
  assert_same( 'Choose a courier.', $halt->payload['data']['message'], 'no AWB yet: guard did not fire, reached the courier check' );
}

// 2b) International order, courier chosen but no city/postcode posted -> ajax_issue
// refuses BEFORE ever constructing a SmartShipClient (city/postcode gate replaces
// the RO city_id/confident gate for non-RO orders).
$GLOBALS['wc_orders'][3] = new FakeOrder( [], 'DE' );
$_REQUEST['order_id'] = 3;
$_POST['courier_id'] = 5;
try {
  $metabox->ajax_issue();
  throw new RuntimeException( 'expected ajax_issue to halt via wp_send_json_error' );
} catch ( WPJsonHalt $halt ) {
  assert_same( false, $halt->payload['success'], 'international, no city/postcode: reports failure' );
  assert_same( 'Enter the destination city and postal code first.', $halt->payload['data']['message'], 'international, no city/postcode: message' );
}
unset( $_POST['courier_id'] );

// 2c) EasyBox order: ajax_issue() no longer blocks with "create the locker AWB in
// SmartShip" (SmartShip's /awb/new now supports locker AWBs — see
// AwbPayload::content_from_order()). courier_id is forced to 12 server-side even
// when a different (stale/tampered) value is posted, and the locker id rides
// along in content.locker_id.
class FakeEasyBoxOrder extends FakeOrder {
  public array $saved_meta = [];
  public array $notes      = [];
  public function __construct() {
    parent::__construct( [
      '_webbership_smartship_easybox_id'   => '5',
      '_webbership_smartship_easybox_name' => 'easybox Test',
    ], 'RO' );
  }
  public function get_shipping_state() { return 'TM'; } // non-Bucharest: skips the sector parser
  public function get_shipping_first_name() { return 'Ion'; }
  public function get_shipping_last_name() { return 'Pop'; }
  public function get_billing_first_name() { return ''; }
  public function get_billing_last_name() { return ''; }
  public function get_shipping_address_1() { return 'Str. Test 1'; }
  public function get_shipping_address_2() { return ''; }
  public function get_billing_address_1() { return ''; }
  public function get_billing_address_2() { return ''; }
  public function get_shipping_phone() { return '0700000000'; }
  public function get_billing_phone() { return ''; }
  public function get_billing_email() { return 'test@example.com'; }
  public function get_total() { return '50'; }
  public function get_payment_method() { return 'card'; }
  public function is_paid() { return true; }
  public function get_order_number() { return '100'; }
  public function get_items() { return []; }
  public function update_meta_data( $k, $v ) { $this->saved_meta[ $k ] = $v; }
  public function add_order_note( $n ) { $this->notes[] = $n; }
  public function save() {}
}

$GLOBALS['wc_orders'][4] = new FakeEasyBoxOrder();
$_REQUEST['order_id'] = 4;
$_POST = [ 'county_id' => 255, 'city_id' => 255154, 'courier_id' => 999 ]; // 999: a tampered/irrelevant posted courier must be ignored
try {
  $metabox->ajax_issue();
  throw new RuntimeException( 'expected ajax_issue to halt via wp_send_json_success' );
} catch ( WPJsonHalt $halt ) {
  assert_same( true, $halt->payload['success'], 'easybox: issue succeeds instead of the old manual-handoff block' );
  assert_same( 'AWB-EB-1', $halt->payload['data']['awb'], 'easybox: awb returned' );
}
assert_same( 12, $GLOBALS['ss_last_body']['courier_id'] ?? null, 'easybox: courier_id forced to 12, ignoring the posted 999' );
assert_same( 5, $GLOBALS['ss_last_body']['content']['locker_id'] ?? null, 'easybox: locker_id carried into the AWB payload' );
$_POST = [];

// 2d) EasyBox order, resolver-only path (FIX 2): the real EasyBox checkout/admin UI
// doesn't always post a county_id/city_id/sector override — render_easybox_handoff()
// only embeds the city/sector picker when the resolver ISN'T confident. Here
// CityResolver resolves the destination confidently from the order's own shipping
// address alone (no picker was needed, nothing posted), so ajax_issue() must still
// succeed, still forcing courier_id to 12 regardless of the tampered posted value.
class FakeEasyBoxOrderResolverOnly extends FakeEasyBoxOrder {
  public function get_shipping_city() { return 'Timisoara'; }
}
$GLOBALS['wc_orders'][5] = new FakeEasyBoxOrderResolverOnly();
$_REQUEST['order_id'] = 5;
$_POST = [ 'courier_id' => 999 ]; // tampered/irrelevant posted courier must still be ignored
try {
  $metabox->ajax_issue();
  throw new RuntimeException( 'expected ajax_issue to halt via wp_send_json_success' );
} catch ( WPJsonHalt $halt ) {
  assert_same( true, $halt->payload['success'], 'easybox resolver-only: issue succeeds with no posted city/sector' );
  assert_same( 'AWB-EB-1', $halt->payload['data']['awb'], 'easybox resolver-only: awb returned' );
}
assert_same( 12, $GLOBALS['ss_last_body']['courier_id'] ?? null, 'easybox resolver-only: courier_id forced to 12' );
assert_same( 5, $GLOBALS['ss_last_body']['content']['locker_id'] ?? null, 'easybox resolver-only: locker_id carried into the AWB payload' );
$_POST = [];

// 3) resolve_for(): a posted sector wins over the parsed one (Fix A); an invalid
//    posted sector is ignored and the parsed/default value is kept.
class FakeOrderAddr {
  private string $country;
  public function __construct( string $country = 'RO' ) { $this->country = $country; }
  public function get_meta( $key ) { return ''; }
  public function get_shipping_state() { return 'B'; }
  public function get_shipping_city() { return 'Bucuresti'; }
  public function get_billing_city() { return ''; }
  public function get_shipping_postcode() { return ''; }
  public function get_billing_postcode() { return ''; }
  public function get_shipping_address_1() { return 'Str. Exemplu 1'; }
  public function get_shipping_address_2() { return ''; }
  public function get_billing_address_1() { return ''; }
  public function get_billing_address_2() { return ''; }
  public function get_shipping_country() { return $this->country; }
  public function get_billing_country() { return ''; }
}

$resolve_for = new ReflectionMethod( AwbMetabox::class, 'resolve_for' );
$resolve_for->setAccessible( true );

$_POST = [ 'county_id' => 255, 'city_id' => 255154, 'sector' => '3' ];
$resolved = $resolve_for->invoke( $metabox, new FakeOrderAddr() );
assert_same( '3', $resolved['sector'], 'posted sector: wins over the parsed sector' );
assert_same( true, $resolved['confident'], 'posted sector + resolved city_id: counts as confident' );

$_POST = [ 'county_id' => 255, 'city_id' => 255154, 'sector' => '9' ];
$resolved = $resolve_for->invoke( $metabox, new FakeOrderAddr() );
assert_same( '0', $resolved['sector'], 'invalid posted sector: ignored, falls back to the parsed/default value' );

unset( $_POST['county_id'], $_POST['city_id'], $_POST['sector'] );

// 3b) resolve_for(): non-RO order skips CityResolver entirely — city/postcode come
// from the order (or a posted override), never a numeric city_id.
$_POST = [];
$resolved = $resolve_for->invoke( $metabox, new FakeOrderAddr( 'DE' ) );
assert_same( 'DE', $resolved['country'], 'international: country carried through' );
assert_same( 0, $resolved['city_id'], 'international: no numeric city id' );
assert_same( true, $resolved['confident'], 'international: always confident (no resolver gate)' );
assert_same( 'Bucuresti', $resolved['city_text'], 'international: city_text defaults from the order' );
assert_same( true, AwbPayload::international_ready( $resolved ) === false, 'international: not ready without a postcode' );

$_POST = [ 'city_text' => 'Berlin', 'postcode' => '10115' ];
$resolved = $resolve_for->invoke( $metabox, new FakeOrderAddr( 'DE' ) );
assert_same( 'Berlin', $resolved['city_text'], 'international: a posted city_text override wins over the order city' );
assert_same( '10115', $resolved['postcode'], 'international: a posted postcode override wins' );
assert_same( true, AwbPayload::international_ready( $resolved ), 'international: ready once both are present' );

$_POST = [];

// 4) posted_package(): valid values pass through; out-of-range weight/dims are dropped
//    (never reach AwbPayload — a garbage packed weight/size must not silently ship).
$posted_package = new ReflectionMethod( AwbMetabox::class, 'posted_package' );
$posted_package->setAccessible( true );

$_POST = [ 'weight' => '0.5', 'length' => '30', 'width' => '20', 'height' => '15' ];
assert_same(
  [ 'weight' => 0.5, 'length' => 30, 'width' => 20, 'height' => 15 ],
  $posted_package->invoke( $metabox ),
  'posted_package: all valid values pass through'
);

$_POST = [ 'weight' => '0.01', 'length' => '0', 'width' => '999', 'height' => '15' ];
assert_same(
  [ 'height' => 15 ],
  $posted_package->invoke( $metabox ),
  'posted_package: out-of-range weight (0.01) and dims (0, 999) dropped; valid height kept'
);

$_POST = [ 'weight' => '500' ];
assert_same( [], $posted_package->invoke( $metabox ), 'posted_package: weight over 100 dropped' );

$_POST = [];
assert_same( [], $posted_package->invoke( $metabox ), 'posted_package: nothing posted -> empty' );

unset( $_POST['weight'], $_POST['length'], $_POST['width'], $_POST['height'] );

echo "smoke-awb-metabox-guard: all assertions passed\n";
