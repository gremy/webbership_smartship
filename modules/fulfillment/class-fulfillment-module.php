<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\Fulfillment;

defined( 'ABSPATH' ) || exit;

use Webbership\Smartship\Module;
use Webbership\Smartship\Modules\Fulfillment\Providers\SmartshipShippingProvider;
use Webbership\Smartship\Api\SmartShipClient;
use Webbership\Smartship\Settings\Settings;
use Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment;

/**
 * Registers the SmartShip WooCommerce Fulfillments shipping provider and
 * keeps a fulfillment's provider/tracking data in sync with the AWB the
 * AWB module already issued and stored on the order.
 *
 * WC's own provider auto-detection is a best-effort regex match against the
 * tracking number alone, so a numeric DPD/Cargus AWB can be guessed as some
 * other courier. Hooking the fulfillment save corrects it deterministically
 * whenever the tracking number matches the order's stored SmartShip AWB.
 *
 * @package Webbership\Smartship\Modules\Fulfillment
 */
final class FulfillmentModule extends Module {
  private const PROVIDER_KEY = 'webbership-smartship';
  private const AWB_META     = '_webbership_smartship_awb';
  private const COURIER_META = '_webbership_smartship_courier';

  public function name(): string { return 'fulfillment'; }

  public function is_supported(): bool {
    return class_exists( 'Automattic\WooCommerce\Admin\Features\Fulfillments\Providers\AbstractShippingProvider' );
  }

  public function register_hooks(): void {
    require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/fulfillment/providers/class-smartship-shipping-provider.php';

    add_filter( 'woocommerce_fulfillment_shipping_providers', [ $this, 'register_provider' ] );
    add_filter( 'woocommerce_fulfillment_before_create', [ $this, 'sync_from_order' ] );
    add_filter( 'woocommerce_fulfillment_before_update', [ $this, 'sync_from_order' ] );
    add_action( 'woocommerce_email_after_fulfillment_table', [ $this, 'render_courier_line' ], 10, 5 );
    // Priority 20: another registered provider's parser can greedily pattern-match
    // a plain numeric SmartShip AWB (courier AWBs routed through SmartShip reuse
    // that courier's own format), so the admin drawer's provider SUGGESTION can be
    // wrong. This filter only feeds that preview (WC fires it from the tracking
    // lookup REST endpoint, never on save) — what actually persists is pinned by
    // sync_from_order() on before_create/before_update regardless.
    add_filter( 'woocommerce_fulfillment_parse_tracking_number', [ $this, 'override_parsed_provider' ], 20 );
  }

  public function register_provider( array $providers ): array {
    $providers[ self::PROVIDER_KEY ] = SmartshipShippingProvider::class;
    return $providers;
  }

  /**
   * Fired on both woocommerce_fulfillment_before_create and _before_update.
   * If the fulfillment's tracking number matches the order's SmartShip AWB,
   * pin the provider/tracking URL/courier deterministically. If the order has
   * NO stored AWB yet and the tracking number isn't a match against nothing,
   * ask SmartShip whether it recognizes the number — the merchant may have
   * pasted it straight into the fulfillment drawer instead of the AWB
   * metabox — and adopt it onto the order so printing/tracking there also
   * works. A tracking number that doesn't match a DIFFERENT stored AWB is
   * left untouched: it's ambiguous whether it's a correction or an unrelated
   * number, so we don't guess.
   *
   * @param mixed $fulfillment Expected to be a Fulfillment; returned as-is.
   * @return mixed
   */
  public function sync_from_order( $fulfillment ) {
    if ( ! $fulfillment instanceof Fulfillment ) {
      return $fulfillment;
    }

    $order = $fulfillment->get_order();
    if ( ! $order ) {
      return $fulfillment;
    }

    $order_awb       = (string) $order->get_meta( self::AWB_META );
    $tracking_number = trim( (string) $fulfillment->get_meta( '_tracking_number', true ) );
    $courier         = '';

    if ( self::awb_matches( $order_awb, $tracking_number ) ) {
      $courier = (string) $order->get_meta( self::COURIER_META );
    } elseif ( '' === $order_awb && '' !== $tracking_number ) {
      // Deliberate tradeoff: this asks SmartShip about ANY tracking number saved
      // on an order with no stored SmartShip AWB — including other couriers'
      // numbers, which SmartShip won't recognize. Bounded by the 5s timeout and
      // the 5-minute negative cache; a tracking number alone is not sensitive.
      $status = $this->verified_awb_status( $tracking_number );
      if ( null === $status ) {
        return $fulfillment;
      }
      $courier = sanitize_text_field( self::courier_from_status( $status ) );
      $order->update_meta_data( self::AWB_META, $tracking_number );
      if ( '' !== $courier ) {
        $order->update_meta_data( self::COURIER_META, $courier );
      }
      $order->save();
    } else {
      return $fulfillment;
    }

    $fulfillment->set_shipment_provider( self::PROVIDER_KEY );
    $fulfillment->set_tracking_url( SmartshipShippingProvider::tracking_url( $tracking_number ) );
    $fulfillment->update_meta_data( self::COURIER_META, $courier );

    return $fulfillment;
  }

