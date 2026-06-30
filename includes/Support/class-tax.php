<?php
declare(strict_types=1);

namespace Webbership\Smartship\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Tax-related help text.
 *
 * @package Webbership\Smartship\Support
 */
final class Tax {
  /**
   * A note stating whether a flat shipping amount is entered with or without VAT,
   * based on the store's WooCommerce tax configuration. WooCommerce treats shipping
   * costs as tax-exclusive and adds shipping tax on top at checkout when taxes are on.
   */
  public static function shipping_note(): string {
    if ( ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() ) {
      return __( 'Taxes are off in WooCommerce, so this is the final price the customer pays.', 'webbership-smartship' );
    }

    $rate = '';
    if ( class_exists( '\WC_Tax' ) ) {
      $pcts = array_values( array_unique( array_map(
        static function ( $r ) {
          return (float) ( $r['rate'] ?? 0 );
        },
        (array) \WC_Tax::get_shipping_tax_rates()
      ) ) );
      if ( 1 === count( $pcts ) && $pcts[0] > 0 ) {
        $rate = rtrim( rtrim( number_format( $pcts[0], 2 ), '0' ), '.' ) . '%';
      }
    }

    if ( '' !== $rate ) {
      /* translators: %s: the store's shipping tax rate, e.g. "21%". */
      return sprintf( __( 'Enter the amount WITHOUT VAT — WooCommerce adds %s shipping tax on top at checkout.', 'webbership-smartship' ), $rate );
    }
    return __( 'Enter the amount WITHOUT VAT — WooCommerce adds shipping tax on top at checkout, per your tax settings.', 'webbership-smartship' );
  }
}
