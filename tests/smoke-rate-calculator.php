<?php
declare(strict_types=1);
// Run: php tests/smoke-rate-calculator.php
define( 'ABSPATH', __DIR__ );

function assert_true( bool $c, string $m ): void { if ( ! $c ) { throw new RuntimeException( $m ); } }
function assert_same( $e, $a, string $m ): void { if ( $e !== $a ) { throw new RuntimeException( $m . ': ' . var_export( $a, true ) ); } }

require_once __DIR__ . '/../modules/checkout-rates/class-rate-calculator.php';
use Ovride\Smartship\Modules\CheckoutRates\RateCalculator;

$costs = [
  [ 'courier_id' => 16, 'courier_name' => 'SmartShip Delivery', 'cost' => 17.97 ],
  [ 'courier_id' => 1,  'courier_name' => 'Cargus',            'cost' => 21.38 ],
  [ 'courier_id' => 5,  'courier_name' => 'DragonStar',        'cost' => 21.40 ],
];

// no allowlist, no markup -> all rates, raw cost, id + courier_id set.
$r = RateCalculator::build_rates( $costs, [] );
assert_same( 3, count( $r ), 'all couriers' );
assert_same( 'ovride_smartship:16', $r[0]['id'], 'rate id format' );
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
assert_true( abs( RateCalculator::apply_markup( 10.0, [ 'markup_type' => 'flat', 'markup_amount' => -50 ] ) - 0.0 ) < 0.001, 'negative guarded to 0' );

// a courier with no id is skipped.
$r = RateCalculator::build_rates( [ [ 'courier_name' => 'x', 'cost' => 1 ], [ 'courier_id' => 2, 'courier_name' => 'SameDay', 'cost' => 9 ] ], [] );
assert_same( 1, count( $r ), 'skip id-less courier' );

// fallback rate.
$f = RateCalculator::fallback_rate( [ 'fallback_amount' => 19.99, 'fallback_title' => 'Curier standard' ] );
assert_same( 'ovride_smartship:fallback', $f['id'], 'fallback id' );
assert_same( 'Curier standard', $f['label'], 'fallback label' );
assert_true( abs( $f['cost'] - 19.99 ) < 0.001, 'fallback cost' );

echo "smoke-rate-calculator: all assertions passed\n";