  /**
   * Hooked onto woocommerce_fulfillment_parse_tracking_number at priority 20
   * (see register_hooks() for why — preview-only; the save path is guaranteed
   * by sync_from_order()). Only overrides an ARRAY result that already carries
   * a tracking_number: a bare string means WC's manager didn't run, and when NO
   * provider matched at all WC returns an empty array without the number, so
   * there is nothing here to verify — that AWB still gets adopted on save.
   *
   * @param mixed $parsed The upstream filter's result.
   * @return mixed
   */
  public function override_parsed_provider( $parsed ) {
    if ( ! is_array( $parsed ) || empty( $parsed['tracking_number'] ) ) {
      return $parsed;
    }

    $tracking_number = (string) $parsed['tracking_number'];
    if ( ! $this->is_smartship_awb( $tracking_number ) ) {
      return $parsed;
    }

    $parsed['shipping_provider'] = self::PROVIDER_KEY;
    $parsed['tracking_url']      = SmartshipShippingProvider::tracking_url( $tracking_number );
    return $parsed;
  }

  /** Whether SmartShip recognizes $number as an AWB, API-verified (not a format guess). */
  public function is_smartship_awb( string $number, int $timeout = 5 ): bool {
    return null !== $this->verified_awb_status( $number, $timeout );
  }

  /**
   * The SmartShip /awb/status payload for $number if — and only if —
   * SmartShip confirms the AWB, else null. Exposed separately from
   * is_smartship_awb() so callers that also need the courier (sync_from_order(),
   * the metabox paste-back) can reuse this same API call instead of asking twice.
   *
   * Sanity-gates the format first (4-30 alphanumeric chars) so garbage input
   * and an unconfigured API key never reach the network. Verdicts are cached
   * in a transient keyed by the number: a positive one for ~1 hour (repeat
   * admin lookups on the same order — print, track, re-render — shouldn't
   * re-hit the API), a negative one for only ~5 minutes so a merchant fixing
   * a typo isn't stuck behind a stale "unknown AWB".
   */
  public function verified_awb_status( string $number, int $timeout = 5 ): ?array {
    $number = trim( $number );
    if ( ! preg_match( '/^[A-Za-z0-9]{4,30}$/', $number ) ) {
      return null;
    }
    if ( '' === Settings::api_key() ) {
      return null;
    }

    $cache_key = 'webbership_ss_awbverify_' . md5( strtoupper( $number ) );
    $cached    = get_transient( $cache_key );
    if ( 'no' === $cached ) {
      return null;
    }
    if ( is_array( $cached ) ) {
      return $cached;
    }

    $status = ( new SmartShipClient( Settings::api_key() ) )->get_awb_status( $number, $timeout );
    if ( empty( $status['ok'] ) ) {
      set_transient( $cache_key, 'no', 5 * 60 );
      return null;
    }
    set_transient( $cache_key, $status, HOUR_IN_SECONDS );
    return $status;
  }

