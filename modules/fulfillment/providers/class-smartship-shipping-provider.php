<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\Fulfillment\Providers;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Admin\Features\Fulfillments\Providers\AbstractShippingProvider;

/**
 * Registers SmartShip as a WooCommerce Fulfillments shipping provider.
 *
 * try_parse_tracking_number() is deliberately not overridden: SmartShip AWBs
 * are whatever per-courier format the assigned courier (DPD, Cargus, ...)
 * uses, so there is no distinctive SmartShip pattern to auto-detect from a
 * bare tracking number. The parent's default (always null) is correct here —
 * FulfillmentModule::sync_from_order() does the real, deterministic match
 * against the order's stored AWB instead.
 *
 * @package Webbership\Smartship\Modules\Fulfillment\Providers
 */
final class SmartshipShippingProvider extends AbstractShippingProvider {
  private const TRACKING_BASE = 'https://smartship.ro/t/';

  /** SmartShip's official logo — no local asset. */
  private const ICON = 'https://smartship.ro/images/logo_curieri/logo_smartship_delivery_square.png';

  public function get_key(): string {
    return 'webbership-smartship';
  }

  public function get_name(): string {
    return 'SmartShip'; // Brand name — not translated.
  }

  public function get_icon(): string {
    return self::ICON;
  }

  public function get_tracking_url( string $tracking_number ): string {
    return self::tracking_url( $tracking_number );
  }

  public function get_shipping_from_countries(): array {
    return [ 'RO' ];
  }

  /** Pure helper so it's testable without WooCommerce loaded. */
  public static function tracking_url( string $awb ): string {
    return self::TRACKING_BASE . rawurlencode( trim( $awb ) );
  }
}
