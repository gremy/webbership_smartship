<?php
declare(strict_types=1);

// Run: php tests/smoke-pick-sender.php

define( 'ABSPATH', __DIR__ );

$GLOBALS['webbership_options'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['webbership_options'][ $k ] ?? $d; }
function wp_parse_args( $a, $d ) { return array_merge( $d, is_array( $a ) ? $a : [] ); }
function __( $t, $d = 'default' ) { return $t; }

function assert_same( $expected, $actual, string $msg ): void {
  if ( $expected !== $actual ) {
    throw new RuntimeException( $msg . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
  }
}

require_once __DIR__ . '/../includes/Settings/class-settings.php';
require_once __DIR__ . '/../modules/awb/admin/class-awb-metabox.php';

use Webbership\Smartship\Settings\Settings;
use Webbership\Smartship\Modules\Awb\Admin\AwbMetabox;

$senders = [
  [ 'id' => 1, 'nume' => 'Roastery' ],
  [ 'id' => 2, 'nume' => 'Coffee shop' ],
];

// Settings default is sender 1.
$GLOBALS['webbership_options'][ Settings::OPTION ] = [ 'sender_id' => 1 ];

assert_same( 2, AwbMetabox::pick_sender( $senders, 2 )['id'], 'posted per-order choice wins' );
assert_same( 1, AwbMetabox::pick_sender( $senders, 0 )['id'], 'no posted choice falls back to settings default' );
assert_same( 1, AwbMetabox::pick_sender( $senders, 99 )['id'], 'unknown posted id falls back to settings default' );

// Stale settings default (sender removed from the account) → first sender.
$GLOBALS['webbership_options'][ Settings::OPTION ] = [ 'sender_id' => 99 ];
assert_same( 1, AwbMetabox::pick_sender( $senders, 0 )['id'], 'stale default falls back to first sender' );

assert_same( [], AwbMetabox::pick_sender( [], 5 ), 'no senders yields empty array' );

echo "smoke-pick-sender: all assertions passed\n";
