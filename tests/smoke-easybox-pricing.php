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

// negative cost never goes below 0
same( 0.0, EasyBoxPricing::price( -5.0, $cfg ), 'negative cost floored at 0' );

// fallback shape
$fb = EasyBoxPricing::fallback( EasyBoxPricing::config( [ 'fallback_amount' => '18.5', 'fallback_title' => 'Locker' ] ) );
same( 18.5, $fb['cost'], 'fallback cost' );
same( 'webbership_smartship_easybox', $fb['id'], 'fallback id' );
same( 'Locker', $fb['label'], 'fallback label' );

echo ( $fail === 0 ) ? "smoke-easybox-pricing: all $pass passed\n" : "smoke-easybox-pricing: $fail FAILED\n";
exit( $fail === 0 ? 0 : 1 );
