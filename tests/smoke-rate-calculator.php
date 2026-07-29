<?php
declare(strict_types=1);
// Run: php tests/smoke-rate-calculator.php
define( 'ABSPATH', __DIR__ );

function assert_true( bool $c, string $m ): void { if ( ! $c ) { throw new RuntimeException( $m ); } }
function assert_same( $e, $a, string $m ): void { if ( $e !== $a ) { throw new RuntimeException( $m . ': ' . var_export( $a, true ) ); } }
function __( $text, $domain = 'default' ) { return $text; }

// Minimal stand-in for WP core's sanitize_text_field(): strips tags, collapses whitespace.
function sanitize_text_field( string $s ): string {
  return trim( preg_replace( '/[\s]+/', ' ', wp_strip_all_tags( $s ) ) );
}
function wp_strip_all_tags( string $s ): string {
  return strip_tags( $s );
}

class WC_Tax {
  public static array $rates = [];

  public static function get_shipping_tax_rates( $tax_class = null ): array {
    // Mirrors the real WooCommerce bug: when the shipping tax class is 'inherit'
    // and no explicit tax_class is passed, core falls through to a cart lookup
    // that fatals if WC()->cart is null. Reproduced here so the guard in
    // Support\Tax is actually exercised, not just assumed.
    if ( null === $tax_class && 'inherit' === get_option( 'woocommerce_shipping_tax_class' ) ) {
      $cart = WC()->cart ?? null;
      $cart->get_cart(); // fatal: call to a member function on null.
    }
    return self::$rates;
  }
}

function get_option( string $name ) {
  return $GLOBALS['webbership_smoke_options'][ $name ] ?? false;
}

class SmokeCustomer {
  public function __construct( private bool $vat_exempt = false ) {}

  public function get_is_vat_exempt(): bool {
    return $this->vat_exempt;
  }
}

function WC(): object {
  return $GLOBALS['webbership_smoke_wc'];
}

function wc_tax_enabled(): bool {
  return $GLOBALS['webbership_smoke_tax_enabled'] ?? true;
}

require_once __DIR__ . '/../modules/checkout-rates/class-rate-calculator.php';
require_once __DIR__ . '/../includes/Support/class-tax.php';
use Webbership\Smartship\Modules\CheckoutRates\RateCalculator;
use Webbership\Smartship\Support\Tax;

$costs = [
  [ 'courier_id' => 16, 'courier_name' => 'SmartShip Delivery', 'cost' => 17.97 ],
  [ 'courier_id' => 1,  'courier_name' => 'Cargus',            'cost' => 21.38 ],
  [ 'courier_id' => 5,  'courier_name' => 'DragonStar',        'cost' => 21.40 ],
];

// no exclude list, no markup -> all rates, raw cost, id + courier_id set. No hardcoded
// courier whitelist: an id SmartShip returns that the plugin has never heard of
// (e.g. FedEx) must still produce a rate.
$costs_with_fedex = array_merge( $costs, [ [ 'courier_id' => 42, 'courier_name' => 'FedEx', 'cost' => 55.0 ] ] );
$r = RateCalculator::build_rates( $costs_with_fedex, [] );
assert_same( 4, count( $r ), 'all couriers, including an unrecognized id' );
assert_same( 'webbership_smartship:16', $r[0]['id'], 'rate id format' );
assert_same( 16, $r[0]['courier_id'], 'courier_id carried' );
assert_true( abs( $r[0]['cost'] - 17.97 ) < 0.001, 'raw cost' );
assert_same( 'SmartShip Delivery', $r[0]['label'], 'default label = courier_name' );
assert_same( 'FedEx', $r[3]['label'], 'unrecognized courier_id still gets a rate, labeled from courier_name' );

// a courier_name containing markup must come out sanitized (no angle brackets) since
// WooCommerce echoes the shipping label unescaped.
$r = RateCalculator::build_rates( [ [ 'courier_id' => 7, 'courier_name' => '<script>alert(1)</script>Cargus', 'cost' => 10.0 ] ], [] );
assert_true( ! str_contains( $r[0]['label'], '<' ) && ! str_contains( $r[0]['label'], '>' ), 'courier_name sanitized, no angle brackets' );

// exclude list filters those ids out; label override; flat markup +5.
$r = RateCalculator::build_rates( $costs, [ 'excluded_couriers' => [ 5 ], 'labels' => [ 16 => 'Curier rapid' ], 'markup_type' => 'flat', 'markup_amount' => 5.0 ] );
assert_same( 2, count( $r ), 'exclude list drops 1, keeps 2' );
assert_same( 'Curier rapid', $r[0]['label'], 'label override' );
assert_true( abs( $r[0]['cost'] - 22.97 ) < 0.001, 'flat markup +5' );

// percent markup 10%.
$r = RateCalculator::build_rates( [ [ 'courier_id' => 1, 'courier_name' => 'Cargus', 'cost' => 20.0 ] ], [ 'markup_type' => 'percent', 'markup_amount' => 10.0 ] );
assert_true( abs( $r[0]['cost'] - 22.0 ) < 0.001, 'percent markup 10%' );

