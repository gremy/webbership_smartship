<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\Awb\Data;

defined( 'ABSPATH' ) || exit;

use Webbership\Smartship\Settings\Settings;

/**
 * Builds the SmartShip recipient/sender/content payload from a WC_Order.
 *
 * @package Webbership\Smartship\Modules\Awb\Data
 */
final class AwbPayload {

  /**
   * SmartShip requires a postal code for non-RO recipients (its API rejects with
   * error 20237 otherwise), but the exact JSON key it expects hasn't been confirmed
   * against the live API/vendor docs yet — 'postal_code' is a provisional guess.
   * Centralized here so that when SmartShip confirms the real key, this is the only line to change.
   */
  public const EXTERNAL_POSTCODE_FIELD = 'postal_code';

  /** RO ships through CityResolver's numeric city ids; everything else is the free-text international path. */
  public static function is_domestic( string $country ): bool {
    return '' === $country || 'RO' === strtoupper( trim( $country ) );
  }

  /** Recipient country from the order: shipping first, billing fallback, 'RO' default. */
  public static function order_country( $order ): string {
    $country = (string) ( $order->get_shipping_country() ?: $order->get_billing_country() );
    return '' !== $country ? $country : 'RO';
  }

  /** International recipients have no city-id resolution step — gate on city text + postal code instead. */
  public static function international_ready( array $resolved ): bool {
    return '' !== trim( (string) ( $resolved['city_text'] ?? '' ) ) && '' !== trim( (string) ( $resolved['postcode'] ?? '' ) );
  }

  /**
   * Caller must gate on $resolved['confident'] before building for a domestic (RO)
   * order: a resolution miss yields city => 0, which SmartShip rejects. For a
   * non-RO order, gate on international_ready( $resolved ) instead.
   */
  public static function recipient_from_order( $order, array $resolved ): array {
    $name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
    if ( '' === $name ) {
      $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
    }
    // Both address lines must come from the SAME source: mixing a shipping line 1
    // with a billing line 2 would build a nonsensical address.
    $use_shipping = (string) $order->get_shipping_address_1() !== '';
    $addr1   = $use_shipping ? $order->get_shipping_address_1() : $order->get_billing_address_1();
    $addr2   = $use_shipping ? $order->get_shipping_address_2() : $order->get_billing_address_2();
    $address = trim( $addr1 . ( $addr2 !== '' ? ' ' . $addr2 : '' ) );
    $phone   = $order->get_shipping_phone() ?: $order->get_billing_phone();
    $country = self::order_country( $order );

    if ( ! self::is_domestic( $country ) ) {
      // No CityResolver for non-RO — its geolocation endpoints are RO-only. City is
      // free text (merchant-editable in the metabox); SmartShip requires a postal
      // code for external destinations, sent under the provisional key above.
      $city     = isset( $resolved['city_text'] ) && '' !== (string) $resolved['city_text']
        ? (string) $resolved['city_text']
        : (string) ( $order->get_shipping_city() ?: $order->get_billing_city() );
      $postcode = isset( $resolved['postcode'] ) && '' !== (string) $resolved['postcode']
        ? (string) $resolved['postcode']
        : (string) ( $order->get_shipping_postcode() ?: $order->get_billing_postcode() );
      return [
        'name'    => $name,
        'address' => (string) $address,
        'email'   => (string) $order->get_billing_email(),
        'city'    => $city,
        'phone'   => (string) $phone,
        'country' => $country,
        'sector'  => '0',
        self::EXTERNAL_POSTCODE_FIELD => $postcode,
      ];
    }

    return [
      'name'    => $name,
      'address' => (string) $address,
      'email'   => (string) $order->get_billing_email(),
      'city'    => isset( $resolved['city_id'] ) ? (int) $resolved['city_id'] : 0,
      'phone'   => (string) $phone,
      'country' => $country,
      'sector'  => self::canonical_sector( $resolved['sector'] ?? '0' ),
    ];
  }

