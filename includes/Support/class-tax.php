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
  /**
   * Returns the factor by which to divide an API cost that already includes VAT,
   * so WooCommerce can add the correct tax on top without double-taxing.
   * e.g. at 21%: 31.35 / 1.21 = 25.91 → WC adds 21% → 31.35 displayed.
   * Returns 1.0 when taxes are disabled or no shipping tax rate is configured.
   */
  public static function shipping_vat_divisor(): float {
    if ( ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() || ! class_exists( '\WC_Tax' ) ) {
      return 1.0;
    }

    $customer = function_exists( 'WC' ) && WC() ? WC()->customer ?? null : null;
    if ( $customer && is_callable( [ $customer, 'get_is_vat_exempt' ] ) && $customer->get_is_vat_exempt() ) {
      return 1.0;
    }

    // ponytail: sums rates as flat (additive), not compound — correct for this
    // store's current flat-VAT setup. If a compound shipping tax class is ever
    // configured, this needs to multiply factors sequentially instead.
    $total = array_sum( array_column( (array) \WC_Tax::get_shipping_tax_rates(), 'rate' ) );
    return $total > 0 ? 1 + $total / 100 : 1.0;
  }

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
