<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\CheckoutRates;

use Webbership\Smartship\Api\SmartShipClient;
use Webbership\Smartship\Support\CostService;
use Webbership\Smartship\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Live SmartShip checkout rates (instance-based, per zone).
 *
 * @package Webbership\Smartship\Modules\CheckoutRates
 */
final class ShippingMethod extends \WC_Shipping_Method {

  /** Known SmartShip courier ids -> default names (see docs/reference/smartship-api.md). */
  private const COURIERS = [
    1 => 'Cargus', 2 => 'SameDay', 3 => 'FanCourier', 5 => 'DragonStar',
    6 => 'DPD', 14 => 'PTT Express', 16 => 'SmartShip Delivery',
  ];

  public function __construct( $instance_id = 0 ) {
    $this->id                 = 'webbership_smartship';
    $this->instance_id        = absint( $instance_id );
    $this->method_title       = __( 'SmartShip Live Rates', 'webbership-smartship' );
    $this->method_description = __( 'Live courier rates from SmartShip, with a fallback flat rate.', 'webbership-smartship' );
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
    $courier_options = [];
    foreach ( self::COURIERS as $cid => $name ) {
      $courier_options[ $cid ] = $name . ' (' . $cid . ')';
    }
    $this->instance_form_fields = [
      'title' => [
        'title'       => __( 'Method title', 'webbership-smartship' ),
        'type'        => 'text',
        'default'     => __( 'SmartShip', 'webbership-smartship' ),
        'description' => __( 'Heading shown above the courier choices at checkout.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'couriers' => [
        'title'       => __( 'Couriers to offer', 'webbership-smartship' ),
        'type'        => 'multiselect',
        'class'       => 'wc-enhanced-select',
        'options'     => $courier_options,
        'default'     => [],
        'description' => __( 'Pick which couriers customers may choose. Leave empty to offer every courier SmartShip returns for the destination.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'labels' => [
        'title'       => __( 'Courier label overrides', 'webbership-smartship' ),
        'type'        => 'textarea',
        'default'     => '',
        'description' => __( 'Rename couriers at checkout. One per line as courier_id|Custom label (e.g. 16|Curier rapid). The IDs are shown in brackets in the "Couriers to offer" list above.', 'webbership-smartship' ),
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
      'fallback_amount' => [
        'title'       => __( 'Fallback flat rate', 'webbership-smartship' ),
        'type'        => 'text',
        'default'     => '0',
        'description' => __( 'Flat price charged when live rates cannot be fetched (SmartShip slow or down, or the address is outside Romania).', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'fallback_title' => [
        'title'   => __( 'Fallback label', 'webbership-smartship' ),
        'type'    => 'text',
        'default' => __( 'Shipping', 'webbership-smartship' ),
        'description' => __( 'Label shown to the customer for the fallback flat rate.', 'webbership-smartship' ),
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
    $known = array_keys( self::COURIERS );
    return [
      'couriers'        => array_values( array_intersect( array_map( 'absint', (array) $this->get_option( 'couriers', [] ) ), $known ) ),
      'labels'          => $labels,
      'markup_type'     => in_array( $this->get_option( 'markup_type', 'none' ), [ 'none', 'flat', 'percent' ], true ) ? $this->get_option( 'markup_type', 'none' ) : 'none',
      'markup_amount'   => max( 0.0, (float) $this->get_option( 'markup_amount', 0 ) ),
      'fallback_amount' => max( 0.0, (float) $this->get_option( 'fallback_amount', 0 ) ),
      'fallback_title'  => sanitize_text_field( (string) $this->get_option( 'fallback_title', __( 'Shipping', 'webbership-smartship' ) ) ),
    ];
  }

  public function calculate_shipping( $package = [] ): void {
    $config = $this->config();

    // Headless/cron (e.g. a subscription renewal) -> fallback, never call the API.
    if ( wp_doing_cron() || ! function_exists( 'WC' ) || null === WC()->session ) {
      $this->add_fallback( $config );
      return;
    }

    $dest = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : [];
    if ( 'RO' !== ( $dest['country'] ?? '' ) ) {
      $this->add_fallback( $config );
      return;
    }

    $api_key = Settings::api_key();
    if ( '' === $api_key ) {
      $this->add_fallback( $config );
      return;
    }
    $client = new SmartShipClient( $api_key );
    $costs  = CostService::costs_for( $package, $client );
    if ( null === $costs ) {
      $this->add_fallback( $config );
      return;
    }

    $rates = RateCalculator::build_rates( $costs, $config );
    if ( empty( $rates ) ) {
      $this->add_fallback( $config );
      return;
    }
    foreach ( $rates as $r ) {
      $this->add_rate( [
        'id'        => $r['id'],
        'label'     => $r['label'],
        'cost'      => $r['cost'],
        'meta_data' => [ 'courier_id' => $r['courier_id'] ],
      ] );
    }
  }

  private function add_fallback( array $config ): void {
    $f = RateCalculator::fallback_rate( $config );
    $this->add_rate( [ 'id' => $f['id'], 'label' => $f['label'], 'cost' => $f['cost'] ] );
  }
}
