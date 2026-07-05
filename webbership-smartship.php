<?php
/**
 * Plugin Name:       Webbership SmartShip
 * Plugin URI:        https://github.com/gremy/webbership-smartship
 * Description:       SmartShip.ro courier integration for WooCommerce: live checkout rates and AWB issuance.
 * Version:           0.3.0
 * Requires at least: 6.4
 * Tested up to:      6.6
 * Requires PHP:      7.4
 * Author:            WEBBERSHIP SRL
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webbership-smartship
 * Domain Path:       /languages
 *
 * @package Webbership\Smartship
 */

defined( 'ABSPATH' ) || exit;

define( 'WEBBERSHIP_SMARTSHIP_FILE', __FILE__ );
define( 'WEBBERSHIP_SMARTSHIP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WEBBERSHIP_SMARTSHIP_URL', plugin_dir_url( __FILE__ ) );
define( 'WEBBERSHIP_SMARTSHIP_VERSION', '0.3.0' );

require_once WEBBERSHIP_SMARTSHIP_DIR . 'includes/class-module.php';
require_once WEBBERSHIP_SMARTSHIP_DIR . 'includes/class-dependencies.php';
require_once WEBBERSHIP_SMARTSHIP_DIR . 'includes/class-i18n.php';
require_once WEBBERSHIP_SMARTSHIP_DIR . 'includes/class-plugin.php';
require_once WEBBERSHIP_SMARTSHIP_DIR . 'includes/Settings/class-settings.php';
require_once WEBBERSHIP_SMARTSHIP_DIR . 'includes/Api/class-smartship-client.php';
require_once WEBBERSHIP_SMARTSHIP_DIR . 'includes/Support/class-city-resolver.php';
require_once WEBBERSHIP_SMARTSHIP_DIR . 'includes/Support/class-cost-service.php';
require_once WEBBERSHIP_SMARTSHIP_DIR . 'includes/Support/class-tax.php';
require_once WEBBERSHIP_SMARTSHIP_DIR . 'includes/class-logger.php';
require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/easybox/class-easybox-pricing.php';
require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/easybox/class-easybox-module.php';

register_activation_hook( __FILE__, [ 'Webbership\\Smartship\\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Webbership\\Smartship\\Plugin', 'deactivate' ] );

// Declare HPOS (custom order tables) compatibility.
add_action( 'before_woocommerce_init', static function () {
  if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
  }
} );

add_action( 'plugins_loaded', static function () {
  \Webbership\Smartship\Plugin::instance()->boot();
}, 20 );
