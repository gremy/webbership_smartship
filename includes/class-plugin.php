<?php
declare(strict_types=1);

namespace Webbership\Smartship;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap: singleton + module loader.
 *
 * @package Webbership\Smartship
 */
final class Plugin {
  private static ?self $instance = null;

  /** @var Module[] */
  private array $modules = [];

  public static function instance(): self {
    if ( null === self::$instance ) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  private function __construct() {}

  public function boot(): void {
    ( new I18n() )->register();

    if ( ! Dependencies::woocommerce_active() ) {
      return; // Nothing else loads without WooCommerce.
    }

    ( new \Webbership\Smartship\Settings\Settings() )->register_hooks();

    require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/awb/class-awb-module.php';
    $this->modules[] = new \Webbership\Smartship\Modules\Awb\AwbModule();

    require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/checkout-rates/class-checkout-rates-module.php';
    $this->modules[] = new \Webbership\Smartship\Modules\CheckoutRates\CheckoutRatesModule();

    $this->modules[] = new \Webbership\Smartship\Modules\EasyBox\EasyBoxModule();

    foreach ( $this->modules as $module ) {
      if ( $module->is_supported() ) {
        $module->register_hooks();
      }
    }
  }

  public static function activate(): void {
    update_option( 'webbership_smartship_version', WEBBERSHIP_SMARTSHIP_VERSION, false );
  }

  public static function deactivate(): void {
    global $wpdb;
    // Every transient this plugin sets is named webbership_ss_* (rates, sender
    // blocks, lockers, the failure-cache) — NOT webbership_smartship_*.
    $wpdb->query(
      "DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '\\_transient\\_webbership\\_ss\\_%'
           OR option_name LIKE '\\_transient\\_timeout\\_webbership\\_ss\\_%'"
    );
  }
}
