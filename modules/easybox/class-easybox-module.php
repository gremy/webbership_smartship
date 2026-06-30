<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\EasyBox;

defined( 'ABSPATH' ) || exit;

use Webbership\Smartship\Module;
use Webbership\Smartship\Api\SmartShipClient;
use Webbership\Smartship\Settings\Settings;

/**
 * Registers the EasyBox locker shipping method and the checkout locker-list endpoint.
 *
 * @package Webbership\Smartship\Modules\EasyBox
 */
final class EasyBoxModule extends Module {
  public function name(): string { return 'easybox'; }

  public function is_supported(): bool { return class_exists( 'WooCommerce' ); }

  public function register_hooks(): void {
    require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/easybox/class-easybox-method.php';
    require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/easybox/class-locker-repository.php';
    add_filter( 'woocommerce_shipping_methods', [ $this, 'register_method' ] );
    add_action( 'wp_ajax_webbership_ss_lockers', [ $this, 'ajax_lockers' ] );
    add_action( 'wp_ajax_nopriv_webbership_ss_lockers', [ $this, 'ajax_lockers' ] );
  }

  public function register_method( array $methods ): array {
    $methods['webbership_smartship_easybox'] = EasyBoxMethod::class;
    return $methods;
  }

  /**
   * Public read endpoint for the checkout picker: the normalized, cached locker list.
   * No nonce — the data is public and the transient rate-limits the upstream call.
   */
  public function ajax_lockers(): void {
    $key = Settings::api_key();
    if ( '' === $key ) {
      wp_send_json_error( [ 'message' => __( 'EasyBox is not configured.', 'webbership-smartship' ) ], 503 );
    }
    // Public, slowly-changing list — let the browser cache it (override admin-ajax's no-cache).
    header( 'Cache-Control: public, max-age=3600' );
    wp_send_json_success( [ 'lockers' => LockerRepository::all( new SmartShipClient( $key ) ) ] );
  }
}
