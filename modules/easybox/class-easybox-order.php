<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\EasyBox;

defined( 'ABSPATH' ) || exit;

/**
 * Capture the chosen EasyBox locker on the order (and remember it per customer).
 *
 * The locker arrives in a customer-controlled hidden field (`webbership_ss_locker`,
 * a JSON blob the picker writes). It is NEVER trusted: parse_locker() rejects
 * anything that isn't a well-formed locker and sanitizes every field before it
 * touches the order or user meta.
 *
 * @package Webbership\Smartship\Modules\EasyBox
 */
final class EasyBoxOrder {
  public function register_hooks(): void {
    add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate' ], 10, 2 );
    add_action( 'woocommerce_checkout_create_order', [ $this, 'save' ], 10, 2 );
  }

  /**
   * Block checkout when EasyBox is chosen but no valid locker was picked.
   *
   * @param array<string,mixed> $data   Posted checkout fields.
   * @param \WP_Error           $errors Collector; adding an error blocks the order.
   */
  public function validate( $data, $errors ): void {
    if ( ! $this->chosen_easybox( (array) $data ) ) {
      return;
    }
    if ( null === $this->parse_locker() ) {
      $errors->add( 'webbership_ss_locker', __( 'Please choose an EasyBox locker on the map.', 'webbership-smartship' ) );
    }
  }

  /**
   * Persist the sanitized locker snapshot on the order (HPOS-safe), and remember
   * it as the customer's preferred locker. WC saves the order after this hook, so
   * we must NOT call $order->save() here.
   *
   * @param \WC_Order           $order The order being created.
   * @param array<string,mixed> $data  Posted checkout fields.
   */
  public function save( $order, $data ): void {
    if ( ! $this->chosen_easybox( (array) $data ) ) {
      return;
    }
    $locker = $this->parse_locker();
    if ( null === $locker ) {
      return;
    }
    $order->update_meta_data( '_webbership_smartship_easybox_id', $locker['id'] );
    $order->update_meta_data( '_webbership_smartship_easybox_name', $locker['name'] );
    $order->update_meta_data( '_webbership_smartship_easybox_address', $locker['address'] );
    $order->update_meta_data( '_webbership_smartship_easybox_city', $locker['city'] );
    $order->update_meta_data( '_webbership_smartship_easybox_lat', $locker['lat'] );
    $order->update_meta_data( '_webbership_smartship_easybox_lng', $locker['lng'] );

    if ( is_user_logged_in() ) {
      update_user_meta( get_current_user_id(), '_webbership_smartship_preferred_locker', $locker );
    }
  }

  /**
   * True when the order's chosen shipping rate is the EasyBox method.
   *
   * WC posts `shipping_method` as an array of rate ids (one per package), e.g.
   * `webbership_smartship_easybox:3`. Be defensive: it may be absent or scalar.
   *
   * @param array<string,mixed> $posted
   */
  private function chosen_easybox( array $posted ): bool {
    $chosen = $posted['shipping_method'] ?? [];
    foreach ( (array) $chosen as $rate_id ) {
      $rate_id = (string) $rate_id;
      if ( EasyBoxPricing::METHOD_ID === $rate_id
        || 0 === strpos( $rate_id, EasyBoxPricing::METHOD_ID . ':' ) ) {
        return true;
      }
    }
    return false;
  }

  /**
   * Read, validate and sanitize the customer-controlled locker JSON.
   *
   * Returns a clean snapshot ONLY for a well-formed locker: a positive-int `id`
   * and non-empty `name`/`address`/`city` (each run through sanitize_text_field,
   * so no markup survives). `lat`/`lng` are optional floats. Anything missing or
   * malformed -> null (caller blocks checkout / skips the save).
   *
   * @return array{id:int,name:string,address:string,city:string,lat:float,lng:float}|null
   */
  private function parse_locker(): ?array {
    if ( empty( $_POST['webbership_ss_locker'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
      return null;
    }
    $raw = wp_unslash( $_POST['webbership_ss_locker'] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
    if ( ! is_string( $raw ) ) {
      return null;
    }
    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) ) {
      return null;
    }

    // id must be a CANONICAL positive integer: a real int, or a digit string that
    // round-trips through (int) — this rejects floats, "abc", "1e3", "0", negatives,
    // leading zeros ("01"->1), and overflow ("999…"-> PHP_INT_MAX), which would
    // silently become a *different* id.
    $id_raw = $data['id'] ?? null;
    if ( is_int( $id_raw ) ) {
      $id = $id_raw;
    } elseif ( is_string( $id_raw ) && ctype_digit( $id_raw ) && (string) (int) $id_raw === $id_raw ) {
      $id = (int) $id_raw;
    } else {
      return null;
    }
    if ( $id <= 0 ) {
      return null;
    }

    // name/address/city must be strings (reject arrays/objects -> no "Array" coercion), then sanitize.
    foreach ( [ 'name', 'address', 'city' ] as $f ) {
      if ( ! isset( $data[ $f ] ) || ! is_string( $data[ $f ] ) ) {
        return null;
      }
    }
    $name    = sanitize_text_field( $data['name'] );
    $address = sanitize_text_field( $data['address'] );
    $city    = sanitize_text_field( $data['city'] );
    if ( '' === $name || '' === $address || '' === $city ) {
      return null;
    }

    // lat/lng optional, but if present must be numeric scalars (reject "abc"/arrays).
    foreach ( [ 'lat', 'lng' ] as $f ) {
      if ( isset( $data[ $f ] ) && ! is_numeric( $data[ $f ] ) ) {
        return null;
      }
    }

    return [
      'id'      => $id,
      'name'    => $name,
      'address' => $address,
      'city'    => $city,
      'lat'     => isset( $data['lat'] ) ? (float) $data['lat'] : 0.0,
      'lng'     => isset( $data['lng'] ) ? (float) $data['lng'] : 0.0,
    ];
  }
}
