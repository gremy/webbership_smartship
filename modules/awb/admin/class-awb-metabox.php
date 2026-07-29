<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\Awb\Admin;

defined( 'ABSPATH' ) || exit;

use Webbership\Smartship\Api\SmartShipClient;
use Webbership\Smartship\Support\CityResolver;
use Webbership\Smartship\Settings\Settings;
use Webbership\Smartship\Modules\Awb\Data\AwbPayload;
use Webbership\Smartship\Modules\Awb\Admin\AwbPrint;
use Webbership\Smartship\Modules\Fulfillment\FulfillmentModule;

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
    add_action( 'wp_ajax_webbership_smartship_set_awb', [ $this, 'ajax_set_awb' ] );
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
    // post.php is shared by every post type; only load the metabox JS on an order edit.
    if ( 'post.php' === $hook && 'shop_order' !== get_post_type( absint( $_GET['post'] ?? 0 ) ) ) {
      return;
    }
    wp_enqueue_script( 'webbership-smartship-awb', WEBBERSHIP_SMARTSHIP_URL . 'assets/js/awb-metabox.js', [ 'jquery' ], WEBBERSHIP_SMARTSHIP_VERSION, true );
    wp_localize_script( 'webbership-smartship-awb', 'WebbershipSmartShip', [
      'ajax'  => admin_url( 'admin-ajax.php' ),
      'nonce' => wp_create_nonce( self::NONCE ),
      'i18n'  => [
        'enterAwb'          => __( 'Enter the AWB number.', 'webbership-smartship' ),
        'saving'            => __( 'Saving…', 'webbership-smartship' ),
        'failed'            => __( 'Failed', 'webbership-smartship' ),
        'estimating'        => __( 'Estimating…', 'webbership-smartship' ),
        'pickCityReest'     => __( 'Pick the destination city, then Re-estimate.', 'webbership-smartship' ),
        'issueAwb'          => __( 'Issue AWB', 'webbership-smartship' ),
        'cantMatchCity'     => __( "Couldn't match the city — pick it:", 'webbership-smartship' ),
        'selectCity'        => __( '— Select city —', 'webbership-smartship' ),
        'reestimate'        => __( 'Re-estimate', 'webbership-smartship' ),
        'cityChanged'       => __( 'City changed — click Re-estimate.', 'webbership-smartship' ),
        'pickCity'          => __( 'Pick a city.', 'webbership-smartship' ),
        'pickCourier'       => __( 'Pick a courier.', 'webbership-smartship' ),
        'selectCityFirst'   => __( 'Select the destination city first.', 'webbership-smartship' ),
        'issuing'           => __( 'Issuing…', 'webbership-smartship' ),
        'cancelConfirm'     => __( 'Cancel this AWB?', 'webbership-smartship' ),
        'loading'           => __( 'Loading…', 'webbership-smartship' ),
        'sender'            => __( 'Sender:', 'webbership-smartship' ),
        'pickSector'        => __( 'Pick the Bucharest sector, then Re-estimate.', 'webbership-smartship' ),
        'selectSector'      => __( '— Select sector —', 'webbership-smartship' ),
        'selectSectorFirst' => __( 'Select the Bucharest sector first.', 'webbership-smartship' ),
        'noCounty'          => __( "Couldn't determine the destination county for this order.", 'webbership-smartship' ),
        'requestFailed'     => __( 'Request failed — reload the page and try again.', 'webbership-smartship' ),
        'packageChanged'    => __( 'Package changed — click Estimate again.', 'webbership-smartship' ),
        'enterCityPostcode' => __( 'Enter the destination city and postal code first.', 'webbership-smartship' ),
      ],
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
    $senders  = (array) ( $client->get_senders()['senders'] ?? [] );
    $sender   = self::pick_sender( $senders, absint( $_POST['sender_id'] ?? 0 ) );

    // International: no CityResolver/city picker — gated on city text + postal code
    // (posted from the metabox's plain text inputs) instead of a resolved city id.
    if ( ! AwbPayload::is_domestic( (string) ( $resolved['country'] ?? 'RO' ) ) ) {
      if ( ! AwbPayload::international_ready( $resolved ) ) {
        wp_send_json_error( [ 'message' => __( 'Enter the destination city and postal code first.', 'webbership-smartship' ) ] );
      }
      $payload = [
        'recipient' => AwbPayload::recipient_from_order( $order, $resolved ),
        'sender'    => AwbPayload::sender_from_account( $sender ),
        'content'   => AwbPayload::content_from_order( $order, $this->posted_package() ),
      ];
      $res = $client->cost( $payload );
      if ( empty( $res['ok'] ) ) { wp_send_json_error( [ 'message' => $res['message'] ?: __( 'Estimate failed.', 'webbership-smartship' ), 'errors' => $res['errors'] ?? [] ] ); }
      wp_send_json_success( [ 'costs' => $res['costs'] ?? ( $res['response']['costs'] ?? [] ), 'resolved' => $resolved, 'senders' => self::senders_for_js( $senders ), 'sender_id' => (int) ( $sender['id'] ?? 0 ) ] );
    }

    // No city id (resolver not confident, no override posted) → /cost with city:0
    // would just fail. Return needs_city so the JS renders the city picker instead.
    if ( empty( $resolved['city_id'] ) ) {
      wp_send_json_success( [ 'costs' => [], 'resolved' => $resolved, 'needs_city' => true, 'senders' => self::senders_for_js( $senders ), 'sender_id' => (int) ( $sender['id'] ?? 0 ) ] );
    }
    // City resolved but not confident only happens for Bucharest with an unparseable
    // sector (CityResolver guarantees confident=true for every other city_id-set case)
    // — the city picker would be useless here (Bucuresti is the only option), so ask
    // for the sector instead of forcing needs_city.
    if ( empty( $resolved['confident'] ) ) {
      wp_send_json_success( [ 'costs' => [], 'resolved' => $resolved, 'needs_sector' => true, 'senders' => self::senders_for_js( $senders ), 'sender_id' => (int) ( $sender['id'] ?? 0 ) ] );
    }
    $payload  = [
      'recipient' => AwbPayload::recipient_from_order( $order, $resolved ),
      'sender'    => AwbPayload::sender_from_account( $sender ),
      'content'   => AwbPayload::content_from_order( $order, $this->posted_package() ),
    ];
    $res = $client->cost( $payload );
    if ( empty( $res['ok'] ) ) { wp_send_json_error( [ 'message' => $res['message'] ?: __( 'Estimate failed.', 'webbership-smartship' ), 'errors' => $res['errors'] ?? [] ] ); }
    wp_send_json_success( [ 'costs' => $res['costs'] ?? ( $res['response']['costs'] ?? [] ), 'resolved' => $resolved, 'senders' => self::senders_for_js( $senders ), 'sender_id' => (int) ( $sender['id'] ?? 0 ) ] );
  }

  public function ajax_issue(): void {
    check_ajax_referer( self::NONCE );
    if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( [ 'message' => __( 'Forbidden.', 'webbership-smartship' ) ], 403 ); }
    $order = $this->order_from_request();
    if ( ! $order ) { wp_send_json_error( [ 'message' => __( 'Order not found.', 'webbership-smartship' ) ], 404 ); }
    // Guard against a double-click (or a retried request) re-issuing a second,
    // billable shipment for an order that already has an AWB.
    if ( '' !== (string) $order->get_meta( '_webbership_smartship_awb' ) ) {
      wp_send_json_error( [ 'message' => __( 'This order already has an AWB. Cancel it first to issue a new one.', 'webbership-smartship' ) ] );
    }
    // EasyBox orders always ship on courier 12 (SameDay EasyBox) — force it so a
    // stale/tampered posted courier_id can never pick a different courier. The
    // locker id itself rides along via AwbPayload::content_from_order()'s order-meta read.
    if ( '' !== (string) $order->get_meta( '_webbership_smartship_easybox_id' ) ) {
      $courier_id = 12;
    } else {
      $courier_id = isset( $_POST['courier_id'] ) ? absint( $_POST['courier_id'] ) : 0;
      if ( ! $courier_id ) { wp_send_json_error( [ 'message' => __( 'Choose a courier.', 'webbership-smartship' ) ] ); }
    }

    $resolved = $this->resolve_for( $order );
    if ( AwbPayload::is_domestic( (string) ( $resolved['country'] ?? 'RO' ) ) ) {
      // Not confident also catches the Bucharest-sector-unknown case (city_id set,
      // sector missing) — issuing then would send sector '0' and SmartShip rejects it.
      if ( empty( $resolved['city_id'] ) || empty( $resolved['confident'] ) ) { wp_send_json_error( [ 'message' => __( 'Resolve the destination city first.', 'webbership-smartship' ) ] ); }
    } elseif ( ! AwbPayload::international_ready( $resolved ) ) {
      wp_send_json_error( [ 'message' => __( 'Enter the destination city and postal code first.', 'webbership-smartship' ) ] );
    }

    $client  = new SmartShipClient( Settings::api_key() );
    $sender  = self::pick_sender( (array) ( $client->get_senders()['senders'] ?? [] ), absint( $_POST['sender_id'] ?? 0 ) );
    $payload = AwbPayload::build( $order, $resolved, $sender, $courier_id, $this->posted_package() );
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

  /**
   * Paste-back: store an AWB the merchant created by hand in smartship.ro —
   * either the EasyBox locker hand-off (SmartShip's V2 API can't issue locker
   * AWBs yet) or, for any other order, one created directly in the SmartShip
   * dashboard instead of through Estimate → Issue here. Mutates the order, so
   * it requires the metabox nonce + edit capability.
   */
  public function ajax_set_awb(): void {
    check_ajax_referer( self::NONCE );
    if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( [ 'message' => __( 'Forbidden.', 'webbership-smartship' ) ], 403 ); }
    $order = $this->order_from_request();
    if ( ! $order ) { wp_send_json_error( [ 'message' => __( 'Order not found.', 'webbership-smartship' ) ], 404 ); }
    $awb = sanitize_text_field( (string) ( $_POST['awb'] ?? '' ) );
    if ( '' === $awb ) { wp_send_json_error( [ 'message' => __( 'Enter the AWB number.', 'webbership-smartship' ) ] ); }

    if ( '' !== (string) $order->get_meta( '_webbership_smartship_easybox_id' ) ) {
      // Meta is stored data, not UI text — keep it locale-independent so it doesn't
      // change with the admin's language and stays consistent across orders.
      $courier = 'SameDay EasyBox';
      $note    = sprintf( /* translators: %s: AWB number */ __( 'SmartShip EasyBox AWB %s added manually.', 'webbership-smartship' ), $awb );
    } else {
      // Every other order: verify with SmartShip before accepting the paste —
      // an unrecognized AWB would silently break printing/tracking later.
      $status = ( new FulfillmentModule() )->verified_awb_status( $awb );
      if ( null === $status ) {
        wp_send_json_error( [ 'message' => __( "SmartShip doesn't recognize this AWB number.", 'webbership-smartship' ) ] );
      }
      $courier = sanitize_text_field( FulfillmentModule::courier_from_status( $status ) );
      $note    = sprintf( /* translators: %s: AWB number */ __( 'SmartShip AWB %s added manually.', 'webbership-smartship' ), $awb );
    }

    $order->update_meta_data( '_webbership_smartship_awb', $awb );
    $order->update_meta_data( '_webbership_smartship_courier', $courier );
    $order->add_order_note( $note );
    $order->save();
    wp_send_json_success( [ 'awb' => $awb ] );
  }

  /**
   * county/city: posted dropdown values win (both required); else the resolver.
   * A posted sector (validated) always wins over whatever was parsed, and — paired
   * with a resolved city_id — makes the resolution confident even if the parser
   * couldn't find "Sector N" in the address text.
   */
  private function resolve_for( $order ): array {
    $country = AwbPayload::order_country( $order );
    if ( ! AwbPayload::is_domestic( $country ) ) {
      // No CityResolver for non-RO — its geolocation endpoints are RO-only. The
      // metabox renders plain city/postal-code text inputs for these orders instead
      // of the county/city picker; a posted edit wins over the order's own address.
      $city_text = isset( $_POST['city_text'] ) ? sanitize_text_field( (string) $_POST['city_text'] ) : (string) ( $order->get_shipping_city() ?: $order->get_billing_city() );
      $postcode  = isset( $_POST['postcode'] ) ? sanitize_text_field( (string) $_POST['postcode'] ) : (string) ( $order->get_shipping_postcode() ?: $order->get_billing_postcode() );
      return [ 'country' => $country, 'confident' => true, 'city_id' => 0, 'sector' => '0', 'city_text' => $city_text, 'postcode' => $postcode ];
    }

    $county = isset( $_POST['county_id'] ) ? absint( $_POST['county_id'] ) : 0;
    $city   = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;
    $sector = self::posted_sector();

    if ( $county && $city ) {
      $is_buc = 'B' === strtoupper( trim( (string) $order->get_shipping_state() ) );
      $parsed   = $is_buc ? CityResolver::sector_from( ( $order->get_shipping_city() ?: $order->get_billing_city() ) . ' ' . self::address_lines( $order ) ) : '';
      $resolved = [ 'county_id' => $county, 'city_id' => $city, 'confident' => true, 'sector' => ( '' !== $parsed ? $parsed : '0' ) ];
    } else {
      $resolver = new CityResolver( new SmartShipClient( Settings::api_key() ) );
      $resolved = $resolver->resolve( (string) $order->get_shipping_state(), (string) ( $order->get_shipping_city() ?: $order->get_billing_city() ), self::address_lines( $order ) );
    }

    if ( null !== $sector ) {
      $resolved['sector'] = $sector;
      if ( ! empty( $resolved['city_id'] ) ) {
        $resolved['confident'] = true;
      }
    }
    $resolved['country'] = $country;
    return $resolved;
  }

  /** Posted Bucharest sector, validated to a single '1'-'6' char; null if absent/invalid. */
  private static function posted_sector(): ?string {
    $raw = isset( $_POST['sector'] ) ? (string) $_POST['sector'] : '';
    return ( 1 === strlen( $raw ) && in_array( $raw, [ '1', '2', '3', '4', '5', '6' ], true ) ) ? $raw : null;
  }

  /**
   * Merchant-posted package overrides from the Package fieldset, range-validated —
   * only valid keys are returned, so AwbPayload::content_from_order()'s isset()
   * checks fall back to the computed weight / the historical 10x10x10 box for
   * anything absent or out of range.
   */
  private function posted_package(): array {
    $package = [];
    if ( isset( $_POST['weight'] ) && '' !== $_POST['weight'] ) {
      $weight = (float) $_POST['weight'];
      if ( $weight >= 0.05 && $weight <= 100 ) {
        $package['weight'] = $weight;
      }
    }
    foreach ( [ 'length', 'width', 'height' ] as $dim ) {
      if ( isset( $_POST[ $dim ] ) && '' !== $_POST[ $dim ] ) {
        $value = (int) $_POST[ $dim ];
        if ( $value >= 1 && $value <= 250 ) {
          $package[ $dim ] = $value;
        }
      }
    }
    return $package;
  }

  /**
   * Street address lines used to source a Bucharest sector, from the SAME source
   * (shipping vs billing) the recipient payload uses — a customer who didn't tick
   * "ship to a different address" has the sector sitting in the billing lines.
   */
  private static function address_lines( $order ): string {
    $use_shipping = (string) $order->get_shipping_address_1() !== '';
    $a1 = $use_shipping ? $order->get_shipping_address_1() : $order->get_billing_address_1();
    $a2 = $use_shipping ? $order->get_shipping_address_2() : $order->get_billing_address_2();
    return trim( $a1 . ' ' . $a2 );
  }

  /**
   * The sender (pickup point) for this shipment: the per-order choice posted from
   * the metabox wins; else the settings default; else the account's first sender.
   */
  public static function pick_sender( array $senders, int $requested ): array {
    foreach ( [ $requested, Settings::sender_id() ] as $want ) {
      foreach ( $want ? $senders : [] as $s ) {
        if ( (int) ( $s['id'] ?? 0 ) === $want ) { return (array) $s; }
      }
    }
    return (array) ( $senders[0] ?? [] );
  }

  /** id + label only — all the metabox dropdown needs. */
  private static function senders_for_js( array $senders ): array {
    return array_values( array_map( static fn( $s ) => [
      'id'    => (int) ( $s['id'] ?? 0 ),
      'label' => sanitize_text_field( trim( ( $s['nume'] ?? '' ) . ' — ' . ( $s['localitate'] ?? '' ), ' —' ) ),
    ], $senders ) );
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
    } elseif ( '' !== (string) $order->get_meta( '_webbership_smartship_easybox_id' ) ) {
      $this->render_easybox_handoff( $order );
    } else {
      echo '<p class="description">' . esc_html__( 'Estimate quotes couriers for the delivery address on this order (no charge). Pick one, then Issue AWB to create the shipment with SmartShip.', 'webbership-smartship' ) . '</p>';
      if ( ! AwbPayload::is_domestic( AwbPayload::order_country( $order ) ) ) {
        $this->render_international_fields( $order );
      }
      $this->render_package_fields( $order );
      echo '<button type="button" class="button webbership-ss-estimate">' . esc_html__( 'Estimate', 'webbership-smartship' ) . '</button>';
      echo '<div class="webbership-ss-sender"></div>';
      echo '<div class="webbership-ss-couriers"></div>';
      echo '<div class="webbership-ss-msg"></div>';
      $this->render_paste_back();
    }
    echo '</div>';
  }

  /**
   * International destination: no county/city picker (SmartShip's geolocation
   * lookup is RO-only) — plain, prefilled, merchant-editable text inputs instead.
   * City is free text; the postal code is required by SmartShip for non-RO recipients.
   */
  private function render_international_fields( $order ): void {
    $city     = (string) ( $order->get_shipping_city() ?: $order->get_billing_city() );
    $postcode = (string) ( $order->get_shipping_postcode() ?: $order->get_billing_postcode() );
    echo '<fieldset class="webbership-ss-international">';
    echo '<p><label>' . esc_html__( 'City', 'webbership-smartship' ) . ' ';
    echo '<input type="text" class="webbership-ss-city-text" value="' . esc_attr( $city ) . '" /></label></p>';
    echo '<p><label>' . esc_html__( 'Postal code', 'webbership-smartship' ) . ' ';
    echo '<input type="text" class="webbership-ss-postcode" value="' . esc_attr( $postcode ) . '" /></label></p>';
    echo '</fieldset>';
  }

  /**
   * Paste-back for orders that don't need the EasyBox hand-off: the merchant
   * may have created the AWB directly in the SmartShip dashboard instead of
   * through Estimate → Issue here. Reuses the EasyBox hand-off's input/button
   * classes so the existing delegated JS handler (.webbership-ss-easybox-save
   * in awb-metabox.js) picks it up unchanged — it isn't scoped to that branch.
   */
  private function render_paste_back(): void {
    echo '<p class="description">' . esc_html__( 'Created the AWB in SmartShip directly? Paste it here to enable printing and tracking.', 'webbership-smartship' ) . '</p>';
    echo '<p><input type="text" class="webbership-ss-easybox-awb" placeholder="' . esc_attr__( 'Paste AWB number', 'webbership-smartship' ) . '" /> ';
    echo '<button type="button" class="button webbership-ss-easybox-save">' . esc_html__( 'Save AWB', 'webbership-smartship' ) . '</button></p>';
  }

  /**
   * Merchant-controlled weight/dims, prefilled from the order and (if any box
   * presets are configured) a preset picker. The fields stay editable after a
   * preset is chosen — the preset is a starting point, not a mode.
   */
  private function render_package_fields( $order ): void {
    $weight = AwbPayload::order_weight_kg( $order );
    $boxes  = Settings::boxes();
    echo '<fieldset class="webbership-ss-package">';
    echo '<p><strong>' . esc_html__( 'Package', 'webbership-smartship' ) . '</strong></p>';
    if ( $boxes ) {
      echo '<p><label>' . esc_html__( 'Box', 'webbership-smartship' ) . ' ';
      echo '<select class="webbership-ss-box-preset">';
      echo '<option value="">' . esc_html__( '— Box —', 'webbership-smartship' ) . '</option>';
      foreach ( $boxes as $i => $box ) {
        $label = sprintf( '%1$s — %2$d×%3$d×%4$d', $box['name'], $box['length'], $box['width'], $box['height'] );
        echo '<option value="' . esc_attr( (string) $i ) . '"'
          . ' data-l="' . esc_attr( (string) $box['length'] ) . '"'
          . ' data-w="' . esc_attr( (string) $box['width'] ) . '"'
          . ' data-h="' . esc_attr( (string) $box['height'] ) . '"'
          . ' data-kg="' . esc_attr( (string) $box['weight'] ) . '"'
          . '>' . esc_html( $label ) . '</option>';
      }
      echo '</select></label></p>';
    }
    echo '<p><label>' . esc_html__( 'Weight (kg)', 'webbership-smartship' ) . ' ';
    echo '<input type="number" step="0.01" min="0.1" class="webbership-ss-weight" data-base="' . esc_attr( (string) $weight ) . '" value="' . esc_attr( (string) $weight ) . '" /></label></p>';
    echo '<p><label>' . esc_html__( 'Dimensions (cm, L×W×H)', 'webbership-smartship' ) . ' ';
    echo '<input type="number" step="1" min="1" class="webbership-ss-length" value="10" /> × ';
    echo '<input type="number" step="1" min="1" class="webbership-ss-width" value="10" /> × ';
    echo '<input type="number" step="1" min="1" class="webbership-ss-height" value="10" /></label></p>';
    echo '</fieldset>';
  }

  /**
   * EasyBox order, no AWB yet: SmartShip's `/awb/new` now supports locker AWBs
   * (courier 12 + content.locker_id — see AwbPayload::content_from_order()), so
   * the courier is fixed (no picker needed) and Issue AWB creates the shipment
   * directly. The hidden radio just satisfies the existing `.webbership-ss-issue`
   * click handler's "a courier is chosen" check; ajax_issue() forces courier_id to
   * 12 server-side regardless of what's posted. The manual paste-back stays as a
   * backup for a locker AWB created by hand in the SmartShip dashboard.
   */
  private function render_easybox_handoff( $order ): void {
    $locker_name = (string) $order->get_meta( '_webbership_smartship_easybox_name' );

    echo '<p><strong>' . esc_html__( 'EasyBox locker', 'webbership-smartship' ) . '</strong></p>';
    echo '<p>' . esc_html( $locker_name ) . '<br/>';
    echo esc_html( (string) $order->get_meta( '_webbership_smartship_easybox_address' ) ) . '<br/>';
    echo esc_html( (string) $order->get_meta( '_webbership_smartship_easybox_city' ) ) . '</p>';

    echo '<p>' . sprintf(
      /* translators: %s: the chosen locker's name */
      esc_html__( 'Courier: SameDay EasyBox — %s', 'webbership-smartship' ),
      esc_html( $locker_name )
    ) . '</p>';
    echo '<input type="radio" name="ss_courier" value="12" checked hidden />';

    // ajax_issue() refuses ("Resolve the destination city first") whenever the
    // recipient address doesn't resolve confidently — same gate as the normal
    // (non-easybox) flow. That flow gets its city/sector picker from a JS
    // response to ajax_estimate(); this flow has no Estimate step (the courier
    // is already fixed), so embed the resolved address here and let the SAME
    // picker JS (maybeRenderCityPicker()/maybeRenderSectorPicker() in
    // awb-metabox.js) render from it on page load instead of from an AJAX
    // response — no new markup, no new endpoint. get_counties()/get_cities()
    // are transient-cached for 12h (SmartShipClient), so this costs nothing on
    // repeat page loads.
    $resolved = $this->resolve_for( $order );
    if ( empty( $resolved['confident'] ) ) {
      echo '<div class="webbership-ss-easybox-resolved" data-resolved="' . esc_attr( wp_json_encode( $resolved ) ) . '"></div>';
    }

    $this->render_package_fields( $order );
    echo '<button type="button" class="button button-primary webbership-ss-issue">' . esc_html__( 'Issue AWB', 'webbership-smartship' ) . '</button>';
    echo '<div class="webbership-ss-msg"></div>';

    $this->render_paste_back();
  }
}
