<?php
/**
 * Plugin Name:       OVRIDE SmartShip
 * Plugin URI:        https://github.com/gremy/ovride
 * Description:       SmartShip.ro courier integration for WooCommerce: live checkout rates, AWB issuance, and SameDay EasyBox locker delivery.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Tested up to:      6.6
 * Requires PHP:      7.4
 * Author:            OVRIDE Coffee
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ovride-smartship
 * Domain Path:       /languages
 *
 * @package Ovride\Smartship
 */

defined( 'ABSPATH' ) || exit;

define( 'OVRIDE_SMARTSHIP_FILE', __FILE__ );
define( 'OVRIDE_SMARTSHIP_DIR', plugin_dir_path( __FILE__ ) );
define( 'OVRIDE_SMARTSHIP_URL', plugin_dir_url( __FILE__ ) );
define( 'OVRIDE_SMARTSHIP_VERSION', '0.1.0' );

require_once OVRIDE_SMARTSHIP_DIR . 'includes/class-module.php';
require_once OVRIDE_SMARTSHIP_DIR . 'includes/class-dependencies.php';
require_once OVRIDE_SMARTSHIP_DIR . 'includes/class-i18n.php';
require_once OVRIDE_SMARTSHIP_DIR . 'includes/class-plugin.php';
require_once OVRIDE_SMARTSHIP_DIR . 'includes/Settings/class-settings.php';

register_activation_hook( __FILE__, [ 'Ovride\\Smartship\\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Ovride\\Smartship\\Plugin', 'deactivate' ] );

// Declare HPOS (custom order tables) compatibility.
add_action( 'before_woocommerce_init', static function () {
  if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
  }
} );

add_action( 'plugins_loaded', static function () {
  \Ovride\Smartship\Plugin::instance()->boot();
}, 20 );
