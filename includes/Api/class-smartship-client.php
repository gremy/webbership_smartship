<?php
declare(strict_types=1);

namespace Webbership\Smartship\Api;

defined( 'ABSPATH' ) || exit;

/**
 * The single outbound client for the SmartShip.ro API.
 *
 * Success is SmartShip's in-body `status` field (200), NOT the HTTP code:
 * SmartShip returns HTTP 200 with body status 999/205/201/202 on errors.
 * Every method returns the house tuple:
 *   [ 'ok'=>bool, 'status'=>int, 'http'=>int, 'code'=>string,
 *     'message'=>string, 'errors'=>array, ...payload ]
 *
 * @package Webbership\Smartship\Api
 */
final class SmartShipClient {
  public const BASE_URL     = 'https://api.smartship.ro';
  public const TIMEOUT      = 20; // admin / back-office calls
  public const RATE_TIMEOUT = 3;  // checkout /cost call

  private string $api_key;
  private string $base_url;

  public function __construct( string $api_key, string $base_url = self::BASE_URL ) {
    $this->api_key  = $api_key;
    $this->base_url = untrailingslashit( $base_url );
  }

  /**
   * Probe credentials via GET /account/senders — an AUTHENTICATED endpoint.
   * (Phase 0 spike: /geolocation/counties is public and returns 200 for ANY key,
   * so it cannot validate credentials. /account/senders returns in-body status
   * 401 "The API key you entered is not recognized by the system." on a bad key.)
   * NOT cached: a stale success must never validate a new bad key.
   */
  public function validate_credentials(): array {
    return $this->request( 'GET', '/account/senders' );
  }

  public function get_counties( int $timeout = self::TIMEOUT ): array {
    return $this->cached( 'counties', 12 * HOUR_IN_SECONDS, function () use ( $timeout ) {
      return $this->request( 'GET', '/geolocation/counties', [ 'query' => [ 'country' => 'RO' ], 'timeout' => $timeout ] );
    } );
  }

  public function get_cities( int $county_id, int $timeout = self::TIMEOUT ): array {
    return $this->cached( 'cities_' . $county_id, 12 * HOUR_IN_SECONDS, function () use ( $county_id, $timeout ) {
      return $this->request( 'GET', '/geolocation/cities', [ 'query' => [ 'county' => $county_id ], 'timeout' => $timeout ] );
    } );
  }

  public function get_senders( int $timeout = self::TIMEOUT ): array {
    return $this->cached( 'senders', 5 * 60, function () use ( $timeout ) {
      return $this->request( 'GET', '/account/senders', [ 'timeout' => $timeout ] );
    } );
  }

  public function cost( array $body, int $timeout = self::TIMEOUT ): array {
    return $this->request( 'POST', '/cost', [ 'body' => $body, 'shop_headers' => true, 'timeout' => $timeout ] );
  }

  public function create_awb( array $body ): array {
    return $this->request( 'POST', '/awb/new', [ 'body' => $body ] );
  }

  public function get_awb_status( string $awb ): array {
    return $this->request( 'GET', '/awb/status/' . rawurlencode( $awb ) );
  }

  /**
   * SmartShip's /awb/cancel returns an EMPTY 200 body on a successful request and
   * does NOT actually cancel the shipment (verified live; their own PrestaShop
   * module clears the AWB locally and never relies on this call). We treat a 2xx
   * with an empty body as a best-effort success; the caller still clears locally.
   */
  public function cancel_awb( string $awb ): array {
    $url      = $this->base_url . '/awb/cancel/' . rawurlencode( $awb );
    $response = wp_remote_get( $url, [ 'timeout' => self::TIMEOUT, 'headers' => $this->headers( false ) ] );
    if ( is_wp_error( $response ) ) {
      return $this->error( 0, 'transport_error', $response->get_error_message() );
    }
    $http = (int) wp_remote_retrieve_response_code( $response );
    $body = trim( (string) wp_remote_retrieve_body( $response ) );
    // /awb/cancel returns an empty 200 on a successful request (no JSON body).
    if ( $http >= 200 && $http < 300 && '' === $body ) {
      return [ 'ok' => true, 'status' => 200, 'http' => $http, 'code' => '', 'message' => '', 'errors' => [] ];
    }
    $json = json_decode( $body, true );
    if ( is_array( $json ) ) {
      $st = isset( $json['status'] ) ? (int) $json['status'] : 0;
      // Strict success (matches request()): the body status must be the integer 200.
      if ( isset( $json['status'] ) && is_int( $json['status'] ) && 200 === $json['status'] ) {
        return [ 'ok' => true, 'status' => 200, 'http' => $http, 'code' => '', 'message' => '', 'errors' => [] ];
      }
      return [ 'ok' => false, 'status' => $st, 'http' => $http, 'code' => $this->error_code( $st ), 'message' => $this->error_message( $st, $json ), 'errors' => [] ];
    }
    return $this->error( $http, 'cancel_failed', __( 'SmartShip did not confirm the cancellation.', 'webbership-smartship' ) );
  }

