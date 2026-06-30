<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\Awb\Admin;

defined( 'ABSPATH' ) || exit;

use Webbership\Smartship\Api\SmartShipClient;
use Webbership\Smartship\Support\CityResolver;
use Webbership\Smartship\Settings\Settings;
use Webbership\Smartship\Modules\Awb\Data\AwbPayload;
use Webbership\Smartship\Modules\Awb\Admin\AwbPrint;

/**
 * @package Webbership\Smartship\Modules\Awb\Admin
 */
final class AwbMetabox {
  private const CAP   = 'edit_shop_orders';
  private const NONCE = 'webbership_smartship_awb';

  public function register_hooks(): void {
    add_action( 'add_meta_boxes', [ $this, 'add_box' ] );
    add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    add_action( 'wp_ajax_webbership_smartship_estimate', [ $this, 'ajax_estimate' ] );
    add_action( 'wp_ajax_webbership_smartship_issue', [ $this, 'ajax_issue' ] );
    add_action( 'wp_ajax_webbership_smartship_cities', [ $this, 'ajax_cities' ] );
    add_action( 'wp_ajax_webbership_smartship_status', [ $this, 'ajax_status' ] );
    add_action( 'wp_ajax_webbership_smartship_cancel', [ $this, 'ajax_cancel' ] );
  }

  public function add_box(): void {
    foreach ( [ 'shop_order', 'woocommerce_page_wc-orders' ] as $screen ) {
      add_meta_box( 'webbership-smartship-awb', __( 'SmartShip AWB', 'webbership-smartship' ), [ $this, 'render' ], $screen, 'side', 'default' );
    }
  }

  public function enqueue( string $hook ): void {
    if ( ! in_array( $hook, [ 'post.php', 'woocommerce_page_wc-orders' ], true ) ) {
      return;
    }
    wp_enqueue_script( 'webbership-smartship-awb', WEBBERSHIP_SMARTSHIP_URL . 'assets/js/awb-metabox.js', [ 'jquery' ], WEBBERSHIP_SMARTSHIP_VERSION, true );
    wp_localize_script( 'webbership-smartship-awb', 'WebbershipSmartShip', [
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
    if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( [ 'message' => __( 'Forbidden.', 'webbership-smartship' ) ], 403 ); }
    $order = $this->order_from_request();
    if ( ! $order ) { wp_send_json_error( [ 'message' => __( 'Order not found.', 'webbership-smartship' ) ], 404 ); }

    $client   = new SmartShipClient( Settings::api_key() );
    $resolved = $this->resolve_for( $order );
    // No city id (resolver not confident, no override posted) → /cost with city:0
    // would just fail. Return needs_city so the JS renders the city picker instead.
    if ( empty( $resolved['city_id'] ) ) {
      wp_send_json_success( [ 'costs' => [], 'resolved' => $resolved, 'needs_city' => true ] );
    }
    $sender   = $this->chosen_sender( $client );
    $payload  = [
      'recipient' => AwbPayload::recipient_from_order( $order, $resolved ),
      'sender'    => AwbPayload::sender_from_account( $sender ),
      'content'   => AwbPayload::content_from_order( $order ),
    ];
    $res = $client->cost( $payload );
    if ( empty( $res['ok'] ) ) { wp_send_json_error( [ 'message' => $res['message'] ?: __( 'Estimate failed.', 'webbership-smartship' ), 'errors' => $res['errors'] ?? [] ] ); }
    wp_send_json_success( [ 'costs' => $res['costs'] ?? ( $res['response']['costs'] ?? [] ), 'resolved' => $resolved ] );
  }

  public function ajax_issue(): void {
    check_ajax_referer( self::NONCE );
    if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( [ 'message' => __( 'Forbidden.', 'webbership-smartship' ) ], 403 ); }
    $order = $this->order_from_request();
    if ( ! $order ) { wp_send_json_error( [ 'message' => __( 'Order not found.', 'webbership-smartship' ) ], 404 ); }
    $courier_id = isset( $_POST['courier_id'] ) ? absint( $_POST['courier_id'] ) : 0;
    if ( ! $courier_id ) { wp_send_json_error( [ 'message' => __( 'Choose a courier.', 'webbership-smartship' ) ] ); }

    $resolved = $this->resolve_for( $order );
    if ( empty( $resolved['city_id'] ) ) { wp_send_json_error( [ 'message' => __( 'Resolve the destination city first.', 'webbership-smartship' ) ] ); }

    $client  = new SmartShipClient( Settings::api_key() );
    $sender  = $this->chosen_sender( $client );
    $payload = AwbPayload::build( $order, $resolved, $sender, $courier_id );
    $res     = $client->create_awb( $payload );
    if ( empty( $res['ok'] ) ) {
      wp_send_json_error( [ 'message' => $res['message'] ?: __( 'AWB issue failed.', 'webbership-smartship' ), 'errors' => $res['errors'] ?? [], 'code' => $res['code'] ?? '' ] );
    }
    $awb          = sanitize_text_field( (string) ( $res['awb'] ?? '' ) );
    $courier_name = sanitize_text_field( (string) ( $res['courier_name'] ?? '' ) );
    $order->update_meta_data( '_webbership_smartship_awb', $awb );
    $order->update_meta_data( '_webbership_smartship_courier', $courier_name );
    $order->update_meta_data( '_webbership_smartship_cost', sanitize_text_field( (string) ( $res['cost'] ?? '' ) ) );
    $order->add_order_note( sprintf( /* translators: 1: AWB number, 2: courier */ __( 'SmartShip AWB %1$s issued (%2$s).', 'webbership-smartship' ), $awb, $courier_name ) );
    $order->save();
    wp_send_json_success( [ 'awb' => $awb ] );
  }

