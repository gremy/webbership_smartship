<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\EasyBox;

use Webbership\Smartship\Api\SmartShipClient;
use Webbership\Smartship\Support\CostService;
use Webbership\Smartship\Support\Tax;
use Webbership\Smartship\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * EasyBox locker shipping method: one rate priced from a real `/cost` quote for a
 * representative locker in the destination county (SameDay EasyBox, courier id 12),
 * with a weight-aware flat fallback when no live quote is available. The customer
 * picks their exact locker on a map at checkout (separate from the county-level
 * locker used only to price the rate).
 *
 * @package Webbership\Smartship\Modules\EasyBox
 */
final class EasyBoxMethod extends \WC_Shipping_Method {

  public function __construct( $instance_id = 0 ) {
    $this->id                 = EasyBoxPricing::METHOD_ID;
    $this->instance_id        = absint( $instance_id );
    $this->method_title       = __( 'Ridicare Sameday Point / EasyBox (SameDay)', 'webbership-smartship' );
    $this->method_description = __( 'Locker delivery, priced from a live SameDay EasyBox quote. Customers choose their locker on a map at checkout.', 'webbership-smartship' );
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
      'fallback_amount' => [
        'title'       => __( 'Fallback base amount', 'webbership-smartship' ),
        'type'        => 'text',
        'default'     => '0',
        'description' => __( 'Base price charged when no live EasyBox quote is available (SmartShip slow or down, no lockers cached, or the address is outside Romania).', 'webbership-smartship' ) . ' ' . Tax::shipping_note(),
        'desc_tip'    => true,
      ],
      'fallback_per_kg_amount' => [
        'title'       => __( 'Fallback per-kg amount', 'webbership-smartship' ),
        'type'        => 'text',
        'default'     => '0',
        'description' => __( 'Added to the fallback base amount for every kg of package weight, so the fallback never underprices a heavy parcel.', 'webbership-smartship' ) . ' ' . Tax::shipping_note(),
        'desc_tip'    => true,
      ],
      'fallback_title' => [
        'title'       => __( 'Fallback label', 'webbership-smartship' ),
        'type'        => 'text',
        'default'     => __( 'Ridicare Sameday Point / EasyBox', 'webbership-smartship' ),
        'description' => __( 'Label shown to the customer for the fallback rate.', 'webbership-smartship' ),
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
      'title'                  => $this->get_option( 'title' ),
      'fallback_amount'        => $this->get_option( 'fallback_amount' ),
      'fallback_per_kg_amount' => $this->get_option( 'fallback_per_kg_amount' ),
      'fallback_title'         => $this->get_option( 'fallback_title' ),
    ] );
  }

  public function calculate_shipping( $package = [] ): void {
    $config = $this->config();

    // Headless/cron (e.g. a subscription renewal) -> fallback, never call the API.
    // Checked BEFORE the block-checkout gate: a renewal that priced its shipping
    // via EasyBox must keep getting its fallback rate even on a blocks store.
    if ( wp_doing_cron() || ! function_exists( 'WC' ) || null === WC()->session ) {
      $this->add_fallback( $config, $package );
      return;
    }

    // The locker picker, its validation, and saving the chosen locker onto the
    // order all hook classic-checkout actions only. WooCommerce Blocks checkout
    // has no Store API integration for any of that yet, so offering this rate
    // there would let an order place with no locker collected. Suppress the rate
    // until block-checkout support is built. (Sessions that cached this rate
    // before a checkout-type switch self-heal when the cart/destination changes.)
    if ( $this->is_block_checkout() ) {
      return;
    }

    $dest = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : [];
    if ( 'RO' !== ( $dest['country'] ?? '' ) ) {
      $this->add_fallback( $config, $package );
      return;
    }

    $api_key = Settings::api_key();
    if ( '' === $api_key ) {
      $this->add_fallback( $config, $package );
      return;
    }

    $client  = new SmartShipClient( $api_key );
    $lockers = LockerRepository::all( $client );
    if ( empty( $lockers ) ) {
      $this->add_fallback( $config, $package );
      return;
    }

    // ponytail: one representative locker prices the whole county's EasyBox rate —
    // per-locker repricing if SmartShip ever differentiates cost within a county.
    $locker = LockerRepository::representative_locker( $lockers, $this->county_name( (string) ( $dest['state'] ?? '' ) ) );
    if ( null === $locker ) {
      $this->add_fallback( $config, $package );
      return;
    }

    $costs = CostService::costs_for( $package, $client, (int) $locker['id'] );
    if ( null === $costs ) {
      $this->add_fallback( $config, $package );
      return;
    }

    $easybox_cost = CostService::courier_cost( $costs, 12 ); // 12 = SameDay EasyBox.
    if ( null === $easybox_cost ) {
      $this->add_fallback( $config, $package );
      return;
    }

    $divisor = Tax::shipping_vat_divisor(); // API returns cu TVA; WC expects ex-VAT.
    $this->add_rate( [
      'id'        => $this->get_rate_id(),
      'label'     => $config['title'],
      'cost'      => round( $easybox_cost / $divisor, 2 ),
      'meta_data' => [ 'easybox' => 1 ],
    ] );
  }

  /** WC's RO state name for a package's state code (e.g. 'TM' -> 'Timiș'), or '' if unavailable. */
  private function county_name( string $state_code ): string {
    if ( '' === $state_code || ! function_exists( 'WC' ) || ! WC()->countries ) {
      return '';
    }
    $states = (array) WC()->countries->get_states( 'RO' );
    return (string) ( $states[ $state_code ] ?? '' );
  }

  private function is_block_checkout(): bool {
    $util = '\\Automattic\\WooCommerce\\Blocks\\Utils\\CartCheckoutUtils';
    if ( class_exists( $util ) && method_exists( $util, 'is_checkout_block_default' ) ) {
      return (bool) $util::is_checkout_block_default();
    }
    return function_exists( 'has_block' ) && function_exists( 'wc_get_page_id' )
      && has_block( 'woocommerce/checkout', wc_get_page_id( 'checkout' ) );
  }

  private function add_fallback( array $config, array $package ): void {
    $weight_kg = CostService::package_weight_kg( $package );
    $fb        = EasyBoxPricing::fallback( $config, $weight_kg );
    $this->add_rate( [
      'id'        => $this->get_rate_id(),
      'label'     => $fb['label'],
      'cost'      => $fb['cost'],
      'meta_data' => [ 'easybox' => 1, 'fallback' => 1 ],
    ] );
  }
}
