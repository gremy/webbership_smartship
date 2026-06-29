<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\CheckoutRates;

defined( 'ABSPATH' ) || exit;

use Webbership\Smartship\Module;
use Webbership\Smartship\Dependencies;

/**
 * @package Webbership\Smartship\Modules\CheckoutRates
 */
final class CheckoutRatesModule extends Module {
  public function name(): string { return 'checkout-rates'; }

  public function is_supported(): bool { return Dependencies::woocommerce_active(); }

  public function register_hooks(): void {
    require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/checkout-rates/class-rate-calculator.php';
    require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/checkout-rates/class-shipping-method.php';
    add_filter( 'woocommerce_shipping_methods', [ $this, 'register_method' ] );
  }

  public function register_method( array $methods ): array {
    $methods['webbership_smartship'] = ShippingMethod::class;
    return $methods;
  }
}
