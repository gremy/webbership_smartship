<?php
declare(strict_types=1);

namespace Ovride\Smartship\Modules\Awb\Admin;

defined( 'ABSPATH' ) || exit;

use Ovride\Smartship\Api\SmartShipClient;
use Ovride\Smartship\Support\CityResolver;
use Ovride\Smartship\Settings\Settings;
use Ovride\Smartship\Modules\Awb\Data\AwbPayload;
use Ovride\Smartship\Modules\Awb\Admin\AwbPrint;

/**
 * @package Ovride\Smartship\Modules\Awb\Admin
 */
final class AwbMetabox {
  private const CAP   = 'edit_shop_orders';
  private const NONCE = 'ovride_smartship_awb';

  public function register_hooks(): void {
    add_action( 'add_meta_boxes', [ $this, 'add_box' ] );
    add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    add_action( 'wp_ajax_ovride_smartship_estimate', [ $this, 'ajax_estimate' ] );
    add_action( 'wp_ajax_ovride_smartship_issue', [ $this, 'ajax_issue' ] );
    add_action( 'wp_ajax_ovride_smartship_cities', [ $this, 'ajax_cities' ] );
    add_action( 'wp_ajax_ovride_smartship_status', [ $this, 'ajax_status' ] );
    add_action( 'wp_ajax_ovride_smartship_cancel', [ $this, 'ajax_cancel' ] );
  }

  public function add_box(): void {
    foreach ( [ 'shop_order', 'woocommerce_page_wc-orders' ] as $screen ) {
      add_meta_box( 'ovride-smartship-awb', __( 'SmartShip AWB', 'ovride-smartship' ), [ $this, 'render' ], $screen, 'side', 'default' );
    }
  }

  public function enqueue( string $hook ): void {
    if ( ! in_array( $hook, [ 'post.php', 'woocommerce_page_wc-orders' ], true ) ) {
      return;
    }
    wp_enqueue_script( 'ovride-smartship-awb', OVRIDE_SMARTSHIP_URL . 'assets/js/awb-metabox.js', [ 'jquery' ], OVRIDE_SMARTSHIP_VERSION, true );
    wp_localize_script( 'ovride-smartship-awb', 'OvrideSmartShip', [
      'ajax'  => admin_url( 'admin-ajax.php' ),
      'nonce' => wp_create_nonce( self::NONCE ),
    ] );
  }

  private function order_from_request() {
    $order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
    return $order_id ? wc_get_order( $order_id ) : false;
  }

  public function ajax_estimate(): void {
    check_ajax_referer( self::NONCE );
    if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( [ 'message' => __( 'Forbidden.', 'ovride-smartship' ) ], 403 ); }
    $order = $this->order_from_request();
    if ( ! $order ) { wp_send_json_error( [ 'message' => __( 'Order not found.', 'ovride-smartship' ) ], 404 ); }

