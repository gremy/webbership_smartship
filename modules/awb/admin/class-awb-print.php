<?php
declare(strict_types=1);

namespace Ovride\Smartship\Modules\Awb\Admin;

defined( 'ABSPATH' ) || exit;

use Ovride\Smartship\Api\SmartShipClient;
use Ovride\Smartship\Settings\Settings;

/**
 * @package Ovride\Smartship\Modules\Awb\Admin
 */
final class AwbPrint {
  private const CAP    = 'edit_shop_orders';
  private const ACTION = 'ovride_smartship_print';

  public function register_hooks(): void {
    add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
  }

  public static function url( int $order_id, string $format ): string {
    return wp_nonce_url(
      add_query_arg( [ 'action' => self::ACTION, 'order_id' => $order_id, 'format' => $format ], admin_url( 'admin-post.php' ) ),
      self::ACTION
    );
  }

  public function handle(): void {
    if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Forbidden.', 'ovride-smartship' ) ); }
    check_admin_referer( self::ACTION );
    $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
    $format   = ( isset( $_GET['format'] ) && 'A6' === $_GET['format'] ) ? 'A6' : 'A4';
    $order    = $order_id ? wc_get_order( $order_id ) : false;
    if ( ! $order ) { wp_die( esc_html__( 'Order not found.', 'ovride-smartship' ) ); }
    $awb = (string) $order->get_meta( '_ovride_smartship_awb' );
    if ( '' === $awb ) { wp_die( esc_html__( 'No AWB on this order.', 'ovride-smartship' ) ); }

    $res = ( new SmartShipClient( Settings::api_key() ) )->print_awb( $awb, $format );
    if ( empty( $res['ok'] ) || empty( $res['pdf'] ) ) {
      wp_die( esc_html( $res['message'] ?: __( 'Could not fetch the label.', 'ovride-smartship' ) ) );
    }
    nocache_headers();
    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: inline; filename="AWB-' . sanitize_file_name( $awb ) . '.pdf"' );
    header( 'Content-Length: ' . strlen( $res['pdf'] ) );
    echo $res['pdf']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw PDF bytes
    exit;
  }
}
