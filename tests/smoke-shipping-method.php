<?php
declare(strict_types=1);
// Run: php tests/smoke-shipping-method.php
//
// Covers the weight-aware fallback behavior of the checkout-rates ShippingMethod:
// whenever a live quote can't be produced (no /cost data, cron/headless, no API key,
// or empty rates), the method must add EXACTLY ONE fallback rate priced
// base + per_kg * package weight, so checkout never shows zero shipping options.
define( 'ABSPATH', __DIR__ );

function assert_true( bool $c, string $m ): void { if ( ! $c ) { throw new RuntimeException( $m ); } }
function assert_same( $e, $a, string $m ): void { if ( $e !== $a ) { throw new RuntimeException( $m . ': expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) ); } }

function __( $text, $domain = 'default' ) { return $text; }
function absint( $n ) { return abs( (int) $n ); }
function sanitize_text_field( string $s ) { return trim( preg_replace( '/[\s]+/', ' ', strip_tags( $s ) ) ); }
function add_action( ...$args ) {}
function untrailingslashit( string $s ): string { return rtrim( $s, '/\\' ); }
function wp_doing_cron() { return $GLOBALS['webbership_smoke_cron'] ?? false; }
function WC() { return $GLOBALS['webbership_smoke_wc']; }

// In-memory options store: CourierRegistry::known() (read by init_form_fields() on
// every ShippingMethod construction) goes through get_option().
$GLOBALS['webbership_smoke_options'] = [];
function get_option( $k, $default = false ) { return $GLOBALS['webbership_smoke_options'][ $k ] ?? $default; }
function update_option( $k, $v, $autoload = true ) { $GLOBALS['webbership_smoke_options'][ $k ] = $v; return true; }

/** Minimal stand-in for WC_Shipping_Method: only what ShippingMethod actually calls. */
class WC_Shipping_Method {
  public $id;
  public $instance_id;
  public $method_title;
  public $method_description;
  public $supports = [];
  public $instance_form_fields = [];
  public $title;
  public $enabled;
  public array $rates_added = [];
  /** Simulated saved admin settings, checked before the caller-supplied default. */
  public array $settings = [];

  public function init_settings(): void {}

  public function get_option( $key, $default = null ) {
    if ( array_key_exists( $key, $this->settings ) ) {
      return $this->settings[ $key ];
    }
    if ( null !== $default ) {
      return $default;
    }
    return $this->instance_form_fields[ $key ]['default'] ?? '';
  }

  public function add_rate( array $args ): void {
    $this->rates_added[] = $args;
  }
}

/** Minimal stand-in for a WC_Product: only get_weight(), as CostService::package_weight() needs. */
class SmokeProduct {
  public function __construct( private string $weight ) {}
  public function get_weight() { return $this->weight; }
}

if ( ! class_exists( '\\Webbership\\Smartship\\Settings\\Settings' ) ) {
  eval( 'namespace Webbership\\Smartship\\Settings; class Settings { public static $key = "TESTKEY"; public static function api_key(): string { return self::$key; } public static function sender_id(): int { return 7; } public static function iban(): string { return ""; } }' );
}

require_once __DIR__ . '/../includes/Api/class-smartship-client.php';
require_once __DIR__ . '/../includes/Support/class-city-resolver.php';
require_once __DIR__ . '/../modules/awb/data/class-awb-payload.php';
require_once __DIR__ . '/../includes/Support/class-courier-registry.php';
require_once __DIR__ . '/../includes/Support/class-cost-service.php';
require_once __DIR__ . '/../includes/Support/class-tax.php';
require_once __DIR__ . '/../modules/checkout-rates/class-rate-calculator.php';
require_once __DIR__ . '/../modules/checkout-rates/class-shipping-method.php';

use Webbership\Smartship\Modules\CheckoutRates\ShippingMethod;
use Webbership\Smartship\Settings\Settings;

$GLOBALS['webbership_smoke_cron'] = false;
$GLOBALS['webbership_smoke_wc']   = (object) [ 'session' => new stdClass() ];
Settings::$key                    = 'TESTKEY';

$fallback_settings = [ 'fallback_amount' => '50', 'fallback_per_kg_amount' => '15', 'fallback_title' => 'Standard shipping' ];
// 3kg package: two 1.5kg items.
$package_3kg  = [ 'destination' => [ 'country' => '' ], 'contents' => [ [ 'data' => new SmokeProduct( '1.5' ), 'quantity' => 2 ] ] ];
$package_0kg  = [ 'destination' => [ 'country' => '' ], 'contents' => [] ];

// 1) CostService returns null (no destination country -> short-circuits before any
//    /cost call) -> the shipping method must add EXACTLY ONE fallback rate, priced
//    base + per_kg * package weight. This is the core regression: no live quote must
//    still leave checkout with a (weight-aware) rate, not zero shipping options.
$method = new ShippingMethod();
$method->settings = $fallback_settings;
$method->calculate_shipping( $package_3kg );
assert_same( 1, count( $method->rates_added ), 'null quote -> exactly one fallback rate' );
assert_same( 'webbership_smartship:fallback', $method->rates_added[0]['id'], 'fallback rate id' );
assert_same( 'Standard shipping', $method->rates_added[0]['label'], 'fallback label from settings' );
assert_true( abs( $method->rates_added[0]['cost'] - 95.0 ) < 0.001, 'fallback cost = base 50 + per_kg 15 * 3kg = 95' );

// 2) Headless/cron context -> exactly one fallback rate, never touches the API.
$GLOBALS['webbership_smoke_cron'] = true;
$method = new ShippingMethod();
$method->settings = $fallback_settings;
$method->calculate_shipping( [ 'destination' => [ 'country' => 'RO' ], 'contents' => [] ] );
assert_same( 1, count( $method->rates_added ), 'cron/headless -> exactly one fallback rate' );
$GLOBALS['webbership_smoke_cron'] = false;

// 3) No API key configured -> exactly one fallback rate.
Settings::$key = '';
$method        = new ShippingMethod();
$method->settings = $fallback_settings;
$method->calculate_shipping( [ 'destination' => [ 'country' => 'RO' ], 'contents' => [] ] );
assert_same( 1, count( $method->rates_added ), 'no API key -> exactly one fallback rate' );
Settings::$key = 'TESTKEY';

// 4) Zero/unknown package weight -> fallback charges base only (no per-kg component).
$method = new ShippingMethod();
$method->settings = $fallback_settings;
$method->calculate_shipping( $package_0kg );
assert_same( 1, count( $method->rates_added ), 'zero-weight package -> exactly one fallback rate' );
assert_true( abs( $method->rates_added[0]['cost'] - 50.0 ) < 0.001, 'zero-weight package -> base amount only' );

// 5) Fallback settings surface in the instance form fields with the documented defaults,
//    and flow through into config().
$method = new ShippingMethod();
assert_true( array_key_exists( 'fallback_amount', $method->instance_form_fields ), 'fallback_amount field present' );
assert_true( array_key_exists( 'fallback_per_kg_amount', $method->instance_form_fields ), 'fallback_per_kg_amount field present' );
assert_true( array_key_exists( 'fallback_title', $method->instance_form_fields ), 'fallback_title field present' );
assert_same( '50', $method->instance_form_fields['fallback_amount']['default'], 'fallback base default 50' );
assert_same( '15', $method->instance_form_fields['fallback_per_kg_amount']['default'], 'fallback per-kg default 15' );
$method->settings = $fallback_settings;
$config = $method->config();
assert_true( abs( $config['fallback_amount'] - 50.0 ) < 0.001, 'fallback_amount in config()' );
assert_true( abs( $config['fallback_per_kg_amount'] - 15.0 ) < 0.001, 'fallback_per_kg_amount in config()' );
assert_same( 'Standard shipping', $config['fallback_title'], 'fallback_title in config()' );

// 6) excluded_couriers normalization: config() must read both the legacy comma-separated
//    string (old text field) and the new array (multiselect) into a clean int array.
$method = new ShippingMethod();
$method->settings = $fallback_settings + [ 'excluded_couriers' => '3, 14' ];
assert_same( [ 3, 14 ], $method->config()['excluded_couriers'], 'legacy comma string normalized to int array' );

$method = new ShippingMethod();
$method->settings = $fallback_settings + [ 'excluded_couriers' => [ '3', 14, 0, '' ] ];
assert_same( [ 3, 14 ], $method->config()['excluded_couriers'], 'multiselect array normalized, zero/blank dropped' );

$method = new ShippingMethod();
$method->settings = $fallback_settings; // no excluded_couriers saved at all
assert_same( [], $method->config()['excluded_couriers'], 'unset excluded_couriers -> empty array (all couriers offered)' );

// 7) excluded_couriers field is a multiselect sourced from CourierRegistry::known(),
//    not a hardcoded list — the field's options must include a seeded courier.
$field = $method->instance_form_fields['excluded_couriers'];
assert_same( 'multiselect', $field['type'], 'excluded_couriers is a multiselect field' );
assert_true( isset( $field['options'][2] ), 'excluded_couriers options include a known courier id' );

echo "smoke-shipping-method: all assertions passed\n";
