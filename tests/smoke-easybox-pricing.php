<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ );
function __( $s, $d = null ) { return $s; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
require __DIR__ . '/../modules/checkout-rates/class-rate-calculator.php';
require __DIR__ . '/../modules/easybox/class-easybox-pricing.php';
use Webbership\Smartship\Modules\EasyBox\EasyBoxPricing;

$pass = 0; $fail = 0;
function same( $a, $b, $m ) {
  global $pass, $fail;
  if ( $a === $b ) { $pass++; } else { $fail++; echo "FAIL $m: " . var_export( $a, true ) . " !== " . var_export( $b, true ) . "\n"; }
}

// config(): no more factor/easybox_factor — pricing is a live /cost quote now.
$cfg = EasyBoxPricing::config( [] );
same( false, array_key_exists( 'factor', $cfg ), 'config: no factor key anymore' );
same( false, array_key_exists( 'easybox_factor', $cfg ), 'config: no easybox_factor key anymore' );
same( 'Ridicare Sameday Point / EasyBox', $cfg['title'], 'title default' );
same( 0.0, $cfg['fallback_amount'], 'fallback_amount default 0' );
same( 0.0, $cfg['fallback_per_kg_amount'], 'fallback_per_kg_amount default 0' );
same( 'Ridicare Sameday Point / EasyBox', $cfg['fallback_title'], 'fallback_title default' );

// title: sanitize (trim)
same( 'Pickup', EasyBoxPricing::config( [ 'title' => '  Pickup  ' ] )['title'], 'title trimmed' );

// fallback_amount / fallback_per_kg_amount: parsed, floored at 0 (never negative)
same( 25.0, EasyBoxPricing::config( [ 'fallback_amount' => '25' ] )['fallback_amount'], 'fallback_amount parsed' );
same( 0.0, EasyBoxPricing::config( [ 'fallback_amount' => '-9' ] )['fallback_amount'], 'negative fallback_amount floored at 0' );
same( 15.0, EasyBoxPricing::config( [ 'fallback_per_kg_amount' => '15' ] )['fallback_per_kg_amount'], 'fallback_per_kg_amount parsed' );
same( 0.0, EasyBoxPricing::config( [ 'fallback_per_kg_amount' => '-5' ] )['fallback_per_kg_amount'], 'negative fallback_per_kg_amount floored at 0' );

// fallback(): weight-aware, shares RateCalculator::fallback_rate()'s base + per_kg * weight math.
$fb_cfg = EasyBoxPricing::config( [ 'fallback_amount' => '20', 'fallback_per_kg_amount' => '5', 'fallback_title' => 'Locker' ] );
$fb = EasyBoxPricing::fallback( $fb_cfg, 3.0 );
same( 35.0, $fb['cost'], 'fallback: base 20 + 5*3kg = 35' );
same( 'Locker', $fb['label'], 'fallback label' );

// fallback() with no weight arg defaults to 0kg -> just the base amount.
same( 20.0, EasyBoxPricing::fallback( $fb_cfg, 0.0 )['cost'], 'fallback: 0kg -> base amount only' );

// fallback() never goes negative even with weird input (RateCalculator floors internally).
$zero_cfg = EasyBoxPricing::config( [] );
same( 0.0, EasyBoxPricing::fallback( $zero_cfg, 5.0 )['cost'], 'fallback: all-zero config -> 0 cost' );

echo ( $fail === 0 ) ? "smoke-easybox-pricing: all $pass passed\n" : "smoke-easybox-pricing: $fail FAILED\n";
exit( $fail === 0 ? 0 : 1 );
