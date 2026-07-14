<?php
declare(strict_types=1);

// Run: php tests/smoke-settings.php

define( 'ABSPATH', __DIR__ );

$GLOBALS['webbership_options'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['webbership_options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['webbership_options'][ $k ] = $v; return true; }
function wp_parse_args( $a, $d ) { return array_merge( $d, is_array( $a ) ? $a : [] ); }
function __( $t, $d = 'default' ) { return $t; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function add_settings_error( $setting, $code, $message, $type = 'error' ) {}
function sanitize_textarea_field( $v ) { return trim( (string) $v ); }

function assert_true( bool $cond, string $msg ): void {
  if ( ! $cond ) { throw new RuntimeException( $msg ); }
}
function assert_same( $expected, $actual, string $msg ): void {
  if ( $expected !== $actual ) {
    throw new RuntimeException( $msg . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
  }
}

require_once __DIR__ . '/../includes/Settings/class-settings.php';

use Webbership\Smartship\Settings\Settings;

$s = new Settings();

// Blank api_key submission keeps the stored key; debug toggles on.
$GLOBALS['webbership_options'][ Settings::OPTION ] = [ 'api_key' => 'EXISTING', 'debug' => 'no' ];
$out = $s->sanitize( [ 'api_key' => '', 'debug' => 'yes' ] );
assert_same( 'EXISTING', $out['api_key'], 'blank submit keeps key' );
assert_same( 'yes', $out['debug'], 'debug toggled on' );

// A new key replaces and is trimmed; debug defaults to no.
$out = $s->sanitize( [ 'api_key' => '  NEWKEY  ' ] );
assert_same( 'NEWKEY', $out['api_key'], 'new key trimmed + stored' );
assert_same( 'no', $out['debug'], 'debug defaults no' );

// api_key() reads the DB...
$GLOBALS['webbership_options'][ Settings::OPTION ] = [ 'api_key' => 'DBKEY', 'debug' => 'no' ];
assert_same( 'DBKEY', Settings::api_key(), 'api_key from DB' );
assert_true( Settings::key_is_constant() === false, 'no constant yet' );

// ...but the wp-config constant overrides it.
define( 'WEBBERSHIP_SMARTSHIP_API_KEY', 'CONSTKEY' );
assert_same( 'CONSTKEY', Settings::api_key(), 'constant overrides DB' );
assert_true( Settings::key_is_constant() === true, 'constant detected' );

// sender_id + iban round-trip through sanitize and accessors.
$out = $s->sanitize( [ 'api_key' => '', 'sender_id' => '8848', 'iban' => ' RO49AAAA1B31007593840000 ' ] );
assert_same( 8848, $out['sender_id'], 'sender_id sanitized to int' );
assert_same( 'RO49AAAA1B31007593840000', $out['iban'], 'iban trimmed' );
$GLOBALS['webbership_options'][ Settings::OPTION ] = [ 'api_key' => 'K', 'sender_id' => 8848, 'iban' => 'RO49AAAA1B31007593840000' ];
assert_same( 8848, Settings::sender_id(), 'sender_id accessor' );
assert_same( 'RO49AAAA1B31007593840000', Settings::iban(), 'iban accessor' );

// IBAN validation: a valid RO IBAN is kept; an invalid one keeps the previously-stored value.
$GLOBALS['webbership_options'][ Settings::OPTION ] = [ 'api_key' => 'K', 'iban' => 'RO11BBBB1B31007593840000' ];
$out = $s->sanitize( [ 'api_key' => '', 'iban' => 'RO49AAAA1B31007593840000' ] );
assert_same( 'RO49AAAA1B31007593840000', $out['iban'], 'valid IBAN kept' );
$out = $s->sanitize( [ 'api_key' => '', 'iban' => 'RO123' ] );
assert_same( 'RO11BBBB1B31007593840000', $out['iban'], 'invalid IBAN keeps previous value' );
// A blank IBAN clears.
$out = $s->sanitize( [ 'api_key' => '', 'iban' => '' ] );
assert_same( '', $out['iban'], 'blank IBAN clears' );

// boxes: sanitize() stores the raw textarea; absent input keeps the current value.
$GLOBALS['webbership_options'][ Settings::OPTION ] = [ 'api_key' => 'K', 'boxes' => 'Old | 10x10x10' ];
$out = $s->sanitize( [ 'api_key' => '', 'boxes' => "Cutie M | 30x20x15 | 0.35\nCutie L | 40x30x20" ] );
assert_same( "Cutie M | 30x20x15 | 0.35\nCutie L | 40x30x20", $out['boxes'], 'boxes: stored verbatim (trimmed)' );
$out = $s->sanitize( [ 'api_key' => '' ] );
assert_same( 'Old | 10x10x10', $out['boxes'], 'boxes: absent input keeps current value' );

// parse_boxes(): pure parser, no WP required.
assert_same( [], Settings::parse_boxes( '' ), 'parse_boxes: empty string -> []' );
assert_same( [], Settings::parse_boxes( "\n  \n" ), 'parse_boxes: blank lines skipped' );

$one = Settings::parse_boxes( 'Cutie M | 30x20x15 | 0.35' );
assert_same( 1, count( $one ), 'parse_boxes: one box parsed' );
assert_same( [ 'name' => 'Cutie M', 'length' => 30, 'width' => 20, 'height' => 15, 'weight' => 0.35 ], $one[0], 'parse_boxes: happy line' );

$comma = Settings::parse_boxes( 'Cutie M | 30x20x15 | 0,35' );
assert_same( 0.35, $comma[0]['weight'], 'parse_boxes: comma decimal weight' );

$times = Settings::parse_boxes( 'Cutie L | 40×30×20' );
assert_same( [ 'name' => 'Cutie L', 'length' => 40, 'width' => 30, 'height' => 20, 'weight' => 0.0 ], $times[0], 'parse_boxes: × separator, missing weight -> 0.0' );

$kg_suffix = Settings::parse_boxes( 'Cutie S | 20x15x10 | 0.3kg' );
assert_same( 0.3, $kg_suffix[0]['weight'], 'parse_boxes: trailing "kg" stripped' );

$multi = Settings::parse_boxes( "garbage line with no pipes\nCutie M | 30x20x15 | 0.35\n\n| no name\nCutie L | not-dims | 1\nCutie XL | 50x40x30" );
assert_same( 2, count( $multi ), 'parse_boxes: garbage lines skipped, valid ones kept' );
assert_same( 'Cutie M', $multi[0]['name'], 'parse_boxes: first valid line kept' );
assert_same( 'Cutie XL', $multi[1]['name'], 'parse_boxes: second valid line kept' );

// boxes() reads the stored option through parse_boxes().
$GLOBALS['webbership_options'][ Settings::OPTION ] = [ 'api_key' => 'K', 'boxes' => 'Cutie M | 30x20x15 | 0.35' ];
$boxes = Settings::boxes();
assert_same( 1, count( $boxes ), 'boxes(): reads and parses the stored option' );
assert_same( 'Cutie M', $boxes[0]['name'], 'boxes(): parsed name' );

echo "smoke-settings: all assertions passed\n";
