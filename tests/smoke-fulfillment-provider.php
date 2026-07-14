<?php
declare(strict_types=1);
// Run: php tests/smoke-fulfillment-provider.php

namespace {
  define( 'ABSPATH', __DIR__ );
  function __( $t, $d = 'default' ) { return $t; }

  function assert_true( bool $c, string $m ): void { if ( ! $c ) { throw new \RuntimeException( $m ); } }
  function assert_same( $e, $a, string $m ): void { if ( $e !== $a ) { throw new \RuntimeException( $m . ': expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) ); } }
}

// Minimal stand-in for WC's abstract provider so the real provider class can be
// loaded and exercised without pulling in WooCommerce.
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

namespace {
  require_once __DIR__ . '/../includes/class-module.php';
  require_once __DIR__ . '/../modules/fulfillment/providers/class-smartship-shipping-provider.php';
  require_once __DIR__ . '/../modules/fulfillment/class-fulfillment-module.php';

  use Webbership\Smartship\Modules\Fulfillment\Providers\SmartshipShippingProvider;
  use Webbership\Smartship\Modules\Fulfillment\FulfillmentModule;

  // tracking_url(): rawurlencode over the trimmed AWB.
  assert_same( 'https://smartship.ro/t/ABC%20123', SmartshipShippingProvider::tracking_url( 'ABC 123' ), 'tracking_url spaces' );
  assert_same( 'https://smartship.ro/t/3006238521', SmartshipShippingProvider::tracking_url( '3006238521' ), 'tracking_url numeric' );
  assert_same( 'https://smartship.ro/t/3006238521', SmartshipShippingProvider::tracking_url( '  3006238521  ' ), 'tracking_url trims' );

  // get_tracking_url() (instance method WC calls) delegates to the same helper.
  $provider = new SmartshipShippingProvider();
  assert_same( 'webbership-smartship', $provider->get_key(), 'get_key' );
  assert_same( 'SmartShip', $provider->get_name(), 'get_name' );
  assert_same( 'https://smartship.ro/t/3006238521', $provider->get_tracking_url( '3006238521' ), 'get_tracking_url' );
  assert_same( [ 'RO' ], $provider->get_shipping_from_countries(), 'get_shipping_from_countries' );
  assert_true( null === $provider->try_parse_tracking_number( '3006238521', 'RO', 'RO' ), 'try_parse_tracking_number always null' );

  // FulfillmentModule::awb_matches(): pure trim + case-insensitive compare.
  assert_true( FulfillmentModule::awb_matches( '3006238521', '3006238521' ), 'awb_matches equal' );
  assert_true( FulfillmentModule::awb_matches( ' AWB-123 ', 'awb-123' ), 'awb_matches case/whitespace-insensitive' );
  assert_true( ! FulfillmentModule::awb_matches( 'AWB-123', 'AWB-456' ), 'awb_matches different' );
  assert_true( ! FulfillmentModule::awb_matches( '', '' ), 'awb_matches both empty' );
  assert_true( ! FulfillmentModule::awb_matches( '', 'AWB-123' ), 'awb_matches order empty' );
  assert_true( ! FulfillmentModule::awb_matches( 'AWB-123', '' ), 'awb_matches tracking empty' );

  echo "smoke-fulfillment-provider: all assertions passed\n";
}
