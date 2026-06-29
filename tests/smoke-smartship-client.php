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

function ss_set_response( $http_code, $body ): void {
  $GLOBALS['ovride_ss_http'] = [
    'response' => [ 'code' => $http_code ],
    'body'     => is_string( $body ) ? $body : json_encode( $body ),
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

echo "smoke-smartship-client: all assertions passed\n";
