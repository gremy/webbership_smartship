<?php
declare(strict_types=1);

namespace Ovride\Smartship\Modules\CheckoutRates;

defined( 'ABSPATH' ) || exit;

/**
 * Live SmartShip checkout rates (instance-based, per zone).
 *
 * @package Ovride\Smartship\Modules\CheckoutRates
 */
final class ShippingMethod extends \WC_Shipping_Method {

  /** Known SmartShip courier ids -> default names (see docs/reference/smartship-api.md). */
  private const COURIERS = [
    1 => 'Cargus', 2 => 'SameDay', 3 => 'FanCourier', 5 => 'DragonStar',
    6 => 'DPD', 14 => 'PTT Express', 16 => 'SmartShip Delivery',
  ];

  public function __construct( $instance_id = 0 ) {
    $this->id                 = 'ovride_smartship';
    $this->instance_id        = absint( $instance_id );
    $this->method_title       = __( 'SmartShip Live Rates', 'ovride-smartship' );
    $this->method_description = __( 'Live courier rates from SmartShip, with a fallback flat rate.', 'ovride-smartship' );
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
        'title'       => __( 'Method title', 'ovride-smartship' ),
        'type'        => 'text',
        'default'     => __( 'SmartShip', 'ovride-smartship' ),
        'desc_tip'    => true,
      ],
      'couriers' => [
        'title'       => __( 'Couriers to offer', 'ovride-smartship' ),
        'type'        => 'multiselect',
        'class'       => 'wc-enhanced-select',
        'options'     => $courier_options,
        'default'     => [],
        'description' => __( 'Leave empty to offer every courier SmartShip returns.', 'ovride-smartship' ),
      ],
      'labels' => [
        'title'       => __( 'Courier label overrides', 'ovride-smartship' ),
        'type'        => 'textarea',
        'default'     => '',
        'description' => __( 'One per line: courier_id|Custom label (e.g. 16|Curier rapid).', 'ovride-smartship' ),
      ],
      'markup_type' => [
        'title'   => __( 'Markup', 'ovride-smartship' ),
        'type'    => 'select',
        'default' => 'none',
        'options' => [ 'none' => __( 'None', 'ovride-smartship' ), 'flat' => __( 'Flat amount', 'ovride-smartship' ), 'percent' => __( 'Percent', 'ovride-smartship' ) ],
      ],
      'markup_amount' => [
        'title'   => __( 'Markup amount', 'ovride-smartship' ),
        'type'    => 'text',
        'default' => '0',
      ],
      'fallback_amount' => [
        'title'       => __( 'Fallback flat rate', 'ovride-smartship' ),
        'type'        => 'text',
        'default'     => '0',
        'description' => __( 'Shown when live rates are unavailable.', 'ovride-smartship' ),
      ],
      'fallback_title' => [
        'title'   => __( 'Fallback label', 'ovride-smartship' ),
        'type'    => 'text',
        'default' => __( 'Shipping', 'ovride-smartship' ),
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
      'fallback_title'  => sanitize_text_field( (string) $this->get_option( 'fallback_title', __( 'Shipping', 'ovride-smartship' ) ) ),
    ];
  }

  /** Fallback-only for now; Task 3 adds the live /cost orchestration. */
  public function calculate_shipping( $package = [] ): void {
    $f = RateCalculator::fallback_rate( $this->config() );
    $this->add_rate( [ 'id' => $f['id'], 'label' => $f['label'], 'cost' => $f['cost'] ] );
  }
}