    $client   = new SmartShipClient( Settings::api_key() );
    $resolved = $this->resolve_for( $order );
    $sender   = $this->chosen_sender( $client );
    $payload  = [
      'recipient' => AwbPayload::recipient_from_order( $order, $resolved ),
      'sender'    => AwbPayload::sender_from_account( $sender ),
      'content'   => AwbPayload::content_from_order( $order ),
    ];
    $res = $client->cost( $payload );
    if ( empty( $res['ok'] ) ) { wp_send_json_error( [ 'message' => $res['message'] ?: __( 'Estimate failed.', 'ovride-smartship' ), 'errors' => $res['errors'] ?? [] ] ); }
    wp_send_json_success( [ 'costs' => $res['costs'] ?? ( $res['response']['costs'] ?? [] ), 'resolved' => $resolved ] );
  }

  public function ajax_issue(): void {
    check_ajax_referer( self::NONCE );
    if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( [ 'message' => __( 'Forbidden.', 'ovride-smartship' ) ], 403 ); }
    $order = $this->order_from_request();
    if ( ! $order ) { wp_send_json_error( [ 'message' => __( 'Order not found.', 'ovride-smartship' ) ], 404 ); }
    $courier_id = isset( $_POST['courier_id'] ) ? absint( $_POST['courier_id'] ) : 0;
    if ( ! $courier_id ) { wp_send_json_error( [ 'message' => __( 'Choose a courier.', 'ovride-smartship' ) ] ); }

    $client  = new SmartShipClient( Settings::api_key() );
    $sender  = $this->chosen_sender( $client );
    $payload = AwbPayload::build( $order, $this->resolve_for( $order ), $sender, $courier_id );
    $res     = $client->create_awb( $payload );
    if ( empty( $res['ok'] ) ) {
      wp_send_json_error( [ 'message' => $res['message'] ?: __( 'AWB issue failed.', 'ovride-smartship' ), 'errors' => $res['errors'] ?? [], 'code' => $res['code'] ?? '' ] );
    }
    $awb          = sanitize_text_field( (string) ( $res['awb'] ?? '' ) );
    $courier_name = sanitize_text_field( (string) ( $res['courier_name'] ?? '' ) );
    $order->update_meta_data( '_ovride_smartship_awb', $awb );
    $order->update_meta_data( '_ovride_smartship_courier', $courier_name );
    $order->update_meta_data( '_ovride_smartship_cost', sanitize_text_field( (string) ( $res['cost'] ?? '' ) ) );
    $order->add_order_note( sprintf( /* translators: 1: AWB number, 2: courier */ __( 'SmartShip AWB %1$s issued (%2$s).', 'ovride-smartship' ), $awb, $courier_name ) );
    $order->save();
    wp_send_json_success( [ 'awb' => $awb ] );
  }

  /** Cities for a resolved county, so the merchant can pick the right one. */
  public function ajax_cities(): void {
    check_ajax_referer( self::NONCE );
    if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( [ 'message' => __( 'Forbidden.', 'ovride-smartship' ) ], 403 ); }
    $county_id = absint( $_POST['county_id'] ?? 0 );
    $res       = ( new SmartShipClient( Settings::api_key() ) )->get_cities( $county_id );
    if ( empty( $res['ok'] ) ) { wp_send_json_error( [ 'message' => $res['message'] ?: __( 'Could not load cities.', 'ovride-smartship' ) ] ); }
    wp_send_json_success( [ 'cities' => array_map(
      fn( $c ) => [ 'id' => (int) ( $c['id'] ?? 0 ), 'city' => sanitize_text_field( (string) ( $c['city'] ?? '' ) ) ],
      (array) ( $res['cities'] ?? [] )
    ) ] );
  }

  public function ajax_status(): void {
    check_ajax_referer( self::NONCE );
    if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( [ 'message' => __( 'Forbidden.', 'ovride-smartship' ) ], 403 ); }
    $order = $this->order_from_request();
    if ( ! $order ) { wp_send_json_error( [ 'message' => __( 'Order not found.', 'ovride-smartship' ) ], 404 ); }
    $awb = (string) $order->get_meta( '_ovride_smartship_awb' );
    if ( '' === $awb ) { wp_send_json_error( [ 'message' => __( 'No AWB on this order.', 'ovride-smartship' ) ], 400 ); }
    $res = ( new SmartShipClient( Settings::api_key() ) )->get_awb_status( $awb );
    if ( empty( $res['ok'] ) ) { wp_send_json_error( [ 'message' => $res['message'] ?: __( 'Status unavailable.', 'ovride-smartship' ) ] ); }
    wp_send_json_success( $res );
  }

  public function ajax_cancel(): void {
    check_ajax_referer( self::NONCE );
    if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( [ 'message' => __( 'Forbidden.', 'ovride-smartship' ) ], 403 ); }
    $order = $this->order_from_request();
    if ( ! $order ) { wp_send_json_error( [ 'message' => __( 'Order not found.', 'ovride-smartship' ) ], 404 ); }
    $awb = (string) $order->get_meta( '_ovride_smartship_awb' );
    if ( '' === $awb ) { wp_send_json_error( [ 'message' => __( 'No AWB on this order.', 'ovride-smartship' ) ], 400 ); }
    $res = ( new SmartShipClient( Settings::api_key() ) )->cancel_awb( $awb );
    if ( empty( $res['ok'] ) ) { wp_send_json_error( [ 'message' => $res['message'] ?: __( 'Cancel failed.', 'ovride-smartship' ) ] ); }
    $order->delete_meta_data( '_ovride_smartship_awb' );
    $order->delete_meta_data( '_ovride_smartship_courier' );
    $order->add_order_note( sprintf( /* translators: %s: AWB number */ __( 'SmartShip AWB %s cancelled.', 'ovride-smartship' ), $awb ) );
    $order->save();
    wp_send_json_success();
  }

  /** county/city: posted dropdown values win (both required); else the resolver. */
  private function resolve_for( $order ): array {
    $county = isset( $_POST['county_id'] ) ? absint( $_POST['county_id'] ) : 0;
    $city   = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;
    if ( $county && $city ) {
      return [ 'county_id' => $county, 'city_id' => $city, 'confident' => true ];
    }
    $resolver = new CityResolver( new SmartShipClient( Settings::api_key() ) );
    return $resolver->resolve( (string) $order->get_shipping_state(), (string) ( $order->get_shipping_city() ?: $order->get_billing_city() ) );
  }

  private function chosen_sender( SmartShipClient $client ): array {
    $res = $client->get_senders();
    $id  = Settings::sender_id();
    foreach ( (array) ( $res['senders'] ?? [] ) as $s ) {
      if ( (int) ( $s['id'] ?? 0 ) === $id ) { return $s; }
    }
    return (array) ( ( $res['senders'] ?? [] )[0] ?? [] );
  }

  public function render( $post_or_order ): void {
    $order = ( $post_or_order instanceof \WC_Order ) ? $post_or_order : wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : $post_or_order );
    if ( ! $order ) { return; }
    $awb = $order->get_meta( '_ovride_smartship_awb' );
    echo '<div class="ovride-ss-awb" data-order="' . esc_attr( (string) $order->get_id() ) . '">';
    if ( $awb ) {
      echo '<p><strong>' . esc_html__( 'AWB:', 'ovride-smartship' ) . '</strong> ' . esc_html( (string) $awb ) . '</p>';
      echo '<p><a class="button" target="_blank" href="' . esc_url( AwbPrint::url( (int) $order->get_id(), 'A4' ) ) . '">' . esc_html__( 'Print A4', 'ovride-smartship' ) . '</a> ';
      echo '<a class="button" target="_blank" href="' . esc_url( AwbPrint::url( (int) $order->get_id(), 'A6' ) ) . '">' . esc_html__( 'Print A6', 'ovride-smartship' ) . '</a></p>';
      echo '<p><button type="button" class="button ovride-ss-track">' . esc_html__( 'Refresh tracking', 'ovride-smartship' ) . '</button> ';
      echo '<button type="button" class="button ovride-ss-cancel">' . esc_html__( 'Cancel AWB', 'ovride-smartship' ) . '</button></p>';
      echo '<div class="ovride-ss-tracking"></div>';
    } else {
      echo '<button type="button" class="button ovride-ss-estimate">' . esc_html__( 'Estimate', 'ovride-smartship' ) . '</button>';
      echo '<div class="ovride-ss-couriers"></div>';
      echo '<div class="ovride-ss-msg"></div>';
    }
    echo '</div>';
  }
}
