<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\EasyBox;

defined( 'ABSPATH' ) || exit;

use Webbership\Smartship\Module;

/**
 * Registers the EasyBox locker shipping method.
 *
 * @package Webbership\Smartship\Modules\EasyBox
 */
final class EasyBoxModule extends Module {
  public function name(): string { return 'easybox'; }

  public function is_supported(): bool { return class_exists( 'WooCommerce' ); }

  public function register_hooks(): void {
    require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/easybox/class-easybox-method.php';
    add_filter( 'woocommerce_shipping_methods', [ $this, 'register_method' ] );
  }

  public function register_method( array $methods ): array {
    $methods['webbership_smartship_easybox'] = EasyBoxMethod::class;
    return $methods;
  }
}
