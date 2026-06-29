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

  private static function log( string $level, string $message, array $context ): void {
    if ( ! function_exists( 'wc_get_logger' ) ) {
      return;
    }
    wc_get_logger()->log( $level, self::redact( $message ), array_merge( [ 'source' => self::SOURCE ], $context ) );
  }
}
