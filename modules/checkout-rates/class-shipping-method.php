<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\CheckoutRates;

use Webbership\Smartship\Api\SmartShipClient;
use Webbership\Smartship\Support\CityResolver;
use Webbership\Smartship\Settings\Settings;
use Webbership\Smartship\Modules\Awb\Data\AwbPayload;

defined( 'ABSPATH' ) || exit;

/**
 * Live SmartShip checkout rates (instance-based, per zone).
 *
 * @package Webbership\Smartship\Modules\CheckoutRates
 */
final class ShippingMethod extends \WC_Shipping_Method {

  /** Known SmartShip courier ids -> default names (see docs/reference/smartship-api.md). */
  private const COURIERS = [
    1 => 'Cargus', 2 => 'SameDay', 3 => 'FanCourier', 5 => 'DragonStar',
    6 => 'DPD', 14 => 'PTT Express', 16 => 'SmartShip Delivery',
  ];

  public function __construct( $instance_id = 0 ) {
    $this->id                 = 'webbership_smartship';
    $this->instance_id        = absint( $instance_id );
    $this->method_title       = __( 'SmartShip Live Rates', 'webbership-smartship' );
    $this->method_description = __( 'Live courier rates from SmartShip, with a fallback flat rate.', 'webbership-smartship' );
    $this->supports           = [ 'shipping-zones', 'instance-settings', 'instance-settings-modal' ];
    $this->init();
  }

  public function init(): void {
    $this->init_form_fields();
    $this->init_settings();
    $this->title   = $this->get_option( 'title', $this->method_title );
    $this->enabled = $this->get_option( 'enabled', 'yes' );
    add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
  }

