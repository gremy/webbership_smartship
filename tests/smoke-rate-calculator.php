<?php
declare(strict_types=1);
// Run: php tests/smoke-rate-calculator.php
define( 'ABSPATH', __DIR__ );

function assert_true( bool $c, string $m ): void { if ( ! $c ) { throw new RuntimeException( $m ); } }
function assert_same( $e, $a, string $m ): void { if ( $e !== $a ) { throw new RuntimeException( $m . ': ' . var_export( $a, true ) ); } }

class WC_Tax {
  public static array $rates = [];

  public static function get_shipping_tax_rates(): array {
    return self::$rates;
  }
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

// no allowlist, no markup -> all rates, raw cost, id + courier_id set.
$r = RateCalculator::build_rates( $costs, [] );
assert_same( 3, count( $r ), 'all couriers' );
assert_same( 'webbership_smartship:16', $r[0]['id'], 'rate id format' );
assert_same( 16, $r[0]['courier_id'], 'courier_id carried' );
assert_true( abs( $r[0]['cost'] - 17.97 ) < 0.001, 'raw cost' );
assert_same( 'SmartShip Delivery', $r[0]['label'], 'default label = courier_name' );

// allowlist filters; label override; flat markup +5.
$r = RateCalculator::build_rates( $costs, [ 'couriers' => [ 16, 1 ], 'labels' => [ 16 => 'Curier rapid' ], 'markup_type' => 'flat', 'markup_amount' => 5.0 ] );
assert_same( 2, count( $r ), 'allowlist keeps 2' );
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

// fallback rate.
$f = RateCalculator::fallback_rate( [ 'fallback_amount' => 19.99, 'fallback_title' => 'Curier standard' ] );
assert_same( 'webbership_smartship:fallback', $f['id'], 'fallback id' );
assert_same( 'Curier standard', $f['label'], 'fallback label' );
assert_true( abs( $f['cost'] - 19.99 ) < 0.001, 'fallback cost' );

// VAT-inclusive API costs are divided only when WooCommerce will add shipping tax.
$GLOBALS['webbership_smoke_wc'] = (object) [ 'customer' => new SmokeCustomer( false ) ];
\WC_Tax::$rates = [ [ 'rate' => 21 ] ];
assert_true( abs( Tax::shipping_vat_divisor() - 1.21 ) < 0.001, 'shipping VAT divisor uses shipping tax rate' );

$GLOBALS['webbership_smoke_wc'] = (object) [ 'customer' => new SmokeCustomer( true ) ];
assert_same( 1.0, Tax::shipping_vat_divisor(), 'VAT-exempt customers keep API shipping cost intact' );

echo "smoke-rate-calculator: all assertions passed\n";