  public function print_awb( string $awb, string $format = 'A4' ): array {
    $format = in_array( $format, [ 'A4', 'A6' ], true ) ? $format : 'A4';
    $url      = $this->base_url . '/awb/print/' . rawurlencode( $awb ) . '/' . $format;
    $response = wp_remote_get( $url, [ 'timeout' => self::TIMEOUT, 'headers' => $this->headers( false ) ] );
    if ( is_wp_error( $response ) ) {
      return $this->error( 0, 'transport_error', $response->get_error_message() );
    }
    $http = (int) wp_remote_retrieve_response_code( $response );
    $body = (string) wp_remote_retrieve_body( $response );
    // The %PDF magic is the only proof of a PDF; content-type is metadata SmartShip
    // also sets on its JSON error bodies, so it can't gate success.
    if ( strncmp( $body, '%PDF', 4 ) === 0 ) {
      return [ 'ok' => true, 'status' => 200, 'http' => $http, 'code' => '', 'message' => '', 'errors' => [], 'pdf' => $body, 'content_type' => 'application/pdf' ];
    }
    $json = json_decode( $body, true );
    if ( is_array( $json ) ) {
      $st = isset( $json['status'] ) ? (int) $json['status'] : 0;
      return [ 'ok' => false, 'status' => $st, 'http' => $http, 'code' => $this->error_code( $st ), 'message' => $this->error_message( $st, $json ), 'errors' => [] ];
    }
    return $this->error( $http, 'invalid_response', __( 'SmartShip did not return a PDF.', 'webbership-smartship' ) );
  }

  /** Cache a successful tuple under an API-key-fingerprinted transient. */
  private function cached( string $key, int $ttl, callable $fetch ): array {
    $tk  = 'webbership_ss_' . substr( md5( $this->api_key ), 0, 12 ) . '_' . $key;
    $hit = get_transient( $tk );
    if ( is_array( $hit ) ) {
      return $hit;
    }
    $res = $fetch();
    if ( ! empty( $res['ok'] ) ) {
      set_transient( $tk, $res, $ttl );
    }
    return $res;
  }

  /**
   * @param string $method 'GET' | 'POST'.
   * @param string $path   Leading-slash path, e.g. '/geolocation/counties'.
   * @param array  $args   [ 'query'=>array, 'body'=>array, 'timeout'=>int, 'shop_headers'=>bool ].
   */
  public function request( string $method, string $path, array $args = [] ): array {
    $url = $this->base_url . $path;
    if ( ! empty( $args['query'] ) ) {
      $url = add_query_arg( $args['query'], $url );
    }

    $http_args = [
      'method'  => $method,
      'timeout' => isset( $args['timeout'] ) ? (int) $args['timeout'] : self::TIMEOUT,
      'headers' => $this->headers( ! empty( $args['shop_headers'] ) ),
    ];
    if ( isset( $args['body'] ) ) {
      $http_args['body'] = wp_json_encode( $args['body'] );
    }

    $response = wp_remote_request( $url, $http_args );

    if ( is_wp_error( $response ) ) {
      return $this->error( 0, 'transport_error', $response->get_error_message() );
    }

    $http = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

    if ( ! is_array( $body ) ) {
      return $this->error( $http, 'invalid_json', __( 'Unexpected response from SmartShip.', 'webbership-smartship' ) );
    }

    // Success requires a STRICT integer 200 in the body — a non-integer like
    // "200abc" coerces to (int) 200 but must NOT count as success.
    $app_status = isset( $body['status'] ) ? (int) $body['status'] : 0;
    $is_success = isset( $body['status'] ) && is_int( $body['status'] ) && 200 === $body['status'];
    if ( ! $is_success ) {
      return [
        'ok'      => false,
        'status'  => $app_status,
        'http'    => $http,
        'code'    => $this->error_code( $app_status ),
        'message' => $this->error_message( $app_status, $body ),
        'errors'  => ( isset( $body['erori'] ) && is_array( $body['erori'] ) ) ? $body['erori'] : [],
      ];
    }

    return [ 'ok' => true, 'status' => 200, 'http' => $http, 'code' => '', 'message' => '', 'errors' => [] ] + $body;
  }

  private function headers( bool $shop_headers ): array {
    $headers = [
      'X-API-KEY'    => $this->api_key,
      'X-Platform'   => 'WP-PLUGIN',
      'Accept'       => 'application/json',
      'Content-Type' => 'application/json',
    ];
    if ( $shop_headers ) {
      $headers['X-Shop-Name'] = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
      $headers['X-Shop-Url']  = home_url();
    }
    return $headers;
  }

  private function error_code( int $status ): string {
    switch ( $status ) {
      case 999: return 'validation';
      case 205: return 'iban_missing';
      case 201: return 'specify_county';
      case 202: return 'county_not_found';
      default:  return 'api_error';
    }
  }

  private function error_message( int $status, array $body ): string {
    if ( 205 === $status ) {
      return __( 'SmartShip requires an IBAN for cash-on-delivery. Add it in the plugin settings.', 'webbership-smartship' );
    }
    if ( ! empty( $body['message'] ) ) {
      return (string) $body['message'];
    }
    if ( ! empty( $body['error'] ) ) {
      return (string) $body['error'];
    }
    /* translators: %d: SmartShip in-body status code. */
    return sprintf( __( 'SmartShip returned status %d.', 'webbership-smartship' ), $status );
  }

  private function error( int $http, string $code, string $message ): array {
    return [ 'ok' => false, 'status' => 0, 'http' => $http, 'code' => $code, 'message' => $message, 'errors' => [] ];
  }
}
