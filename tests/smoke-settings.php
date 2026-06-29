<?php
declare(strict_types=1);

// Run: php tests/smoke-settings.php

define( 'ABSPATH', __DIR__ );

$GLOBALS['ovride_options'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['ovride_options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['ovride_options'][ $k ] = $v; return true; }
function wp_parse_args( $a, $d ) { return array_merge( $d, is_array( $a ) ? $a : [] ); }
function __( $t, $d = 'default' ) { return $t; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }

function assert_true( bool $cond, string $msg ): void {
  if ( ! $cond ) { throw new RuntimeException( $msg ); }
}
function assert_same( $expected, $actual, string $msg ): void {
  if ( $expected !== $actual ) {
    throw new RuntimeException( $msg . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
  }
}

require_once __DIR__ . '/../includes/Settings/class-settings.php';

use Ovride\Smartship\Settings\Settings;

$s = new Settings();

// Blank api_key submission keeps the stored key; debug toggles on.
$GLOBALS['ovride_options'][ Settings::OPTION ] = [ 'api_key' => 'EXISTING', 'debug' => 'no' ];
$out = $s->sanitize( [ 'api_key' => '', 'debug' => 'yes' ] );
assert_same( 'EXISTING', $out['api_key'], 'blank submit keeps key' );
assert_same( 'yes', $out['debug'], 'debug toggled on' );

// A new key replaces and is trimmed; debug defaults to no.
$out = $s->sanitize( [ 'api_key' => '  NEWKEY  ' ] );
assert_same( 'NEWKEY', $out['api_key'], 'new key trimmed + stored' );
assert_same( 'no', $out['debug'], 'debug defaults no' );

// api_key() reads the DB...
$GLOBALS['ovride_options'][ Settings::OPTION ] = [ 'api_key' => 'DBKEY', 'debug' => 'no' ];
assert_same( 'DBKEY', Settings::api_key(), 'api_key from DB' );
assert_true( Settings::key_is_constant() === false, 'no constant yet' );

// ...but the wp-config constant overrides it.
define( 'OVRIDE_SMARTSHIP_API_KEY', 'CONSTKEY' );
assert_same( 'CONSTKEY', Settings::api_key(), 'constant overrides DB' );
assert_true( Settings::key_is_constant() === true, 'constant detected' );

// sender_id + iban round-trip through sanitize and accessors.
$out = $s->sanitize( [ 'api_key' => '', 'sender_id' => '8848', 'iban' => ' RO49AAAA1B31007593840000 ' ] );
assert_same( 8848, $out['sender_id'], 'sender_id sanitized to int' );
assert_same( 'RO49AAAA1B31007593840000', $out['iban'], 'iban trimmed' );
$GLOBALS['ovride_options'][ Settings::OPTION ] = [ 'api_key' => 'K', 'sender_id' => 8848, 'iban' => 'RO49AAAA1B31007593840000' ];
assert_same( 8848, Settings::sender_id(), 'sender_id accessor' );
assert_same( 'RO49AAAA1B31007593840000', Settings::iban(), 'iban accessor' );

echo "smoke-settings: all assertions passed\n";
