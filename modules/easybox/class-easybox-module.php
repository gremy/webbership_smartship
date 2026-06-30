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
    require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/easybox/class-easybox-order.php';
    ( new EasyBoxOrder() )->register_hooks();
    add_filter( 'woocommerce_shipping_methods', [ $this, 'register_method' ] );
    add_action( 'wp_ajax_webbership_ss_lockers', [ $this, 'ajax_lockers' ] );
    add_action( 'wp_ajax_nopriv_webbership_ss_lockers', [ $this, 'ajax_lockers' ] );
    add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_picker' ] );
    add_action( 'woocommerce_review_order_after_shipping', [ $this, 'render_picker' ] );
  }

  public function register_method( array $methods ): array {
    $methods['webbership_smartship_easybox'] = EasyBoxMethod::class;
    return $methods;
  }

  /**
   * Register (not auto-enqueue Leaflet) the lazy checkout locker picker.
   * Only on the classic checkout. The heavy Leaflet css/js is injected by the
   * picker itself, on first need, when EasyBox is the chosen rate — so a checkout
   * that never picks EasyBox never downloads the map library.
   */
  public function enqueue_picker(): void {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
      return;
    }
    wp_enqueue_style( 'webbership-ss-easybox', WEBBERSHIP_SMARTSHIP_URL . 'assets/css/easybox-checkout.css', [], WEBBERSHIP_SMARTSHIP_VERSION );
    wp_enqueue_script( 'webbership-ss-easybox', WEBBERSHIP_SMARTSHIP_URL . 'assets/js/easybox-checkout.js', [ 'jquery' ], WEBBERSHIP_SMARTSHIP_VERSION, true );

    $vendor = WEBBERSHIP_SMARTSHIP_URL . 'assets/vendor/leaflet/';
    wp_localize_script( 'webbership-ss-easybox', 'WebbershipEasyBox', [
      'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
      'action'    => 'webbership_ss_lockers',
      'methodId'  => EasyBoxPricing::METHOD_ID,
      'preferred' => $this->preferred_locker(),
      'leaflet'   => [
        'js'      => $vendor . 'leaflet.js',
        'css'     => [
          $vendor . 'leaflet.css',
          $vendor . 'MarkerCluster.css',
          $vendor . 'MarkerCluster.Default.css',
        ],
        'cluster' => $vendor . 'leaflet.markercluster.js',
      ],
      'i18n'      => [
        'search'   => __( 'Search by city, address, or locker name', 'webbership-smartship' ),
        'loading'  => __( 'Loading lockers…', 'webbership-smartship' ),
        'empty'    => __( 'No lockers found — try another city.', 'webbership-smartship' ),
        'error'    => __( "Couldn't load lockers.", 'webbership-smartship' ),
        'retry'    => __( 'Retry', 'webbership-smartship' ),
        'selected' => __( 'Selected', 'webbership-smartship' ),
        'choose'   => __( 'Choose an EasyBox locker', 'webbership-smartship' ),
        'more'     => __( 'Type to narrow the list…', 'webbership-smartship' ),
      ],
    ] );
  }

  /**
   * The picker mount point + the hidden field the checkout submits.
   * Rendered hidden; the picker JS reveals it only when EasyBox is chosen.
   */
  public function render_picker(): void {
    echo '<tr class="webbership-ss-easybox-row" hidden><td colspan="2">';
    echo '<div class="webbership-ss-easybox" hidden></div>';
    echo '<input type="hidden" name="webbership_ss_locker" id="webbership_ss_locker" value="">';
    echo '</td></tr>';
  }

  /**
   * The logged-in customer's saved preferred locker (Task 6 writes it), or null.
   *
   * @return array<string,mixed>|null
   */
  private function preferred_locker(): ?array {
    if ( ! is_user_logged_in() ) {
      return null;
    }
    $saved = get_user_meta( get_current_user_id(), '_webbership_smartship_preferred_locker', true );
    return is_array( $saved ) && $saved ? $saved : null;
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