  /**
   * Best-effort courier name out of a SmartShip /awb/status response — the
   * exact shape isn't documented, so this checks a handful of plausible
   * top-level keys, common wrapper keys (awb/data), and the newest/oldest
   * history entry, defensively. Returns '' rather than guessing wrong.
   * Pure/no WP calls so it's testable without WordPress loaded.
   */
  public static function courier_from_status( array $status ): string {
    foreach ( [ 'courier', 'courier_name', 'curier' ] as $key ) {
      if ( isset( $status[ $key ] ) && is_string( $status[ $key ] ) && '' !== trim( $status[ $key ] ) ) {
        return trim( $status[ $key ] );
      }
    }
    foreach ( [ 'awb', 'data' ] as $wrapper ) {
      if ( isset( $status[ $wrapper ] ) && is_array( $status[ $wrapper ] ) ) {
        $found = self::courier_from_status( $status[ $wrapper ] );
        if ( '' !== $found ) {
          return $found;
        }
      }
    }
    if ( isset( $status['history'] ) && is_array( $status['history'] ) && $status['history'] ) {
      $entries = array_values( $status['history'] );
      foreach ( [ $entries[ count( $entries ) - 1 ], $entries[0] ] as $entry ) {
        if ( is_array( $entry ) ) {
          $found = self::courier_from_status( $entry );
          if ( '' !== $found ) {
            return $found;
          }
        }
      }
    }
    return '';
  }

  /**
   * Adds a "Courier: DPD (SmartShip)" line to the fulfillment tracking email,
   * for the SmartShip-fulfilled shipments only.
   *
   * @param \WC_Order   $order         The order.
   * @param mixed       $fulfillment   Expected to be a Fulfillment.
   * @param bool        $sent_to_admin Whether the email goes to the admin.
   * @param bool        $plain_text    Whether this is the plain-text email.
   * @param \WC_Email   $email         The email object.
   */
  public function render_courier_line( $order, $fulfillment, bool $sent_to_admin, bool $plain_text, $email ): void {
    /**
     * Themes/plugins that already render the courier as the shipment-provider
     * label (so the customer never sees the internal "webbership-smartship"
     * slug or "SmartShip" as if it were the courier) set this true to skip
     * this plugin's own duplicate line.
     *
     * @param bool  $skip        Whether to suppress this line. Default false.
     * @param mixed $fulfillment Expected to be a Fulfillment.
     */
    if ( apply_filters( 'webbership_smartship_email_shows_courier', false, $fulfillment ) ) {
      return;
    }

    if ( ! $fulfillment instanceof Fulfillment || self::PROVIDER_KEY !== $fulfillment->get_shipment_provider() ) {
      return;
    }

    $courier = (string) $fulfillment->get_meta( self::COURIER_META );
    if ( '' === $courier && $order instanceof \WC_Order ) {
      $courier = (string) $order->get_meta( self::COURIER_META );
    }
    if ( '' === $courier ) {
      return;
    }

    if ( $plain_text ) {
      echo esc_html__( 'Courier:', 'webbership-smartship' ) . ' ' . esc_html( $courier ) . " (SmartShip)\n";
    } else {
      echo '<p><strong>' . esc_html__( 'Courier:', 'webbership-smartship' ) . '</strong> ' . esc_html( $courier ) . ' (SmartShip)</p>';
    }
  }

  /** Trim + case-insensitive compare; false whenever either side is empty. */
  public static function awb_matches( string $order_awb, string $tracking_number ): bool {
    $order_awb       = trim( $order_awb );
    $tracking_number = trim( $tracking_number );
    if ( '' === $order_awb || '' === $tracking_number ) {
      return false;
    }
    return 0 === strcasecmp( $order_awb, $tracking_number );
  }
}
