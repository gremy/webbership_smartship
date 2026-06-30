<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ );
function __( $s, $d = null ) { return $s; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
require __DIR__ . '/../modules/easybox/class-easybox-pricing.php';
use Webbership\Smartship\Modules\EasyBox\EasyBoxPricing;

$pass = 0; $fail = 0;
function same( $a, $b, $m ) {
  global $pass, $fail;
  if ( $a === $b ) { $pass++; } else { $fail++; echo "FAIL $m: " . var_export( $a, true ) . " !== " . var_export( $b, true ) . "\n"; }
}

// default factor 0.80
$cfg = EasyBoxPricing::config( [] );
same( 0.80, $cfg['factor'], 'default factor 0.80' );
same( 20.73, EasyBoxPricing::price( 25.91, $cfg ), 'price = 25.91 * 0.80 rounded' );

// percent setting -> factor; clamped to a sane range
same( 0.65, EasyBoxPricing::config( [ 'easybox_factor' => '65' ] )['factor'], 'percent 65 -> 0.65' );
same( 0.10, EasyBoxPricing::config( [ 'easybox_factor' => '0' ] )['factor'], 'clamp low to 0.10' );
same( 3.00, EasyBoxPricing::config( [ 'easybox_factor' => '9999' ] )['factor'], 'clamp high to 3.00' );

// blank / non-numeric factor → DEFAULT (not 0.10); explicit numeric '0' clamps to floor
same( 0.80, EasyBoxPricing::config( [ 'easybox_factor' => '' ] )['factor'], 'blank factor -> default 0.80' );
same( 0.80, EasyBoxPricing::config( [ 'easybox_factor' => '  ' ] )['factor'], 'whitespace factor -> default' );
same( 0.80, EasyBoxPricing::config( [ 'easybox_factor' => null ] )['factor'], 'null factor -> default' );
same( 0.80, EasyBoxPricing::config( [ 'easybox_factor' => 'abc' ] )['factor'], 'non-numeric factor -> default' );
same( 0.10, EasyBoxPricing::config( [ 'easybox_factor' => '0' ] )['factor'], 'explicit 0 clamps to floor' );

// title: default + sanitize (trim)
same( 'Ridicare Sameday Point / EasyBox', EasyBoxPricing::config( [] )['title'], 'title default' );
same( 'Pickup', EasyBoxPricing::config( [ 'title' => '  Pickup  ' ] )['title'], 'title trimmed' );

// negative fallback amount floored at 0
same( 0.0, EasyBoxPricing::config( [ 'fallback_amount' => '-9' ] )['fallback'], 'negative fallback floored' );

// negative cost never goes below 0
same( 0.0, EasyBoxPricing::price( -5.0, $cfg ), 'negative cost floored at 0' );

// fallback shape
$fb = EasyBoxPricing::fallback( EasyBoxPricing::config( [ 'fallback_amount' => '18.5', 'fallback_title' => 'Locker' ] ) );
same( 18.5, $fb['cost'], 'fallback cost' );
same( 'webbership_smartship_easybox', $fb['id'], 'fallback id' );
same( 'Locker', $fb['label'], 'fallback label' );

echo ( $fail === 0 ) ? "smoke-easybox-pricing: all $pass passed\n" : "smoke-easybox-pricing: $fail FAILED\n";
exit( $fail === 0 ? 0 : 1 );
