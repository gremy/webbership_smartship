<?php
declare(strict_types=1);

namespace Ovride\Smartship\Modules\CheckoutRates;

defined( 'ABSPATH' ) || exit;

use Ovride\Smartship\Module;
use Ovride\Smartship\Dependencies;

/**
 * @package Ovride\Smartship\Modules\CheckoutRates
 */
final class CheckoutRatesModule extends Module {
  public function name(): string { return 'checkout-rates'; }

  public function is_supported(): bool { return Dependencies::woocommerce_active(); }

  public function register_hooks(): void {
    require_once OVRIDE_SMARTSHIP_DIR . 'modules/checkout-rates/class-rate-calculator.php';
    require_once OVRIDE_SMARTSHIP_DIR . 'modules/checkout-rates/class-shipping-method.php';
    add_filter( 'woocommerce_shipping_methods', [ $this, 'register_method' ] );
  }

  public function register_method( array $methods ): array {
    $methods['ovride_smartship'] = ShippingMethod::class;
    return $methods;
  }
}
