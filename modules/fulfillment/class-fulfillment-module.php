<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\Fulfillment;

defined( 'ABSPATH' ) || exit;

use Webbership\Smartship\Module;
use Webbership\Smartship\Modules\Fulfillment\Providers\SmartshipShippingProvider;
use Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment;

/**
 * Registers the SmartShip WooCommerce Fulfillments shipping provider and
 * keeps a fulfillment's provider/tracking data in sync with the AWB the
 * AWB module already issued and stored on the order.
 *
 * WC's own provider auto-detection is a best-effort regex match against the
 * tracking number alone, so a numeric DPD/Cargus AWB can be guessed as some
 * other courier. Hooking the fulfillment save corrects it deterministically
 * whenever the tracking number matches the order's stored SmartShip AWB.
 *
 * @package Webbership\Smartship\Modules\Fulfillment
 */
final class FulfillmentModule extends Module {
  private const PROVIDER_KEY = 'webbership-smartship';
  private const AWB_META     = '_webbership_smartship_awb';
  private const COURIER_META = '_webbership_smartship_courier';

  public function name(): string { return 'fulfillment'; }

  public function is_supported(): bool {
    return class_exists( 'Automattic\WooCommerce\Admin\Features\Fulfillments\Providers\AbstractShippingProvider' );
  }

  public function register_hooks(): void {
    require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/fulfillment/providers/class-smartship-shipping-provider.php';

    add_filter( 'woocommerce_fulfillment_shipping_providers', [ $this, 'register_provider' ] );
    add_filter( 'woocommerce_fulfillment_before_create', [ $this, 'sync_from_order' ] );
    add_filter( 'woocommerce_fulfillment_before_update', [ $this, 'sync_from_order' ] );
    add_action( 'woocommerce_email_after_fulfillment_table', [ $this, 'render_courier_line' ], 10, 5 );
  }

  public function register_provider( array $providers ): array {
    $providers[ self::PROVIDER_KEY ] = SmartshipShippingProvider::class;
    return $providers;
  }

  /**
   * Fired on both woocommerce_fulfillment_before_create and _before_update.
   * If the fulfillment's tracking number matches the order's SmartShip AWB,
   * pin the provider/tracking URL/courier deterministically; otherwise leave
   * WC's own detection (or the merchant's manual choice) untouched.
   *
   * @param mixed $fulfillment Expected to be a Fulfillment; returned as-is.
   * @return mixed
   */
  public function sync_from_order( $fulfillment ) {
    if ( ! $fulfillment instanceof Fulfillment ) {
      return $fulfillment;
    }

    $order = $fulfillment->get_order();
    if ( ! $order ) {
      return $fulfillment;
    }

    $order_awb       = (string) $order->get_meta( self::AWB_META );
    $tracking_number = (string) $fulfillment->get_meta( '_tracking_number', true );
    if ( ! self::awb_matches( $order_awb, $tracking_number ) ) {
      return $fulfillment;
    }

    $fulfillment->set_shipment_provider( self::PROVIDER_KEY );
    $fulfillment->set_tracking_url( SmartshipShippingProvider::tracking_url( $tracking_number ) );
    $fulfillment->update_meta_data( self::COURIER_META, (string) $order->get_meta( self::COURIER_META ) );

    return $fulfillment;
  }

  /**
   * Adds a "Courier: DPD (SmartShip)" line to the fulfillment tracking email,
   * for the SmartShip-fulfilled shipments only.
   *
   * @param \WC_Order   $order         The order.
   * @param mixed       $fulfillment   Expected to be a Fulfillment.
   * @param bool        $sent_to_admin Whether the email goes to the admin.
   * @param bool        $plain_text    Whether this is the plain-text email.
   * @param \WC_Email   $email         The email object.
   */
  public function render_courier_line( $order, $fulfillment, bool $sent_to_admin, bool $plain_text, $email ): void {
    if ( ! $fulfillment instanceof Fulfillment || self::PROVIDER_KEY !== $fulfillment->get_shipment_provider() ) {
      return;
    }

    $courier = (string) $fulfillment->get_meta( self::COURIER_META );
    if ( '' === $courier && $order instanceof \WC_Order ) {
      $courier = (string) $order->get_meta( self::COURIER_META );
    }
    if ( '' === $courier ) {
      return;
    }

    if ( $plain_text ) {
      echo esc_html__( 'Courier:', 'webbership-smartship' ) . ' ' . esc_html( $courier ) . " (SmartShip)\n";
    } else {
      echo '<p><strong>' . esc_html__( 'Courier:', 'webbership-smartship' ) . '</strong> ' . esc_html( $courier ) . ' (SmartShip)</p>';
    }
  }

  /** Trim + case-insensitive compare; false whenever either side is empty. */
  public static function awb_matches( string $order_awb, string $tracking_number ): bool {
    $order_awb       = trim( $order_awb );
    $tracking_number = trim( $tracking_number );
    if ( '' === $order_awb || '' === $tracking_number ) {
      return false;
    }
    return 0 === strcasecmp( $order_awb, $tracking_number );
  }
}
