<?php
declare(strict_types=1);
// Run: php tests/smoke-fulfillment-sync.php
//
// Covers the AWB-paste auto-detection added to FulfillmentModule:
// is_smartship_awb()'s sanity gate (no API call on garbage input),
// courier_from_status() shape handling, the parse-filter override, and
// sync_from_order()'s AWB-adoption path.

namespace {
  define( 'ABSPATH', __DIR__ );
  if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

  function __( $t, $d = 'default' ) { return $t; }
  // Minimal stand-in: production sanitize_text_field() strips tags/extra whitespace.
  function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }

  // In-memory transient store so the real caching path in verified_awb_status()
  // is exercised (not bypassed), same as production get_transient/set_transient.
  $GLOBALS['test_transients'] = [];
  function get_transient( $key ) { return $GLOBALS['test_transients'][ $key ] ?? false; }
  function set_transient( $key, $value, $ttl ) { $GLOBALS['test_transients'][ $key ] = $value; return true; }

  function assert_true( bool $c, string $m ): void { if ( ! $c ) { throw new \RuntimeException( $m ); } }
  function assert_same( $e, $a, string $m ): void { if ( $e !== $a ) { throw new \RuntimeException( $m . ': expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) ); } }
}

// Minimal stand-in for WC's abstract provider so the real SmartshipShippingProvider
// can be loaded and its (pure) tracking_url() exercised without pulling in WooCommerce.
namespace Automattic\WooCommerce\Admin\Features\Fulfillments\Providers {
  abstract class AbstractShippingProvider {
    abstract public function get_key(): string;
    abstract public function get_name(): string;
    abstract public function get_icon(): string;
    abstract public function get_tracking_url( string $tracking_number ): string;
    public function get_shipping_from_countries(): array { return []; }
    public function get_shipping_to_countries(): array { return []; }
    public function try_parse_tracking_number( string $tracking_number, string $shipping_from, string $shipping_to ): ?array { return null; }
  }
}

// Minimal stand-in for WC's Fulfillment entity — just enough surface for
// FulfillmentModule::sync_from_order() to read/write.
namespace Automattic\WooCommerce\Admin\Features\Fulfillments {
  class Fulfillment {
    public $order;
    public array $meta;
    public ?string $provider = null;
    public ?string $tracking_url = null;

    public function __construct( $order, array $meta = [] ) {
      $this->order = $order;
      $this->meta  = $meta;
    }

    public function get_order() { return $this->order; }
    public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
    public function set_shipment_provider( $v ) { $this->provider = $v; }
    public function set_tracking_url( $v ) { $this->tracking_url = $v; }
    public function update_meta_data( $key, $val ) { $this->meta[ $key ] = $val; }
  }
}

// Fake Settings: FulfillmentModule only ever calls Settings::api_key() — no
// need to require the real class (and its get_option()/WP Settings API deps).
namespace Webbership\Smartship\Settings {
  final class Settings {
    public static function api_key(): string {
      return $GLOBALS['test_api_key'] ?? '';
    }
  }
}

// Fake SmartShipClient: records every get_awb_status() call and returns a
// canned response, so tests can both assert on call counts (proving the
// sanity gate/cache avoid real calls) and drive the verified/unverified paths.
namespace Webbership\Smartship\Api {
  final class SmartShipClient {
    public function __construct( string $api_key ) {}
    public function get_awb_status( string $awb, int $timeout = 20 ): array {
      $GLOBALS['test_status_calls'][] = $awb;
      return $GLOBALS['test_status_response'];
    }
  }
}

namespace {
  require_once __DIR__ . '/../includes/class-module.php';
  require_once __DIR__ . '/../modules/fulfillment/providers/class-smartship-shipping-provider.php';
  require_once __DIR__ . '/../modules/fulfillment/class-fulfillment-module.php';

  use Webbership\Smartship\Modules\Fulfillment\FulfillmentModule;
  use Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment;

  class FakeOrder {
    public array $meta = [];
    public bool $saved = false;
    public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
    public function update_meta_data( $key, $val ) { $this->meta[ $key ] = $val; }
    public function save() { $this->saved = true; }
  }

  $module = new FulfillmentModule();

  // --- is_smartship_awb(): sanity gate rejects garbage without ever calling the API ---
  $GLOBALS['test_api_key']      = 'key123';
  $GLOBALS['test_status_calls'] = [];
  assert_true( ! $module->is_smartship_awb( 'ab' ), 'too short: rejected' );
  assert_true( ! $module->is_smartship_awb( str_repeat( 'A', 31 ) ), 'too long: rejected' );
  assert_true( ! $module->is_smartship_awb( 'AB CD1234' ), 'non-alphanumeric: rejected' );
  assert_same( [], $GLOBALS['test_status_calls'], 'sanity gate: no API calls made for any garbage input' );

  // No API key configured -> false without a call, even for an otherwise-valid number.
  $GLOBALS['test_api_key']      = '';
  $GLOBALS['test_status_calls'] = [];
  assert_true( ! $module->is_smartship_awb( 'VALIDAWB123' ), 'no api key: rejected' );
  assert_same( [], $GLOBALS['test_status_calls'], 'no api key: no API call made' );

  // --- is_smartship_awb(): verified + cached ---
  $GLOBALS['test_api_key']          = 'key123';
  $GLOBALS['test_status_response']  = [ 'ok' => true, 'status' => 200, 'courier_name' => 'Cargus' ];
  $GLOBALS['test_status_calls']     = [];
  assert_true( $module->is_smartship_awb( 'VERIFIEDAWB1' ), 'valid + SmartShip confirms: true' );
  assert_same( 1, count( $GLOBALS['test_status_calls'] ), 'one API call made' );
  assert_true( $module->is_smartship_awb( 'VERIFIEDAWB1' ), 'second lookup: still true (served from cache)' );
  assert_same( 1, count( $GLOBALS['test_status_calls'] ), 'second lookup: no new API call (positive verdict cached)' );

  // --- is_smartship_awb(): SmartShip doesn't recognize it ---
  $GLOBALS['test_status_response'] = [ 'ok' => false, 'status' => 999 ];
  $GLOBALS['test_status_calls']    = [];
  assert_true( ! $module->is_smartship_awb( 'UNKNOWNAWB99' ), 'valid format but SmartShip rejects: false' );
  assert_same( 1, count( $GLOBALS['test_status_calls'] ), 'one API call made for the unknown AWB' );

  // --- courier_from_status(): shape handling, pure/no WP needed ---
  assert_same( 'DPD', FulfillmentModule::courier_from_status( [ 'courier' => 'DPD' ] ), 'top-level courier key' );
  assert_same( 'Cargus', FulfillmentModule::courier_from_status( [ 'history' => [ [ 'courier_name' => 'Cargus' ] ] ] ), 'history entry courier_name' );
  assert_same( 'FanCourier', FulfillmentModule::courier_from_status( [ 'data' => [ 'curier' => 'FanCourier' ] ] ), 'nested under data wrapper' );
  assert_same( '', FulfillmentModule::courier_from_status( [ 'foo' => 'bar' ] ), 'unrecognized shape: empty string, not a guess' );
  assert_same( '', FulfillmentModule::courier_from_status( [] ), 'empty status: empty string' );

  // --- override_parsed_provider(): the woocommerce_fulfillment_parse_tracking_number filter ---
  $GLOBALS['test_api_key']         = 'key123';
  $GLOBALS['test_status_response'] = [ 'ok' => true, 'status' => 200 ];
  $parsed = [ 'tracking_number' => 'CONFIRMEDAWB1', 'shipping_provider' => 'some-other-provider', 'tracking_url' => 'https://example.com/x' ];
  $result = $module->override_parsed_provider( $parsed );
  assert_same( 'webbership-smartship', $result['shipping_provider'], 'array in + verified: provider overridden' );
  assert_same( 'https://smartship.ro/t/CONFIRMEDAWB1', $result['tracking_url'], 'array in + verified: tracking url overridden' );

  $GLOBALS['test_status_response'] = [ 'ok' => false ];
  $unverified = [ 'tracking_number' => 'NOTREALAWB99', 'shipping_provider' => 'some-other-provider', 'tracking_url' => 'https://example.com/y' ];
  assert_same( $unverified, $module->override_parsed_provider( $unverified ), 'array in + not verified: untouched' );

  assert_same( 'RAWSTRING', $module->override_parsed_provider( 'RAWSTRING' ), 'string in (upstream filter unhooked): untouched' );
  assert_same( [], $module->override_parsed_provider( [] ), 'array without tracking_number: untouched' );

  // --- sync_from_order(): adoption when the order has no stored AWB yet ---
  $GLOBALS['test_status_response'] = [ 'ok' => true, 'status' => 200, 'courier_name' => 'DPD' ];
  $GLOBALS['test_status_calls']    = [];
  $order       = new FakeOrder();
  $fulfillment = new Fulfillment( $order, [ '_tracking_number' => 'NEWAWB4567' ] );
  $returned    = $module->sync_from_order( $fulfillment );
  assert_true( $returned === $fulfillment, 'adoption: returns the same fulfillment object' );
  assert_same( 'NEWAWB4567', $order->meta['_webbership_smartship_awb'] ?? null, 'adoption: order AWB meta written' );
  assert_same( 'DPD', $order->meta['_webbership_smartship_courier'] ?? null, 'adoption: order courier meta written' );
  assert_true( $order->saved, 'adoption: order saved' );
  assert_same( 'webbership-smartship', $fulfillment->provider, 'adoption: fulfillment provider pinned' );
  assert_same( 'https://smartship.ro/t/NEWAWB4567', $fulfillment->tracking_url, 'adoption: fulfillment tracking url pinned' );
  assert_same( 'DPD', $fulfillment->meta['_webbership_smartship_courier'] ?? null, 'adoption: fulfillment courier meta written' );

  // --- sync_from_order(): a DIFFERENT stored AWB is left untouched (ambiguous) ---
  $GLOBALS['test_status_calls'] = [];
  $order2       = new FakeOrder();
  $order2->meta['_webbership_smartship_awb'] = 'OLDAWB0001';
  $fulfillment2 = new Fulfillment( $order2, [ '_tracking_number' => 'DIFFERENTAWB2' ] );
  $module->sync_from_order( $fulfillment2 );
  assert_true( null === $fulfillment2->provider, 'different stored AWB: fulfillment provider not pinned' );
  assert_same( 'OLDAWB0001', $order2->meta['_webbership_smartship_awb'], 'different stored AWB: order meta untouched' );
  assert_true( ! $order2->saved, 'different stored AWB: order not saved' );
  assert_same( [], $GLOBALS['test_status_calls'], 'different stored AWB: no API call — ambiguous case is skipped entirely' );

  // --- sync_from_order(): no stored AWB, but SmartShip doesn't recognize the number ---
  $GLOBALS['test_status_response'] = [ 'ok' => false ];
  $order3       = new FakeOrder();
  $fulfillment3 = new Fulfillment( $order3, [ '_tracking_number' => 'GARBAGEXXX1' ] );
  $module->sync_from_order( $fulfillment3 );
  assert_true( null === $fulfillment3->provider, 'unverified adoption attempt: fulfillment provider not pinned' );
  assert_true( ! isset( $order3->meta['_webbership_smartship_awb'] ), 'unverified adoption attempt: order meta not written' );
  assert_true( ! $order3->saved, 'unverified adoption attempt: order not saved' );

  // --- sync_from_order(): no stored AWB, no tracking number either -> nothing to do ---
  $GLOBALS['test_status_calls'] = [];
  $order4       = new FakeOrder();
  $fulfillment4 = new Fulfillment( $order4, [ '_tracking_number' => '' ] );
  $module->sync_from_order( $fulfillment4 );
  assert_same( [], $GLOBALS['test_status_calls'], 'no tracking number at all: no API call' );

  echo "smoke-fulfillment-sync: all assertions passed\n";
}