  /** Cities for a resolved county, so the merchant can pick the right one. */
  public function ajax_cities(): void {
    check_ajax_referer( self::NONCE );
    if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( [ 'message' => __( 'Forbidden.', 'webbership-smartship' ) ], 403 ); }
    $county_id = absint( $_POST['county_id'] ?? 0 );
    $res       = ( new SmartShipClient( Settings::api_key() ) )->get_cities( $county_id );
    if ( empty( $res['ok'] ) ) { wp_send_json_error( [ 'message' => $res['message'] ?: __( 'Could not load cities.', 'webbership-smartship' ) ] ); }
    wp_send_json_success( [ 'cities' => array_map(
      fn( $c ) => [ 'id' => (int) ( $c['id'] ?? 0 ), 'city' => sanitize_text_field( (string) ( $c['city'] ?? '' ) ) ],
      (array) ( $res['cities'] ?? [] )
    ) ] );
  }

  public function ajax_status(): void {
    check_ajax_referer( self::NONCE );
    if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( [ 'message' => __( 'Forbidden.', 'webbership-smartship' ) ], 403 ); }
    $order = $this->order_from_request();
    if ( ! $order ) { wp_send_json_error( [ 'message' => __( 'Order not found.', 'webbership-smartship' ) ], 404 ); }
    $awb = (string) $order->get_meta( '_webbership_smartship_awb' );
    if ( '' === $awb ) { wp_send_json_error( [ 'message' => __( 'No AWB on this order.', 'webbership-smartship' ) ], 400 ); }
    $res = ( new SmartShipClient( Settings::api_key() ) )->get_awb_status( $awb );
    if ( empty( $res['ok'] ) ) { wp_send_json_error( [ 'message' => $res['message'] ?: __( 'Status unavailable.', 'webbership-smartship' ) ] ); }
    wp_send_json_success( $res );
  }

  public function ajax_cancel(): void {
    check_ajax_referer( self::NONCE );
    if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( [ 'message' => __( 'Forbidden.', 'webbership-smartship' ) ], 403 ); }
    $order = $this->order_from_request();
    if ( ! $order ) { wp_send_json_error( [ 'message' => __( 'Order not found.', 'webbership-smartship' ) ], 404 ); }
    $awb = (string) $order->get_meta( '_webbership_smartship_awb' );
    if ( '' === $awb ) { wp_send_json_error( [ 'message' => __( 'No AWB on this order.', 'webbership-smartship' ) ], 400 ); }
    // SmartShip's /awb/cancel doesn't actually cancel the shipment, so this is
    // best-effort: ignore its result and always clear the AWB locally.
    ( new SmartShipClient( Settings::api_key() ) )->cancel_awb( $awb );
    $order->delete_meta_data( '_webbership_smartship_awb' );
    $order->delete_meta_data( '_webbership_smartship_courier' );
    $order->delete_meta_data( '_webbership_smartship_cost' );
    $order->add_order_note( sprintf( /* translators: %s: AWB number */ __( 'SmartShip AWB %s removed from this order. If it was already sent to the courier, cancel it in the SmartShip dashboard too.', 'webbership-smartship' ), $awb ) );
    $order->save();
    wp_send_json_success( [ 'message' => __( 'AWB removed. Cancel it in the SmartShip dashboard if it was already submitted.', 'webbership-smartship' ) ] );
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
    $awb = $order->get_meta( '_webbership_smartship_awb' );
    echo '<div class="webbership-ss-awb" data-order="' . esc_attr( (string) $order->get_id() ) . '">';
    if ( $awb ) {
      $courier = (string) $order->get_meta( '_webbership_smartship_courier' );
      echo '<p><strong>' . esc_html__( 'AWB:', 'webbership-smartship' ) . '</strong> ' . esc_html( (string) $awb );
      if ( '' !== $courier ) { echo ' (' . esc_html( $courier ) . ')'; }
      echo '</p>';
      echo '<p><a class="button" target="_blank" href="' . esc_url( AwbPrint::url( (int) $order->get_id(), 'A4' ) ) . '">' . esc_html__( 'Print A4', 'webbership-smartship' ) . '</a> ';
      echo '<a class="button" target="_blank" href="' . esc_url( AwbPrint::url( (int) $order->get_id(), 'A6' ) ) . '">' . esc_html__( 'Print A6', 'webbership-smartship' ) . '</a></p>';
      echo '<p><button type="button" class="button webbership-ss-track">' . esc_html__( 'Refresh tracking', 'webbership-smartship' ) . '</button> ';
      echo '<button type="button" class="button webbership-ss-cancel">' . esc_html__( 'Cancel AWB', 'webbership-smartship' ) . '</button></p>';
      echo '<p class="description">' . esc_html__( 'Cancel removes the AWB from this order. If it was already handed to the courier, cancel it in the SmartShip dashboard too.', 'webbership-smartship' ) . '</p>';
      echo '<div class="webbership-ss-tracking"></div>';
    } else {
      echo '<p class="description">' . esc_html__( 'Estimate quotes couriers for the delivery address on this order (no charge). Pick one, then Issue AWB to create the shipment with SmartShip.', 'webbership-smartship' ) . '</p>';
      echo '<button type="button" class="button webbership-ss-estimate">' . esc_html__( 'Estimate', 'webbership-smartship' ) . '</button>';
      echo '<div class="webbership-ss-couriers"></div>';
      echo '<div class="webbership-ss-msg"></div>';
    }
    echo '</div>';
  }
}
