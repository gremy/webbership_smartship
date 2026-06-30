<?php
declare(strict_types=1);
// Run: php tests/smoke-easybox-order.php
define( 'ABSPATH', __DIR__ );

function __( $t, $d = 'default' ) { return $t; }
function wp_unslash( $v ) { return $v; }
// Minimal sanitize_text_field: strip tags + collapse/trim whitespace (enough to prove XSS can't survive).
function sanitize_text_field( $s ) {
  if ( ! is_string( $s ) ) { return ''; }
  $s = strip_tags( $s );
  $s = preg_replace( '/[\r\n\t ]+/', ' ', $s );
  return trim( (string) $s );
}

$GLOBALS['ss_user_meta'] = [];
$GLOBALS['ss_logged_in'] = true;
function is_user_logged_in() { return (bool) $GLOBALS['ss_logged_in']; }
function get_current_user_id() { return 42; }
function update_user_meta( $uid, $key, $val ) { $GLOBALS['ss_user_meta'][] = [ $uid, $key, $val ]; return true; }

function assert_true( bool $c, string $m ): void { if ( ! $c ) { throw new RuntimeException( $m ); } }
function assert_same( $e, $a, string $m ): void { if ( $e !== $a ) { throw new RuntimeException( $m . ': expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) ); } }

require_once __DIR__ . '/../modules/easybox/class-easybox-pricing.php';
require_once __DIR__ . '/../modules/easybox/class-easybox-order.php';

use Webbership\Smartship\Modules\EasyBox\EasyBoxOrder;

/** Order double: only update_meta_data() is exercised; records into a map. */
class FakeOrder {
  public array $meta = [];
  public int $save_calls = 0;
  public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
  public function save() { $this->save_calls++; }
}

/** WP_Error double: add() records code+message. */
class FakeErrors {
  public array $errors = [];
  public function add( $code, $msg ) { $this->errors[] = [ $code, $msg ]; }
}

$method_id = \Webbership\Smartship\Modules\EasyBox\EasyBoxPricing::METHOD_ID;
$valid_json = json_encode( [
  'id'      => 1234,
  'name'    => 'EasyBox Mega Mall',
  'city'    => 'București',
  'address' => 'Bd. Splaiul Unirii 4',
  'lat'     => 44.4,
  'lng'     => 26.1,
] );

function reset_env(): void {
  $GLOBALS['ss_user_meta'] = [];
  $GLOBALS['ss_logged_in'] = true;
  unset( $_POST['webbership_ss_locker'] );
}

$order = new EasyBoxOrder();

// ---------------------------------------------------------------------------
// 1) Valid locker + EasyBox chosen -> save() writes meta + user meta.
// ---------------------------------------------------------------------------
reset_env();
$_POST['webbership_ss_locker'] = $valid_json;
$data = [ 'shipping_method' => [ $method_id . ':3' ] ];

$wc_order = new FakeOrder();
$order->save( $wc_order, $data );
assert_same( 1234, $wc_order->meta['_webbership_smartship_easybox_id'], 'save: id meta' );
assert_same( 'EasyBox Mega Mall', $wc_order->meta['_webbership_smartship_easybox_name'], 'save: name meta' );
assert_same( 'Bd. Splaiul Unirii 4', $wc_order->meta['_webbership_smartship_easybox_address'], 'save: address meta' );
assert_same( 'București', $wc_order->meta['_webbership_smartship_easybox_city'], 'save: city meta' );
assert_same( 0, $wc_order->save_calls, 'save: does NOT call $order->save() (WC saves after this hook)' );
assert_same( 1, count( $GLOBALS['ss_user_meta'] ), 'save: preferred-locker user meta written once' );
assert_same( '_webbership_smartship_preferred_locker', $GLOBALS['ss_user_meta'][0][1], 'save: preferred meta key' );
assert_same( 1234, $GLOBALS['ss_user_meta'][0][2]['id'], 'save: preferred snapshot id' );

// validate() with a valid locker adds no error.
$errs = new FakeErrors();
$order->validate( $data, $errs );
assert_same( 0, count( $errs->errors ), 'validate: valid locker -> no error' );

// ---------------------------------------------------------------------------
// 2) EasyBox chosen, missing locker -> validate() errors; save() writes nothing.
// ---------------------------------------------------------------------------
reset_env(); // no $_POST['webbership_ss_locker']
$errs = new FakeErrors();
$order->validate( $data, $errs );
assert_same( 1, count( $errs->errors ), 'validate: missing locker -> one error' );
assert_same( 'webbership_ss_locker', $errs->errors[0][0], 'validate: error code' );

$wc_order = new FakeOrder();
$order->save( $wc_order, $data );
assert_same( 0, count( $wc_order->meta ), 'save: missing locker -> no meta' );
assert_same( 0, count( $GLOBALS['ss_user_meta'] ), 'save: missing locker -> no user meta' );

// Empty string also rejected.
reset_env();
$_POST['webbership_ss_locker'] = '   ';
$errs = new FakeErrors();
$order->validate( $data, $errs );
assert_same( 1, count( $errs->errors ), 'validate: blank locker -> one error' );

// ---------------------------------------------------------------------------
// 3) Malformed / hostile input -> rejected or sanitized (no script survives).
// ---------------------------------------------------------------------------
// Malformed JSON.
reset_env();
$_POST['webbership_ss_locker'] = '{not json';
$errs = new FakeErrors();
$order->validate( $data, $errs );
assert_same( 1, count( $errs->errors ), 'validate: malformed JSON -> error' );

// Non-positive id.
reset_env();
$_POST['webbership_ss_locker'] = json_encode( [ 'id' => 0, 'name' => 'X', 'city' => 'Y', 'address' => 'Z' ] );
$errs = new FakeErrors();
$order->validate( $data, $errs );
assert_same( 1, count( $errs->errors ), 'validate: id<=0 -> error' );

// Non-int id (string).
reset_env();
$_POST['webbership_ss_locker'] = json_encode( [ 'id' => 'abc', 'name' => 'X', 'city' => 'Y', 'address' => 'Z' ] );
$errs = new FakeErrors();
$order->validate( $data, $errs );
assert_same( 1, count( $errs->errors ), 'validate: non-int id -> error' );

// Stricter trust-boundary edges: leading-zero / overflow / float / scientific id,
// non-string text field, non-numeric lat/lng -> all rejected (no coercion).
$bad = array(
  'leading-zero id' => array( 'id' => '01', 'name' => 'X', 'city' => 'Y', 'address' => 'Z' ),
  'overflow id'     => array( 'id' => '999999999999999999999999', 'name' => 'X', 'city' => 'Y', 'address' => 'Z' ),
  'float id'        => array( 'id' => 1.5, 'name' => 'X', 'city' => 'Y', 'address' => 'Z' ),
  'scientific id'   => array( 'id' => '1e3', 'name' => 'X', 'city' => 'Y', 'address' => 'Z' ),
  'array name'      => array( 'id' => 5, 'name' => array( 'x' ), 'city' => 'Y', 'address' => 'Z' ),
  'non-numeric lat' => array( 'id' => 5, 'name' => 'X', 'city' => 'Y', 'address' => 'Z', 'lat' => 'abc' ),
  'array lng'       => array( 'id' => 5, 'name' => 'X', 'city' => 'Y', 'address' => 'Z', 'lng' => array() ),
);
foreach ( $bad as $label => $payload ) {
  reset_env();
  $_POST['webbership_ss_locker'] = json_encode( $payload );
  $errs = new FakeErrors();
  $order->validate( $data, $errs );
  assert_same( 1, count( $errs->errors ), 'validate: rejects ' . $label );
}

// A canonical digit-string id IS accepted (no false negatives).
reset_env();
$_POST['webbership_ss_locker'] = json_encode( array( 'id' => '42', 'name' => 'X', 'city' => 'Y', 'address' => 'Z' ) );
$errs = new FakeErrors();
$order->validate( $data, $errs );
assert_same( 0, count( $errs->errors ), 'validate: canonical digit-string id "42" accepted' );

// Missing required field (no address).
reset_env();
$_POST['webbership_ss_locker'] = json_encode( [ 'id' => 5, 'name' => 'X', 'city' => 'Y' ] );
$errs = new FakeErrors();
$order->validate( $data, $errs );
assert_same( 1, count( $errs->errors ), 'validate: missing address -> error' );

// XSS-y name: locker is otherwise valid -> accepted, but the script tag must NOT survive into meta.
reset_env();
$_POST['webbership_ss_locker'] = json_encode( [
  'id'      => 77,
  'name'    => '<script>alert(1)</script>Locker',
  'city'    => 'Cluj<img src=x onerror=alert(1)>',
  'address' => 'Str. A 1',
] );
$wc_order = new FakeOrder();
$order->save( $wc_order, $data );
assert_true( isset( $wc_order->meta['_webbership_smartship_easybox_name'] ), 'xss: locker accepted' );
assert_true( strpos( $wc_order->meta['_webbership_smartship_easybox_name'], '<script' ) === false, 'xss: no <script in name' );
assert_true( strpos( $wc_order->meta['_webbership_smartship_easybox_city'], '<img' ) === false, 'xss: no <img in city' );
assert_same( 'alert(1)Locker', $wc_order->meta['_webbership_smartship_easybox_name'], 'xss: name sanitized to text' );

// ---------------------------------------------------------------------------
// 4) Non-EasyBox method chosen -> neither validate nor save touch anything,
//    even with a valid locker posted.
// ---------------------------------------------------------------------------
reset_env();
$_POST['webbership_ss_locker'] = $valid_json;
$other = [ 'shipping_method' => [ 'flat_rate:1' ] ];

$errs = new FakeErrors();
$order->validate( $other, $errs );
assert_same( 0, count( $errs->errors ), 'non-easybox: no validation error' );

$wc_order = new FakeOrder();
$order->save( $wc_order, $other );
assert_same( 0, count( $wc_order->meta ), 'non-easybox: no order meta' );
assert_same( 0, count( $GLOBALS['ss_user_meta'] ), 'non-easybox: no user meta' );

// Absent shipping_method key -> treated as not-chosen (defensive).
reset_env();
$_POST['webbership_ss_locker'] = $valid_json;
$errs = new FakeErrors();
$order->validate( [], $errs );
assert_same( 0, count( $errs->errors ), 'no shipping_method key: no error' );

// Scalar shipping_method (defensive) matching the bare method id.
reset_env();
$_POST['webbership_ss_locker'] = $valid_json;
$wc_order = new FakeOrder();
$order->save( $wc_order, [ 'shipping_method' => $method_id ] );
assert_same( 1234, $wc_order->meta['_webbership_smartship_easybox_id'], 'scalar bare method id -> chosen' );

// ---------------------------------------------------------------------------
// 5) Logged-out: order meta still saved, but no preferred-locker user meta.
// ---------------------------------------------------------------------------
reset_env();
$GLOBALS['ss_logged_in'] = false;
$_POST['webbership_ss_locker'] = $valid_json;
$wc_order = new FakeOrder();
$order->save( $wc_order, $data );
assert_same( 1234, $wc_order->meta['_webbership_smartship_easybox_id'], 'logged-out: order meta saved' );
assert_same( 0, count( $GLOBALS['ss_user_meta'] ), 'logged-out: no preferred-locker user meta' );

echo "smoke-easybox-order: all assertions passed\n";
