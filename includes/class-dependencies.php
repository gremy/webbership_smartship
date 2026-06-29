<?php
declare(strict_types=1);

namespace Ovride\Smartship;

defined( 'ABSPATH' ) || exit;

/**
 * Runtime dependency probes.
 *
 * @package Ovride\Smartship
 */
final class Dependencies {
  public static function woocommerce_active(): bool {
    return class_exists( 'WooCommerce' );
  }
}
