<?php
declare(strict_types=1);

namespace Ovride\Smartship\Modules\Awb\Data;

defined( 'ABSPATH' ) || exit;

use Ovride\Smartship\Settings\Settings;

/**
 * Builds the SmartShip recipient/sender/content payload from a WC_Order.
 *
 * @package Ovride\Smartship\Modules\Awb\Data
 */
final class AwbPayload {

  public static function recipient_from_order( $order, array $resolved ): array {
    $name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
    if ( '' === $name ) {
      $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
    }
    $address = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
    $phone   = $order->get_shipping_phone() ?: $order->get_billing_phone();
    return [
      'name'    => $name,
      'address' => (string) $address,
      'email'   => (string) $order->get_billing_email(),
      'city'    => isset( $resolved['city_id'] ) ? (int) $resolved['city_id'] : 0,
      'phone'   => (string) $phone,
      'country' => 'RO',
      'sector'  => '0',
    ];
  }

  public static function content_from_order( $order ): array {
    $weight = 0.0;
    foreach ( $order->get_items() as $item ) {
      $product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
      if ( $product && '' !== (string) $product->get_weight() ) {
        $weight += (float) $product->get_weight();
      }
    }
    $weight = max( 1.0, $weight );

    $cod = 0.0;
    if ( ! $order->is_paid() || 'cod' === $order->get_payment_method() ) {
      $cod = (float) $order->get_total();
    }

    return [
      'package_content'  => 'Comanda ' . $order->get_order_number(),
      'parcels'          => 1,
      'weight'           => $weight,
      'cash_on_delivery' => $cod,
      'length'           => 10,
      'width'            => 10,
      'height'           => 10,
      'insurance'        => 0,
      'iban'             => Settings::iban(),
      'open_package'     => 0,
      'order_id'         => (string) $order->get_order_number(),
    ];
  }

  public static function sender_from_account( array $sender ): array {
    return [
      'name'    => (string) ( $sender['nume'] ?? '' ),
      'address' => (string) ( $sender['adresa'] ?? '' ),
      'email'   => (string) ( $sender['email'] ?? '' ),
      'city'    => (int) ( $sender['localitate_id'] ?? 0 ),
      'phone'   => (string) ( $sender['telefon'] ?? '' ),
      'country' => 'RO',
      'sector'  => (string) ( $sender['sector'] ?? '0' ),
    ];
  }

  public static function build( $order, array $resolved, array $sender, int $courier_id ): array {
    return [
      'recipient'  => self::recipient_from_order( $order, $resolved ),
      'sender'     => self::sender_from_account( $sender ),
      'content'    => self::content_from_order( $order ),
      'courier_id' => $courier_id,
    ];
  }
}
