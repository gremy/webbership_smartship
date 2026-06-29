<?php
declare(strict_types=1);

namespace Webbership\Smartship;

defined( 'ABSPATH' ) || exit;

/**
 * Loads plugin translations.
 *
 * @package Webbership\Smartship
 */
final class I18n {
  public function register(): void {
    // Hook `init`, not `plugins_loaded`, to avoid the WP 6.7 just-in-time textdomain notice.
    add_action( 'init', [ $this, 'load_textdomain' ] );
  }

  public function load_textdomain(): void {
    load_plugin_textdomain(
      'webbership-smartship',
      false,
      dirname( plugin_basename( WEBBERSHIP_SMARTSHIP_FILE ) ) . '/languages'
    );
  }
}
