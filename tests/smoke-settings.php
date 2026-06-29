<?php
declare(strict_types=1);

// Run: php tests/smoke-settings.php

define( 'ABSPATH', __DIR__ );

$GLOBALS['ovride_options'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['ovride_options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['ovride_options'][ $k ] = $v; return true; }
function wp_parse_args( $a, $d ) { return array_merge( $d, is_array( $a ) ? $a : [] ); }
function __( $t, $d = 'default' ) { return $t; }

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

echo "smoke-settings: all assertions passed\n";