  public function init_form_fields(): void {
    $courier_options = [];
    foreach ( self::COURIERS as $cid => $name ) {
      $courier_options[ $cid ] = $name . ' (' . $cid . ')';
    }
    $this->instance_form_fields = [
      'title' => [
        'title'       => __( 'Method title', 'webbership-smartship' ),
        'type'        => 'text',
        'default'     => __( 'SmartShip', 'webbership-smartship' ),
        'description' => __( 'Heading shown above the courier choices at checkout.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'couriers' => [
        'title'       => __( 'Couriers to offer', 'webbership-smartship' ),
        'type'        => 'multiselect',
        'class'       => 'wc-enhanced-select',
        'options'     => $courier_options,
        'default'     => [],
        'description' => __( 'Pick which couriers customers may choose. Leave empty to offer every courier SmartShip returns for the destination.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'labels' => [
        'title'       => __( 'Courier label overrides', 'webbership-smartship' ),
        'type'        => 'textarea',
        'default'     => '',
        'description' => __( 'Rename couriers at checkout. One per line as courier_id|Custom label (e.g. 16|Curier rapid). The IDs are shown in brackets in the "Couriers to offer" list above.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'markup_type' => [
        'title'   => __( 'Markup', 'webbership-smartship' ),
        'type'    => 'select',
        'default' => 'none',
        'options' => [ 'none' => __( 'None', 'webbership-smartship' ), 'flat' => __( 'Flat amount', 'webbership-smartship' ), 'percent' => __( 'Percent', 'webbership-smartship' ) ],
        'description' => __( 'Optionally add a handling fee on top of each live rate.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'markup_amount' => [
        'title'   => __( 'Markup amount', 'webbership-smartship' ),
        'type'    => 'text',
        'default' => '0',
        'description' => __( 'With "Flat amount", the fee added in your store currency. With "Percent", the percentage added to each rate.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'fallback_amount' => [
        'title'       => __( 'Fallback flat rate', 'webbership-smartship' ),
        'type'        => 'text',
        'default'     => '0',
        'description' => __( 'Flat price charged when live rates cannot be fetched (SmartShip slow or down, or the address is outside Romania).', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
      'fallback_title' => [
        'title'   => __( 'Fallback label', 'webbership-smartship' ),
        'type'    => 'text',
        'default' => __( 'Shipping', 'webbership-smartship' ),
        'description' => __( 'Label shown to the customer for the fallback flat rate.', 'webbership-smartship' ),
        'desc_tip'    => true,
      ],
    ];
  }

  /** Read + sanitize the instance settings into the RateCalculator config shape. */
  public function config(): array {
    $labels = [];
    foreach ( preg_split( '/\r\n|\r|\n/', (string) $this->get_option( 'labels', '' ) ) as $line ) {
      $parts = explode( '|', $line, 2 );
      if ( count( $parts ) === 2 && absint( $parts[0] ) > 0 ) {
        $labels[ absint( $parts[0] ) ] = sanitize_text_field( trim( $parts[1] ) );
      }
    }
    $known = array_keys( self::COURIERS );
    return [
      'couriers'        => array_values( array_intersect( array_map( 'absint', (array) $this->get_option( 'couriers', [] ) ), $known ) ),
      'labels'          => $labels,
      'markup_type'     => in_array( $this->get_option( 'markup_type', 'none' ), [ 'none', 'flat', 'percent' ], true ) ? $this->get_option( 'markup_type', 'none' ) : 'none',
      'markup_amount'   => max( 0.0, (float) $this->get_option( 'markup_amount', 0 ) ),
      'fallback_amount' => max( 0.0, (float) $this->get_option( 'fallback_amount', 0 ) ),
      'fallback_title'  => sanitize_text_field( (string) $this->get_option( 'fallback_title', __( 'Shipping', 'webbership-smartship' ) ) ),
    ];
  }

  public function calculate_shipping( $package = [] ): void {
    $config = $this->config();

    // Headless/cron (e.g. a subscription renewal) -> fallback, never call the API.
    if ( wp_doing_cron() || ! function_exists( 'WC' ) || null === WC()->session ) {
      $this->add_fallback( $config );
      return;
    }

    $dest = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : [];
    if ( 'RO' !== ( $dest['country'] ?? '' ) ) {
      $this->add_fallback( $config );
      return;
    }

    $api_key = Settings::api_key();
    if ( '' === $api_key ) {
      $this->add_fallback( $config );
      return;
    }
    $client   = new SmartShipClient( $api_key );
    $resolved = ( new CityResolver( $client, SmartShipClient::RATE_TIMEOUT ) )->resolve( (string) ( $dest['state'] ?? '' ), (string) ( $dest['city'] ?? '' ) );
    if ( empty( $resolved['city_id'] ) ) {
      $this->add_fallback( $config );
      return;
    }

    $sender = $this->sender_block( $client );
    if ( empty( $sender ) ) {
      $this->add_fallback( $config );
      return;
    }

    $weight = (int) ceil( max( 1.0, $this->package_weight( $package ) ) );
    $costs  = $this->fetch_costs( $client, (int) $resolved['city_id'], $weight, (string) ( $dest['address'] ?? '' ), $sender );
    if ( null === $costs ) {
      $this->add_fallback( $config );
      return;
    }

    $rates = RateCalculator::build_rates( $costs, $config );
    if ( empty( $rates ) ) {
      $this->add_fallback( $config );
      return;
    }
    foreach ( $rates as $r ) {
      $this->add_rate( [
        'id'        => $r['id'],
        'label'     => $r['label'],
        'cost'      => $r['cost'],
        'meta_data' => [ 'courier_id' => $r['courier_id'] ],
      ] );
    }
  }

  private function add_fallback( array $config ): void {
    $f = RateCalculator::fallback_rate( $config );
    $this->add_rate( [ 'id' => $f['id'], 'label' => $f['label'], 'cost' => $f['cost'] ] );
  }

  /** Cached /cost costs[] for (city, weight); null on failure (and sets a brief failure-cache). */
  private function fetch_costs( SmartShipClient $client, int $city_id, int $weight, string $address, array $sender ) {
    $key    = 'webbership_ss_rate_' . md5( $city_id . '|' . $weight );
    $cached = get_transient( $key );
    if ( is_array( $cached ) ) {
      return $cached;
    }
    if ( get_transient( 'webbership_ss_rate_fail' ) ) {
      return null;
    }
    $body = [
      'recipient' => [ 'name' => 'Estimate', 'address' => $address, 'email' => 'estimate@example.com', 'city' => $city_id, 'phone' => '0700000000', 'country' => 'RO', 'sector' => '0' ],
      'sender'    => $sender,
      'content'   => [ 'package_content' => 'Estimate', 'parcels' => 1, 'weight' => $weight, 'cash_on_delivery' => 0, 'length' => 10, 'width' => 10, 'height' => 10 ],
    ];
    $res = $client->cost( $body, SmartShipClient::RATE_TIMEOUT );
    if ( empty( $res['ok'] ) ) {
      set_transient( 'webbership_ss_rate_fail', 1, MINUTE_IN_SECONDS );
      return null;
    }
    $costs = $res['costs'] ?? ( $res['response']['costs'] ?? [] );
    // A malformed ok-response (costs not an array) must fall back, not fatal build_rates(array).
    if ( ! is_array( $costs ) ) {
      set_transient( 'webbership_ss_rate_fail', 1, MINUTE_IN_SECONDS );
      return null;
    }
    set_transient( $key, $costs, 10 * MINUTE_IN_SECONDS );
    return $costs;
  }

  /** Sender block from the configured sender, cached a day to stay off the checkout hot path. */
  private function sender_block( SmartShipClient $client ): array {
    $id = Settings::sender_id();
    if ( $id <= 0 ) {
      return [];
    }
    $tk    = 'webbership_ss_sender_block_' . $id;
    $block = get_transient( $tk );
    if ( is_array( $block ) ) {
      return $block;
    }
    $res = $client->get_senders( SmartShipClient::RATE_TIMEOUT );
    foreach ( (array) ( $res['senders'] ?? [] ) as $s ) {
      if ( (int) ( $s['id'] ?? 0 ) === $id ) {
        $block = AwbPayload::sender_from_account( $s );
        // A usable sender block needs at least a name and a (nonzero int) city id;
        // an incomplete one would make /cost fail anyway, so reject (and don't cache) it.
        if ( empty( $block['name'] ) || empty( $block['city'] ) ) {
          return [];
        }
        set_transient( $tk, $block, DAY_IN_SECONDS );
        return $block;
      }
    }
    return [];
  }

  private function package_weight( array $package ): float {
    $weight = 0.0;
    foreach ( (array) ( $package['contents'] ?? [] ) as $item ) {
      $product = isset( $item['data'] ) && is_object( $item['data'] ) ? $item['data'] : null;
      $qty     = (int) ( $item['quantity'] ?? 1 );
      if ( $product && method_exists( $product, 'get_weight' ) && '' !== (string) $product->get_weight() ) {
        $weight += (float) $product->get_weight() * max( 1, $qty );
      }
    }
    return $weight;
  }
}
