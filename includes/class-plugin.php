<?php
declare(strict_types=1);

namespace Ovride\Smartship;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap: singleton + module loader.
 *
 * @package Ovride\Smartship
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

    ( new \Ovride\Smartship\Settings\Settings() )->register_hooks();

    require_once OVRIDE_SMARTSHIP_DIR . 'modules/awb/class-awb-module.php';
    $this->modules[] = new \Ovride\Smartship\Modules\Awb\AwbModule();

    foreach ( $this->modules as $module ) {
      if ( $module->is_supported() ) {
        $module->register_hooks();
      }
    }
  }

  public static function activate(): void {
    update_option( 'ovride_smartship_version', OVRIDE_SMARTSHIP_VERSION, false );
  }

  public static function deactivate(): void {
    global $wpdb;
    $wpdb->query(
      "DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '\\_transient\\_ovride\\_smartship\\_%'
           OR option_name LIKE '\\_transient\\_timeout\\_ovride\\_smartship\\_%'"
    );
  }
}
