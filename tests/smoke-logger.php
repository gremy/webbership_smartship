<?php
declare(strict_types=1);

// Run: php tests/smoke-logger.php

define( 'ABSPATH', __DIR__ );

$GLOBALS['webbership_ss_log'] = null; // last [ level, message, context ] recorded

function __( $text, $domain = 'default' ) { return $text; }
function wp_parse_args( $args, $defaults = [] ) { return array_merge( $defaults, (array) $args ); }
function get_option( $name, $default = false ) { return $GLOBALS['webbership_ss_options'][ $name ] ?? $default; }

class Webbership_SS_Test_Logger {
  public function log( $level, $message, $context ) {
    $GLOBALS['webbership_ss_log'] = [ 'level' => $level, 'message' => $message, 'context' => $context ];
  }
}
function wc_get_logger() { return new Webbership_SS_Test_Logger(); }

function assert_true( bool $cond, string $msg ): void {
  if ( ! $cond ) { throw new RuntimeException( $msg ); }
}
function assert_same( $expected, $actual, string $msg ): void {
  if ( $expected !== $actual ) {
    throw new RuntimeException( $msg . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
  }
}

require_once __DIR__ . '/../includes/Settings/class-settings.php';
require_once __DIR__ . '/../includes/class-logger.php';

use Webbership\Smartship\Logger;
use Webbership\Smartship\Settings\Settings;

// Seed the API key so Settings::api_key() returns SEKRET.
$GLOBALS['webbership_ss_options'][ Settings::OPTION ] = [ 'api_key' => 'SEKRET', 'debug' => 'no' ];
assert_same( 'SEKRET', Settings::api_key(), 'sanity: Settings::api_key()' );

// Log an error whose message AND context carry the key (nested too).
Logger::error( 'boom SEKRET', [
  'headers' => [ 'X-API-KEY' => 'SEKRET' ],
  'nested'  => [ 'k' => 'SEKRET' ],
  'count'   => 7, // non-string scalar must survive untouched
] );

$rec = $GLOBALS['webbership_ss_log'];
assert_true( null !== $rec, 'a record was logged' );

$blob = var_export( $rec['message'], true ) . var_export( $rec['context'], true );
assert_true( strpos( $blob, 'SEKRET' ) === false, 'no SEKRET anywhere in message or context' );
assert_true( strpos( $rec['message'], '***' ) !== false, 'message redacted to ***' );
assert_true( strpos( $rec['context']['headers']['X-API-KEY'], '***' ) !== false, 'context header redacted' );
assert_true( strpos( $rec['context']['nested']['k'], '***' ) !== false, 'nested context redacted' );
assert_same( 7, $rec['context']['count'], 'non-string scalar untouched' );

echo "smoke-logger: all assertions passed\n";
