<?php
declare(strict_types=1);
// Run: php tests/smoke-courier-registry.php
define( 'ABSPATH', __DIR__ );

function assert_true( bool $c, string $m ): void { if ( ! $c ) { throw new RuntimeException( $m ); } }
function assert_same( $e, $a, string $m ): void { if ( $e !== $a ) { throw new RuntimeException( $m . ': expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) ); } }

function sanitize_text_field( string $s ): string { return trim( preg_replace( '/[\s]+/', ' ', strip_tags( $s ) ) ); }

// In-memory options store, counting update_option() calls to prove learn() skips
// the write when nothing actually changed.
$GLOBALS['ss_options']       = [];
$GLOBALS['ss_update_calls']  = 0;
function get_option( $k, $default = false ) { return $GLOBALS['ss_options'][ $k ] ?? $default; }
function update_option( $k, $v, $autoload = true ) { $GLOBALS['ss_update_calls']++; $GLOBALS['ss_options'][ $k ] = $v; return true; }

require_once __DIR__ . '/../includes/Support/class-courier-registry.php';

use Webbership\Smartship\Support\CourierRegistry;

// 1) Fresh install: known() returns the seed map, unmodified.
$known = CourierRegistry::known();
assert_same( 'SameDay', $known[2] ?? null, 'seed: courier 2 is SameDay' );
assert_same( 'FanCourier', $known[3] ?? null, 'seed: courier 3 is FanCourier (display-only, BYOC-only since 2026-07-29)' );
assert_same( 'DPD', $known[6] ?? null, 'seed: courier 6 is DPD (display-only, BYOC-only since 2026-07-29)' );
assert_same( 'PTT Express', $known[8] ?? null, 'seed: courier 8 is PTT Express' );
assert_same( 'SameDay EasyBox', $known[12] ?? null, 'seed: courier 12 is SameDay EasyBox' );
assert_same( 'PTT Express', $known[14] ?? null, 'seed: courier 14 is also PTT Express (a distinct service from 8)' );
assert_same( 'Komy', $known[35] ?? null, 'seed: courier 35 is Komy' );
assert_true( ! isset( $known[99] ), 'seed: an unseen courier id is absent' );

// 2) learn() with a courier the seed doesn't know -> it appears in known(), and the
// option was actually written.
$costs = [
  [ 'courier_id' => 99, 'courier_name' => 'NewCourier', 'cost' => 12.5 ],
];
CourierRegistry::learn( $costs );
assert_same( 1, $GLOBALS['ss_update_calls'], 'learn: new courier -> one write' );
assert_same( 'NewCourier', CourierRegistry::known()[99] ?? null, 'learn: new courier now in known()' );
// Seed entries are untouched by learning a new one.
assert_same( 'SameDay', CourierRegistry::known()[2] ?? null, 'learn: seed entries still present after learning' );

// 3) learn() again with the exact same id/name -> no-op, no extra DB write.
CourierRegistry::learn( $costs );
assert_same( 1, $GLOBALS['ss_update_calls'], 'learn: repeat of the same data does not write again' );

// 4) learn() with a name change for a known id -> writes once more, and the name
// updates (live API data wins over what we stored before).
CourierRegistry::learn( [ [ 'courier_id' => 99, 'courier_name' => 'RenamedCourier' ] ] );
assert_same( 2, $GLOBALS['ss_update_calls'], 'learn: changed name -> another write' );
assert_same( 'RenamedCourier', CourierRegistry::known()[99] ?? null, 'learn: name change reflected in known()' );

// 5) A learned name for a seeded id overrides the seed's display name in known().
CourierRegistry::learn( [ [ 'courier_id' => 2, 'courier_name' => 'SameDay Curier' ] ] );
assert_same( 'SameDay Curier', CourierRegistry::known()[2] ?? null, 'learn: learned name overrides the seed for the same id' );

// 6) Garbage rows (no id, id <= 0, no/blank name, non-array row) are ignored, no write.
$before = $GLOBALS['ss_update_calls'];
CourierRegistry::learn( [ 'garbage', [ 'courier_id' => 0, 'courier_name' => 'x' ], [ 'courier_id' => 5 ], [ 'courier_id' => 6, 'courier_name' => '' ] ] );
assert_same( $before, $GLOBALS['ss_update_calls'], 'learn: garbage/id-less/name-less rows never trigger a write' );

// 7) courier_name is sanitized before storage (no markup surviving into the option/label).
CourierRegistry::learn( [ [ 'courier_id' => 50, 'courier_name' => '<script>alert(1)</script>Evil' ] ] );
$label = CourierRegistry::known()[50] ?? '';
assert_true( ! str_contains( $label, '<' ) && ! str_contains( $label, '>' ), 'learn: courier_name sanitized before storage' );

echo "smoke-courier-registry: all assertions passed\n";
