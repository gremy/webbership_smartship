<?php
declare(strict_types=1);
// Run: php tests/smoke-shipping-method.php
//
// Covers the "no fallback" behavior of the checkout-rates ShippingMethod: whenever a
// live quote can't be produced (no /cost data, cron/headless, no API key), the method
// must add ZERO rates so WooCommerce shows its own "no shipping options" message,
// instead of the old flat fallback rate.
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

  public function init_settings(): void {}

  public function get_option( $key, $default = null ) {
    if ( null !== $default ) {
      return $default;
    }
    return $this->instance_form_fields[ $key ]['default'] ?? '';
  }

  public function add_rate( array $args ): void {
    $this->rates_added[] = $args;
  }
}

if ( ! class_exists( '\\Webbership\\Smartship\\Settings\\Settings' ) ) {
  eval( 'namespace Webbership\\Smartship\\Settings; class Settings { public static $key = "TESTKEY"; public static function api_key(): string { return self::$key; } public static function sender_id(): int { return 7; } public static function iban(): string { return ""; } }' );
}

require_once __DIR__ . '/../includes/Api/class-smartship-client.php';
require_once __DIR__ . '/../includes/Support/class-city-resolver.php';
require_once __DIR__ . '/../modules/awb/data/class-awb-payload.php';
require_once __DIR__ . '/../includes/Support/class-cost-service.php';
require_once __DIR__ . '/../includes/Support/class-tax.php';
require_once __DIR__ . '/../modules/checkout-rates/class-rate-calculator.php';
require_once __DIR__ . '/../modules/checkout-rates/class-shipping-method.php';

use Webbership\Smartship\Modules\CheckoutRates\ShippingMethod;
use Webbership\Smartship\Settings\Settings;

$GLOBALS['webbership_smoke_cron'] = false;
$GLOBALS['webbership_smoke_wc']   = (object) [ 'session' => new stdClass() ];
Settings::$key                    = 'TESTKEY';

// 1) CostService returns null (no destination country -> short-circuits before any
//    /cost call) -> the shipping method must add ZERO rates. This is the core
//    regression: no quote must mean no rate, not a flat fallback.
$method = new ShippingMethod();
$method->calculate_shipping( [ 'destination' => [ 'country' => '' ], 'contents' => [] ] );
assert_same( 0, count( $method->rates_added ), 'null quote -> zero rates added (no fallback)' );

// 2) Headless/cron context -> zero rates (no fallback), never touches the API.
$GLOBALS['webbership_smoke_cron'] = true;
$method = new ShippingMethod();
$method->calculate_shipping( [ 'destination' => [ 'country' => 'RO' ], 'contents' => [] ] );
assert_same( 0, count( $method->rates_added ), 'cron/headless -> zero rates added' );
$GLOBALS['webbership_smoke_cron'] = false;

// 3) No API key configured -> zero rates (no fallback).
Settings::$key = '';
$method        = new ShippingMethod();
$method->calculate_shipping( [ 'destination' => [ 'country' => 'RO' ], 'contents' => [] ] );
assert_same( 0, count( $method->rates_added ), 'no API key -> zero rates added' );
Settings::$key = 'TESTKEY';

// 4) The removed fallback settings no longer surface in the instance form fields or config().
$method = new ShippingMethod();
assert_true( ! array_key_exists( 'fallback_amount', $method->instance_form_fields ), 'fallback_amount field removed' );
assert_true( ! array_key_exists( 'fallback_title', $method->instance_form_fields ), 'fallback_title field removed' );
$config = $method->config();
assert_true( ! array_key_exists( 'fallback_amount', $config ), 'fallback_amount dropped from config()' );
assert_true( ! array_key_exists( 'fallback_title', $config ), 'fallback_title dropped from config()' );

echo "smoke-shipping-method: all assertions passed\n";
