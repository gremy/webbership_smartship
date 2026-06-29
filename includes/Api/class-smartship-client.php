<?php
declare(strict_types=1);

namespace Ovride\Smartship\Api;

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
 * @package Ovride\Smartship\Api
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
      return $this->error( $http, 'invalid_json', __( 'Unexpected response from SmartShip.', 'ovride-smartship' ) );
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
      return __( 'SmartShip requires an IBAN for cash-on-delivery. Add it in the plugin settings.', 'ovride-smartship' );
    }
    if ( ! empty( $body['message'] ) ) {
      return (string) $body['message'];
    }
    if ( ! empty( $body['error'] ) ) {
      return (string) $body['error'];
    }
    /* translators: %d: SmartShip in-body status code. */
    return sprintf( __( 'SmartShip returned status %d.', 'ovride-smartship' ), $status );
  }

  private function error( int $http, string $code, string $message ): array {
    return [ 'ok' => false, 'status' => 0, 'http' => $http, 'code' => $code, 'message' => $message, 'errors' => [] ];
  }
}
