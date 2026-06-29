<?php
declare(strict_types=1);

namespace Webbership\Smartship;

defined( 'ABSPATH' ) || exit;

/**
 * Runtime dependency probes.
 *
 * @package Webbership\Smartship
 */
final class Dependencies {
  public static function woocommerce_active(): bool {
    return class_exists( 'WooCommerce' );
  }

  public static function smartship_configured(): bool {
    return \Webbership\Smartship\Settings\Settings::api_key() !== '';
  }
}