  /**
   * Canonicalize a sector value at an API payload boundary: SmartShip's convention
   * is '0' = no sector, but some resolution paths yield '' (e.g. CityResolver's
   * internal "Bucharest sector unknown" signal) — never let '' reach the API.
   */
  public static function canonical_sector( $sector ): string {
    $sector = (string) ( $sector ?? '0' );
    return '' !== $sector ? $sector : '0';
  }

  /** Sum -> convert to kg (store weight unit may be g/lbs/oz; SmartShip expects kg). */
  public static function to_kg( float $weight ): float {
    return function_exists( 'wc_get_weight' ) ? (float) wc_get_weight( $weight, 'kg' ) : $weight;
  }

  /** Sum of product weights on the order, kg-converted, floored at 1.0 — the PREFILL default shown in the metabox. */
  public static function order_weight_kg( $order ): float {
    $weight = 0.0;
    foreach ( $order->get_items() as $item ) {
      $product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
      $qty     = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1;
      if ( $product && '' !== (string) $product->get_weight() ) {
        $weight += (float) $product->get_weight() * max( 1, $qty );
      }
    }
    return max( 1.0, self::to_kg( $weight ) );
  }

  /**
   * $package carries merchant-posted overrides from the AWB metabox (already
   * range-validated by AwbMetabox::posted_package()): 'weight', 'length', 'width',
   * 'height'. A posted weight wins outright — including going under the computed
   * 1.0kg floor — since the merchant is stating the real packed weight; it's only
   * floored at 0.05 to keep a stray near-zero value out of the SmartShip payload.
   * Missing keys fall back to the computed weight / the historical 10x10x10 box.
   */
  public static function content_from_order( $order, array $package = [] ): array {
    $weight = isset( $package['weight'] ) ? max( 0.05, (float) $package['weight'] ) : self::order_weight_kg( $order );

    $cod = 0.0;
    if ( ! $order->is_paid() || 'cod' === $order->get_payment_method() ) {
      $cod = (float) $order->get_total();
    }

    $content = [
      'package_content'  => 'Comanda ' . $order->get_order_number(),
      'parcels'          => 1,
      'weight'           => $weight,
      'cash_on_delivery' => $cod,
      'length'           => isset( $package['length'] ) ? (int) $package['length'] : 10,
      'width'            => isset( $package['width'] ) ? (int) $package['width'] : 10,
      'height'           => isset( $package['height'] ) ? (int) $package['height'] : 10,
      'insurance'        => 0,
      'iban'             => Settings::iban(),
      'open_package'     => 0,
      'order_id'         => (string) $order->get_order_number(),
    ];

    // EasyBox orders (locker chosen at checkout) must ship on courier 12 with the
    // locker id attached — see AwbMetabox::ajax_issue(), which forces courier_id
    // to 12 whenever this meta is set.
    $locker_id = (int) $order->get_meta( '_webbership_smartship_easybox_id' );
    if ( $locker_id > 0 ) {
      $content['locker_id'] = $locker_id;
    }

    return $content;
  }

  public static function sender_from_account( array $sender ): array {
    return [
      'name'    => (string) ( $sender['nume'] ?? '' ),
      'address' => (string) ( $sender['adresa'] ?? '' ),
      'email'   => (string) ( $sender['email'] ?? '' ),
      'city'    => (int) ( $sender['localitate_id'] ?? 0 ),
      'phone'   => (string) ( $sender['telefon'] ?? '' ),
      'country' => 'RO',
      'sector'  => self::canonical_sector( $sender['sector'] ?? '0' ),
    ];
  }

  public static function build( $order, array $resolved, array $sender, int $courier_id, array $package = [] ): array {
    return [
      'recipient'  => self::recipient_from_order( $order, $resolved ),
      'sender'     => self::sender_from_account( $sender ),
      'content'    => self::content_from_order( $order, $package ),
      'courier_id' => $courier_id,
    ];
  }
}