// markup edge cases.
assert_true( abs( RateCalculator::apply_markup( 10.0, [] ) - 10.0 ) < 0.001, 'no markup' );
assert_true( abs( RateCalculator::apply_markup( 10.0, [ 'markup_type' => 'flat', 'markup_amount' => -50 ] ) - 0.0 ) < 0.001, 'flat negative guarded to 0' );
assert_true( abs( RateCalculator::apply_markup( 10.0, [ 'markup_type' => 'percent', 'markup_amount' => -200 ] ) - 0.0 ) < 0.001, 'percent negative guarded to 0' );

// a courier with no id is skipped.
$r = RateCalculator::build_rates( [ [ 'courier_name' => 'x', 'cost' => 1 ], [ 'courier_id' => 2, 'courier_name' => 'SameDay', 'cost' => 9 ] ], [] );
assert_same( 1, count( $r ), 'skip id-less courier' );

// a row with a missing or non-numeric cost is SKIPPED (must not become a free 0-cost rate).
$r = RateCalculator::build_rates( [
  [ 'courier_id' => 1, 'courier_name' => 'no cost' ],
  [ 'courier_id' => 2, 'courier_name' => 'bad cost', 'cost' => 'abc' ],
  [ 'courier_id' => 16, 'courier_name' => 'ok', 'cost' => 9.5 ],
], [] );
assert_same( 1, count( $r ), 'skip rows without a numeric cost' );
assert_same( 16, $r[0]['courier_id'], 'only the valid-cost row survives' );
// a non-array row is skipped.
$r = RateCalculator::build_rates( [ 'garbage', [ 'courier_id' => 1, 'courier_name' => 'ok', 'cost' => 5 ] ], [] );
assert_same( 1, count( $r ), 'skip non-array row' );

// fallback_rate(): weight-aware fallback pricing (base + per_kg * weight).
$fb_config = [ 'fallback_amount' => 50, 'fallback_per_kg_amount' => 15, 'fallback_title' => 'Standard shipping' ];
$fb = RateCalculator::fallback_rate( $fb_config, 3.0 );
assert_same( 'webbership_smartship:fallback', $fb['id'], 'fallback rate id' );
assert_same( 'Standard shipping', $fb['label'], 'fallback label from config' );
assert_true( abs( $fb['cost'] - 95.0 ) < 0.001, 'fallback cost = base 50 + per_kg 15 * 3kg = 95' );

// zero/unknown weight -> base amount only.
$fb = RateCalculator::fallback_rate( $fb_config, 0.0 );
assert_true( abs( $fb['cost'] - 50.0 ) < 0.001, 'zero weight -> base amount only' );

// missing config keys default to 0, and negative inputs are guarded to 0.
$fb = RateCalculator::fallback_rate( [], 5.0 );
assert_true( abs( $fb['cost'] - 0.0 ) < 0.001, 'missing fallback config -> 0 cost' );
assert_same( 'Shipping', $fb['label'], 'missing fallback_title falls back to "Shipping"' );

// empty string fallback_title must also fall back to default label.
$fb = RateCalculator::fallback_rate( [ 'fallback_title' => '' ], 1.0 );
assert_same( 'Shipping', $fb['label'], 'empty fallback_title falls back to default "Shipping"' );

$fb = RateCalculator::fallback_rate( [ 'fallback_amount' => -10, 'fallback_per_kg_amount' => -5 ], 3.0 );
assert_true( abs( $fb['cost'] - 0.0 ) < 0.001, 'negative base/per_kg guarded to 0' );

// VAT-inclusive API costs are divided only when WooCommerce will add shipping tax.
$GLOBALS['webbership_smoke_wc'] = (object) [ 'customer' => new SmokeCustomer( false ) ];
\WC_Tax::$rates = [ [ 'rate' => 21 ] ];
assert_true( abs( Tax::shipping_vat_divisor() - 1.21 ) < 0.001, 'shipping VAT divisor uses shipping tax rate' );

$GLOBALS['webbership_smoke_wc'] = (object) [ 'customer' => new SmokeCustomer( true ) ];
assert_same( 1.0, Tax::shipping_vat_divisor(), 'VAT-exempt customers keep API shipping cost intact' );

// Regression: 'inherit' shipping tax class + no cart (wp-admin/REST/CLI) must not fatal.
// Before the fix, this reproduced "Call to a member function get_cart() on null".
$GLOBALS['webbership_smoke_options']['woocommerce_shipping_tax_class'] = 'inherit';
$GLOBALS['webbership_smoke_wc'] = (object) []; // no cart property at all, like wp-admin context
\WC_Tax::$rates = [ [ 'rate' => 21 ] ];
try {
  $divisor = Tax::shipping_vat_divisor();
  $note    = Tax::shipping_note();
} catch ( \Throwable $e ) {
  throw new RuntimeException( "Tax methods must not fatal without a cart: {$e->getMessage()}" );
}
assert_true( abs( $divisor - 1.21 ) < 0.001, 'no-cart + inherit still resolves the standard tax class rate' );
assert_true( is_string( $note ) && '' !== $note, 'shipping_note() returns a note without a cart' );

echo "smoke-rate-calculator: all assertions passed\n";
