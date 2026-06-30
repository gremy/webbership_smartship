<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\EasyBox;

use Webbership\Smartship\Api\SmartShipClient;
use Webbership\Smartship\Support\CostService;
use Webbership\Smartship\Support\Tax;
use Webbership\Smartship\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * EasyBox locker shipping method: one rate priced from the live SameDay home
 * rate × a configurable factor, with a flat fallback when no live rate is
 * available. The customer picks a locker on a map at checkout (later task).
 *
 * @package Webbership\Smartship\Modules\EasyBox
 */
final class EasyBoxMethod extends \WC_Shipping_Method {

  public function __construct( $instance_id = 0 ) {
    $this->id                 = EasyBoxPricing::METHOD_ID;
    $this->instance_id        = absint( $instance_id );
    $this->method_title       = __( 'Ridicare Sameday Point / EasyBox (SameDay)', 'webbership-smartship' );
    $this->method_description = __( 'Locker delivery, priced from the live SameDay rate. Customers choose their locker on a map at checkout.', 'webbership-smartship' );
    $this->supports           = [ 'shipping-zones', 'instance-settings', 'instance-settings-modal' ];
    $this->init();
  }

  public function init(): void {
    $this->init_form_fields();
    $this->init_settings();
    $this->title   = $this->get_option( 'title', __( 'Ridicare Sameday Point / EasyBox', 'webbership-smartship' ) );
    $this->enabled = $this->get_option( 'enabled', 'yes' );
    add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
  }

  public function init_form_fields(): void {
    $this->instance_form_fields = [
      'title' => [
        'title'       => __( 'Method title', 'webbership-smartship' ),
        'type'        => 'text',
        'default'     => __( 'Ridicare Sameday Point / EasyBox', 'webbership-smartship' ),
        'description' => __( 'Label shown to the customer at checkout.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'easybox_factor' => [
        'title'       => __( 'Price factor (%)', 'webbership-smartship' ),
        'type'        => 'text',
        'default'     => '80',
        'description' => __( 'Percent of the live SameDay home rate. 80 means 20% off — the measured EasyBox discount.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'fallback_amount' => [
        'title'       => __( 'Fallback flat rate', 'webbership-smartship' ),
        'type'        => 'text',
        'default'     => '0',
        'description' => __( 'Flat price charged when no live SameDay rate is available (SmartShip slow or down, or the address is outside Romania).', 'webbership-smartship' ) . ' ' . Tax::shipping_note(),
        'desc_tip'    => true,
      ],
      'fallback_title' => [
        'title'       => __( 'Fallback label', 'webbership-smartship' ),
        'type'        => 'text',
        'default'     => __( 'Ridicare Sameday Point / EasyBox', 'webbership-smartship' ),
        'description' => __( 'Label shown to the customer for the fallback flat rate.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
    ];
  }

  /**
   * Read the instance settings into the EasyBoxPricing config shape.
   * Reads each field via get_option() (like the live-rates method) rather than the
   * raw $this->instance_settings property — the latter only happens to be populated
   * via an init() side effect, so a refactor could silently revert to all-defaults.
   */
  public function config(): array {
    return EasyBoxPricing::config( [
      'title'           => $this->get_option( 'title' ),
      'easybox_factor'  => $this->get_option( 'easybox_factor' ),
      'fallback_amount' => $this->get_option( 'fallback_amount' ),
      'fallback_title'  => $this->get_option( 'fallback_title' ),
    ] );
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

    $sameday = CostService::courier_cost( $costs, 2 ); // 2 = SameDay home.
    if ( null === $sameday ) {
      $this->add_fallback( $config );
      return;
    }

    $divisor = Tax::shipping_vat_divisor(); // API returns cu TVA; WC expects ex-VAT.
    $this->add_rate( [
      'id'        => $this->get_rate_id(),
      'label'     => $config['title'],
      'cost'      => EasyBoxPricing::price( $sameday / $divisor, $config ),
      'meta_data' => [ 'easybox' => 1 ],
    ] );
  }

  private function add_fallback( array $config ): void {
    $fb = EasyBoxPricing::fallback( $config );
    $this->add_rate( [
      'id'        => $this->get_rate_id(),
      'label'     => $fb['label'],
      'cost'      => $fb['cost'],
      'meta_data' => [ 'easybox' => 1, 'fallback' => 1 ],
    ] );
  }
}
