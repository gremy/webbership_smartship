<?php
declare(strict_types=1);

namespace Ovride\Smartship;

defined( 'ABSPATH' ) || exit;

/**
 * Loads plugin translations.
 *
 * @package Ovride\Smartship
 */
final class I18n {
  public function register(): void {
    // Hook `init`, not `plugins_loaded`, to avoid the WP 6.7 just-in-time textdomain notice.
    add_action( 'init', [ $this, 'load_textdomain' ] );
  }

  public function load_textdomain(): void {
    load_plugin_textdomain(
      'ovride-smartship',
      false,
      dirname( plugin_basename( OVRIDE_SMARTSHIP_FILE ) ) . '/languages'
    );
  }
}
