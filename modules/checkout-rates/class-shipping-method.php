<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\CheckoutRates;

use Webbership\Smartship\Api\SmartShipClient;
use Webbership\Smartship\Support\CostService;
use Webbership\Smartship\Support\Tax;
use Webbership\Smartship\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Live SmartShip checkout rates (instance-based, per zone).
 *
 * @package Webbership\Smartship\Modules\CheckoutRates
 */
final class ShippingMethod extends \WC_Shipping_Method {

  public function __construct( $instance_id = 0 ) {
    $this->id                 = 'webbership_smartship';
    $this->instance_id        = absint( $instance_id );
    $this->method_title       = __( 'SmartShip Live Rates', 'webbership-smartship' );
    $this->method_description = __( 'Live courier rates from SmartShip. No rate is offered when a live quote cannot be fetched.', 'webbership-smartship' );
    $this->supports           = [ 'shipping-zones', 'instance-settings', 'instance-settings-modal' ];
    $this->init();
  }

  public function init(): void {
    $this->init_form_fields();
    $this->init_settings();
    $this->title   = $this->get_option( 'title', $this->method_title );
    $this->enabled = $this->get_option( 'enabled', 'yes' );
    add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
  }

  public function init_form_fields(): void {
    $this->instance_form_fields = [
      'title' => [
        'title'       => __( 'Method title', 'webbership-smartship' ),
        'type'        => 'text',
        'default'     => __( 'SmartShip', 'webbership-smartship' ),
        'description' => __( 'Heading shown above the courier choices at checkout.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'excluded_couriers' => [
        'title'       => __( 'Couriers to exclude', 'webbership-smartship' ),
        'type'        => 'text',
        'default'     => '',
        'description' => __( 'By default every courier SmartShip returns for the destination is offered. To hide specific couriers, list their ids here, comma-separated (e.g. 3, 14). Find a courier\'s id in the order-screen Estimate results.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'labels' => [
        'title'       => __( 'Courier label overrides', 'webbership-smartship' ),
        'type'        => 'textarea',
        'default'     => '',
        'description' => __( 'Rename couriers at checkout. One per line as courier_id|Custom label (e.g. 16|Curier rapid). Find a courier\'s id in the order-screen Estimate results.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'markup_type' => [
        'title'   => __( 'Markup', 'webbership-smartship' ),
        'type'    => 'select',
        'default' => 'none',
        'options' => [ 'none' => __( 'None', 'webbership-smartship' ), 'flat' => __( 'Flat amount', 'webbership-smartship' ), 'percent' => __( 'Percent', 'webbership-smartship' ) ],
        'description' => __( 'Optionally add a handling fee on top of each live rate.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'markup_amount' => [
        'title'   => __( 'Markup amount', 'webbership-smartship' ),
        'type'    => 'text',
        'default' => '0',
        'description' => __( 'With "Flat amount", the fee added in your store currency. With "Percent", the percentage added to each rate.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
    ];
  }

  /** Read + sanitize the instance settings into the RateCalculator config shape. */
  public function config(): array {
    $labels = [];
    foreach ( preg_split( '/\r\n|\r|\n/', (string) $this->get_option( 'labels', '' ) ) as $line ) {
      $parts = explode( '|', $line, 2 );
      if ( count( $parts ) === 2 && absint( $parts[0] ) > 0 ) {
        $labels[ absint( $parts[0] ) ] = sanitize_text_field( trim( $parts[1] ) );
      }
    }
    $excluded = array_filter( array_map( 'absint', explode( ',', (string) $this->get_option( 'excluded_couriers', '' ) ) ) );
    return [
      'excluded_couriers' => array_values( $excluded ),
      'labels'            => $labels,
      'markup_type'       => in_array( $this->get_option( 'markup_type', 'none' ), [ 'none', 'flat', 'percent' ], true ) ? $this->get_option( 'markup_type', 'none' ) : 'none',
      'markup_amount'     => max( 0.0, (float) $this->get_option( 'markup_amount', 0 ) ),
    ];
  }

  public function calculate_shipping( $package = [] ): void {
    $config = $this->config();

    // Headless/cron (e.g. a subscription renewal) -> no live quote is possible, no rate.
    if ( wp_doing_cron() || ! function_exists( 'WC' ) || null === WC()->session ) {
      return;
    }

    $api_key = Settings::api_key();
    if ( '' === $api_key ) {
      return;
    }
    $client = new SmartShipClient( $api_key );
    $costs  = CostService::costs_for( $package, $client );
    if ( null === $costs ) {
      // No quote, no fallback: better to block checkout for this address than to
      // undercharge a heavy order on a flat rate the store would eat the loss on.
      return;
    }

    $rates = RateCalculator::build_rates( $costs, $config );
    if ( empty( $rates ) ) {
      return;
    }
    $divisor = Tax::shipping_vat_divisor(); // API returns cu TVA; WC expects ex-VAT.
    foreach ( $rates as $r ) {
      $this->add_rate( [
        'id'        => $r['id'],
        'label'     => $r['label'],
        'cost'      => round( $r['cost'] / $divisor, 2 ),
        'meta_data' => [ 'courier_id' => $r['courier_id'] ],
      ] );
    }
  }
}
