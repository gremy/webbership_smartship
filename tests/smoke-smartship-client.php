<?php
declare(strict_types=1);

// Run: php tests/smoke-smartship-client.php

define( 'ABSPATH', __DIR__ );

$GLOBALS['ovride_ss_http']         = null; // canned response for the next call
$GLOBALS['ovride_ss_last_request'] = null;

function __( $text, $domain = 'default' ) { return $text; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function add_query_arg( $args, $url ) {
  $sep = ( strpos( (string) $url, '?' ) === false ) ? '?' : '&';
  return $url . $sep . http_build_query( $args );
}
function wp_json_encode( $data ) { return json_encode( $data ); }
function get_bloginfo( $k ) { return 'Test Shop'; }
function home_url() { return 'https://shop.test'; }
function wp_specialchars_decode( $s, $q = null ) { return $s; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $ttl ) { return true; }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
function wp_remote_get( $url, $args = [] ) { return wp_remote_request( $url, $args ); }
function wp_remote_retrieve_header( $r, $h ) { return is_array( $r ) ? ( $r['headers'][ strtolower( $h ) ] ?? '' ) : ''; }

class WP_Error {
  private $code; private $message;
  public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
  public function get_error_code() { return $this->code; }
  public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function wp_remote_request( $url, $args = [] ) {
  $GLOBALS['ovride_ss_last_request'] = [ 'url' => $url, 'args' => $args ];
  return $GLOBALS['ovride_ss_http'];
}
function wp_remote_post( $url, $args = [] ) { return wp_remote_request( $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }

function ss_set_response( $http_code, $body, $headers = [] ) {
  $GLOBALS['ovride_ss_http'] = [
    'response' => [ 'code' => $http_code ],
    'body'     => is_string( $body ) ? $body : json_encode( $body ),
    'headers'  => array_change_key_case( $headers, CASE_LOWER ),
  ];
}
function assert_true( bool $cond, string $msg ): void {
  if ( ! $cond ) { throw new RuntimeException( $msg ); }
}
function assert_same( $expected, $actual, string $msg ): void {
  if ( $expected !== $actual ) {
    throw new RuntimeException( $msg . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
  }
}

require_once __DIR__ . '/../includes/Api/class-smartship-client.php';

use Ovride\Smartship\Api\SmartShipClient;

$client = new SmartShipClient( 'secret-key-123' );

// 1) Success: HTTP 200 + body status 200 -> ok true, payload merged.
ss_set_response( 200, [ 'status' => 200, 'response' => [ 'costs' => [] ] ] );
$r = $client->request( 'POST', '/cost', [ 'body' => [ 'a' => 1 ] ] );
assert_true( true === $r['ok'], 'success: ok true' );
assert_same( 200, $r['status'], 'success: status 200' );
assert_true( isset( $r['response'] ), 'success: payload merged' );

// 2) HTTP 200 + body status 999 (validation) -> ok false, errors carried.
ss_set_response( 200, [ 'status' => 999, 'message' => 'bad', 'erori' => [ [ 'id' => 1, 'message' => 'x' ] ] ] );
$r = $client->request( 'POST', '/awb/new', [ 'body' => [] ] );
assert_true( false === $r['ok'], 'validation: ok false on body 999' );
assert_same( 'validation', $r['code'], 'validation: code' );
assert_same( 1, count( $r['errors'] ), 'validation: errors carried' );

// 3) HTTP 200 + body status 205 -> ok false, iban_missing.
ss_set_response( 200, [ 'status' => 205, 'message' => 'iban' ] );
$r = $client->request( 'POST', '/awb/new', [ 'body' => [] ] );
assert_true( false === $r['ok'], 'iban: ok false' );
assert_same( 'iban_missing', $r['code'], 'iban: code' );

// 4) Transport error -> ok false, status 0.
$GLOBALS['ovride_ss_http'] = new WP_Error( 'http_request_failed', 'down' );
$r = $client->request( 'GET', '/geolocation/counties' );
assert_true( false === $r['ok'], 'transport: ok false' );
assert_same( 0, $r['status'], 'transport: status 0' );
assert_same( 'transport_error', $r['code'], 'transport: code' );

// 5) Invalid JSON body -> ok false, invalid_json.
ss_set_response( 200, 'not json' );
$r = $client->request( 'GET', '/geolocation/counties' );
assert_true( false === $r['ok'], 'invalid_json: ok false' );
assert_same( 'invalid_json', $r['code'], 'invalid_json: code' );

// 5b) HTTP 200 + non-integer body status "200abc" -> ok false (must not coerce to 200).
ss_set_response( 200, [ 'status' => '200abc', 'message' => 'garbage' ] );
$r = $client->request( 'POST', '/cost', [ 'body' => [] ] );
assert_true( false === $r['ok'], 'non-int status: ok false on "200abc"' );

// 6) validate_credentials hits /account/senders; key in header, NEVER in the URL; timeout bounded.
ss_set_response( 200, [ 'status' => 200, 'senders' => [] ] );
$r   = $client->validate_credentials();
$req = $GLOBALS['ovride_ss_last_request'];
assert_true( true === $r['ok'], 'validate: ok' );
assert_true( strpos( $req['url'], '/account/senders' ) !== false, 'validate: hits senders' );
assert_true( strpos( $req['url'], 'secret-key-123' ) === false, 'security: key NOT in URL' );
assert_same( 'secret-key-123', $req['args']['headers']['X-API-KEY'], 'security: key in header' );
assert_true( $req['args']['timeout'] <= SmartShipClient::TIMEOUT, 'timeout <= TIMEOUT' );

// 7) shop headers only when asked.
ss_set_response( 200, [ 'status' => 200 ] );
$client->request( 'POST', '/cost', [ 'body' => [], 'shop_headers' => true ] );
assert_true( isset( $GLOBALS['ovride_ss_last_request']['args']['headers']['X-Shop-Url'] ), 'shop headers on /cost' );
$client->request( 'GET', '/geolocation/counties' );
assert_true( ! isset( $GLOBALS['ovride_ss_last_request']['args']['headers']['X-Shop-Url'] ), 'no shop headers elsewhere' );

// 8) get_counties hits the counties endpoint with country=RO.
ss_set_response( 200, [ 'status' => 200, 'counties' => [ [ 'id' => 1, 'county' => 'Alba' ] ] ] );
$r = $client->get_counties();
$req = $GLOBALS['ovride_ss_last_request'];
assert_true( $r['ok'] === true, 'counties: ok' );
assert_true( strpos( $req['url'], '/geolocation/counties' ) !== false, 'counties: endpoint' );
assert_true( strpos( $req['url'], 'country=RO' ) !== false, 'counties: country param' );

// 9) get_cities hits cities with the county id.
ss_set_response( 200, [ 'status' => 200, 'cities' => [ [ 'id' => 251695, 'city' => 'Abrud' ] ] ] );
$client->get_cities( 7 );
assert_true( strpos( $GLOBALS['ovride_ss_last_request']['url'], 'county=7' ) !== false, 'cities: county id' );

// 10) cost sends shop headers; create_awb does not.
ss_set_response( 200, [ 'status' => 200, 'costs' => [] ] );
$client->cost( [ 'recipient' => [], 'sender' => [], 'content' => [] ] );
assert_true( isset( $GLOBALS['ovride_ss_last_request']['args']['headers']['X-Shop-Url'] ), 'cost: shop headers' );
// cost() honors a custom timeout (RATE_TIMEOUT at checkout).
ss_set_response( 200, [ 'status' => 200, 'costs' => [] ] );
$client->cost( [ 'recipient' => [], 'sender' => [], 'content' => [] ], 3 );
assert_same( 3, $GLOBALS['ovride_ss_last_request']['args']['timeout'], 'cost: custom timeout' );
ss_set_response( 200, [ 'status' => 200, 'awb' => 'AWB1' ] );
$client->create_awb( [ 'recipient' => [], 'sender' => [], 'content' => [], 'courier_id' => 2 ] );
assert_true( ! isset( $GLOBALS['ovride_ss_last_request']['args']['headers']['X-Shop-Url'] ), 'create_awb: no shop headers' );

// 11) get_awb_status / cancel_awb rawurlencode the awb in the path.
ss_set_response( 200, [ 'status' => 200, 'history' => [] ] );
$client->get_awb_status( 'A B/2' );
assert_true( strpos( $GLOBALS['ovride_ss_last_request']['url'], '/awb/status/A%20B%2F2' ) !== false, 'status: rawurlencoded' );

// 12) print_awb returns the PDF on a %PDF body...
ss_set_response( 200, '%PDF-1.4 ...binary...', [ 'Content-Type' => 'application/pdf' ] );
$p = $client->print_awb( 'AWB1', 'A6' );
assert_true( $p['ok'] === true, 'print: ok on pdf' );
assert_true( isset( $p['pdf'] ) && strncmp( $p['pdf'], '%PDF', 4 ) === 0, 'print: pdf bytes' );
assert_true( strpos( $GLOBALS['ovride_ss_last_request']['url'], '/awb/print/AWB1/A6' ) !== false, 'print: url+format' );

// 13) ...and the error tuple on a JSON (in-body status) body.
ss_set_response( 200, [ 'status' => 999, 'message' => 'bad' ], [ 'Content-Type' => 'application/json' ] );
$p = $client->print_awb( 'AWB1' );
assert_true( $p['ok'] === false, 'print: ok false on json' );
assert_same( 'validation', $p['code'], 'print: maps 999' );

// 13b) print_awb requires the %PDF magic: a pdf content-type header over a non-%PDF body is NOT a PDF.
ss_set_response( 200, [ 'status' => 999 ], [ 'Content-Type' => 'application/pdf' ] );
$p = $client->print_awb( 'AWB1' );
assert_true( $p['ok'] === false, 'print: ok false when content-type pdf but body is not %PDF' );

// 14) print_awb rejects a bad format (defaults to A4).
ss_set_response( 200, '%PDF-1.4', [ 'Content-Type' => 'application/pdf' ] );
$client->print_awb( 'AWB1', 'A5' );
assert_true( strpos( $GLOBALS['ovride_ss_last_request']['url'], '/awb/print/AWB1/A4' ) !== false, 'print: bad format -> A4' );

// 15) cancel_awb treats an empty 200 body as a (best-effort) success.
// SmartShip's /awb/cancel returns an empty 200 and does not actually cancel.
ss_set_response( 200, '' );
$c = $client->cancel_awb( 'AWB1' );
assert_true( $c['ok'] === true, 'cancel: ok on empty 200' );
assert_true( strpos( $GLOBALS['ovride_ss_last_request']['url'], '/awb/cancel/AWB1' ) !== false, 'cancel: url' );

// 16) cancel_awb with a JSON body keeps the strict integer-200 success rule.
ss_set_response( 200, [ 'status' => '200abc' ] );
$c = $client->cancel_awb( 'AWB1' );
assert_true( $c['ok'] === false, 'cancel: non-int status "200abc" is not success' );
ss_set_response( 200, [ 'status' => 999, 'message' => 'no' ] );
$c = $client->cancel_awb( 'AWB1' );
assert_true( $c['ok'] === false, 'cancel: body status 999 -> ok false' );

echo "smoke-smartship-client: all assertions passed\n";
