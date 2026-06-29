<?php
declare(strict_types=1);

namespace Ovride\Smartship;

use Ovride\Smartship\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over wc_get_logger() with API-key redaction.
 *
 * @package Ovride\Smartship
 */
final class Logger {
  private const SOURCE = 'ovride-smartship';

  public static function error( string $message, array $context = [] ): void {
    self::log( 'error', $message, $context );
  }

  public static function info( string $message, array $context = [] ): void {
    if ( ! Settings::enabled( 'debug' ) ) {
      return;
    }
    self::log( 'info', $message, $context );
  }

  public static function redact( string $text ): string {
    $key = Settings::api_key();
    return ( '' !== $key ) ? str_replace( $key, '***', $text ) : $text;
  }

  /** Recursively redact the API key from every string in the context array. */
  private static function redact_context( array $context ): array {
    foreach ( $context as $k => $v ) {
      if ( is_string( $v ) ) {
        $context[ $k ] = self::redact( $v );
      } elseif ( is_array( $v ) ) {
        $context[ $k ] = self::redact_context( $v );
      }
    }
    return $context;
  }

  private static function log( string $level, string $message, array $context ): void {
    if ( ! function_exists( 'wc_get_logger' ) ) {
      return;
    }
    $context = self::redact_context( array_merge( [ 'source' => self::SOURCE ], $context ) );
    wc_get_logger()->log( $level, self::redact( $message ), $context );
  }
}
