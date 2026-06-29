<?php
declare(strict_types=1);

namespace Ovride\Smartship\Settings;

use Ovride\Smartship\Api\SmartShipClient;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings page + accessors (WP Settings API).
 *
 * @package Ovride\Smartship\Settings
 */
final class Settings {
  public const OPTION       = 'ovride_smartship_settings';
  public const GROUP        = 'ovride_smartship_settings_group';
  public const PAGE         = 'ovride-smartship';
  public const CAPABILITY   = 'manage_woocommerce';
  public const KEY_CONSTANT = 'OVRIDE_SMARTSHIP_API_KEY';

  public const ACTION_TEST       = 'ovride_smartship_test_connection';
  public const LAST_ERROR_OPTION = 'ovride_smartship_last_error';

  public function register_hooks(): void {
    add_action( 'admin_menu', [ $this, 'add_menu' ] );
    add_action( 'admin_init', [ $this, 'register_settings' ] );
    add_action( 'admin_post_' . self::ACTION_TEST, [ $this, 'handle_test_connection' ] );
  }

  public function handle_test_connection(): void {
    if ( ! current_user_can( self::CAPABILITY ) ) {
      wp_die( esc_html__( 'You do not have permission to do this.', 'ovride-smartship' ) );
    }
    check_admin_referer( self::ACTION_TEST );

    $result  = ( new SmartShipClient( self::api_key() ) )->validate_credentials();
    $success = (bool) $result['ok'];
    update_option( self::LAST_ERROR_OPTION, $success ? '' : (string) $result['message'], false );

    wp_safe_redirect( add_query_arg(
      [ 'page' => self::PAGE, 'ovride_ss_test' => $success ? 'ok' : 'fail' ],
      admin_url( 'admin.php' )
    ) );
    exit;
  }

  public static function defaults(): array {
    return [ 'api_key' => '', 'debug' => 'no' ];
  }

  public static function get(): array {
    return wp_parse_args( get_option( self::OPTION, [] ), self::defaults() );
  }

  /** The wp-config constant wins over the DB; trimmed. */
  public static function api_key(): string {
    if ( self::key_is_constant() ) {
      return (string) constant( self::KEY_CONSTANT );
    }
    return trim( (string) self::get()['api_key'] );
  }

  public static function key_is_constant(): bool {
    return defined( self::KEY_CONSTANT ) && (string) constant( self::KEY_CONSTANT ) !== '';
  }

  public static function enabled( string $flag ): bool {
    return ( self::get()[ $flag ] ?? 'no' ) === 'yes';
  }

  public function add_menu(): void {
    add_submenu_page(
      'woocommerce',
      __( 'SmartShip', 'ovride-smartship' ),
      __( 'SmartShip', 'ovride-smartship' ),
      self::CAPABILITY,
      self::PAGE,
      [ $this, 'render_page' ]
    );
  }

  public function register_settings(): void {
    register_setting( self::GROUP, self::OPTION, [
      'type'              => 'array',
      'sanitize_callback' => [ $this, 'sanitize' ],
    ] );
  }

  /** Blank api_key submission keeps the stored value; never silently wipes it. */
  public function sanitize( $input ): array {
    $input   = is_array( $input ) ? $input : [];
    $current = self::get();

    $api_key = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
    if ( '' === $api_key ) {
      $api_key = (string) $current['api_key'];
    }

    return [
      'api_key' => $api_key,
      'debug'   => ( isset( $input['debug'] ) && 'yes' === $input['debug'] ) ? 'yes' : 'no',
    ];
  }

  public function render_page(): void {
    if ( ! current_user_can( self::CAPABILITY ) ) {
      wp_die( esc_html__( 'You do not have permission to view this page.', 'ovride-smartship' ) );
    }
    ?>
    <div class="wrap">
      <h1><?php echo esc_html__( 'SmartShip', 'ovride-smartship' ); ?></h1>
      <?php
      if ( isset( $_GET['ovride_ss_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag
        $ok = 'ok' === sanitize_key( wp_unslash( $_GET['ovride_ss_test'] ) );
        printf(
          '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
          $ok ? 'success' : 'error',
          $ok
            ? esc_html__( 'SmartShip connection OK.', 'ovride-smartship' )
            : esc_html( sprintf(
                /* translators: %s: error message from SmartShip. */
                __( 'SmartShip connection failed: %s', 'ovride-smartship' ),
                (string) get_option( self::LAST_ERROR_OPTION, '' )
              ) )
        );
      }
      ?>
      <form method="post" action="options.php">
        <?php settings_fields( self::GROUP ); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">
              <label for="ovride_smartship_api_key"><?php echo esc_html__( 'API key', 'ovride-smartship' ); ?></label>
            </th>
            <td>
              <?php if ( self::key_is_constant() ) : ?>
                <p class="description">
                  <?php echo esc_html__( 'Defined in wp-config.php (OVRIDE_SMARTSHIP_API_KEY); the database value is ignored.', 'ovride-smartship' ); ?>
                </p>
              <?php else : ?>
                <input
                  name="<?php echo esc_attr( self::OPTION ); ?>[api_key]"
                  id="ovride_smartship_api_key"
                  type="password"
                  value=""
                  autocomplete="new-password"
                  class="regular-text" />
                <p class="description">
                  <?php echo esc_html__( 'The stored key is hidden. Leave blank to keep the current key.', 'ovride-smartship' ); ?>
                </p>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php echo esc_html__( 'Debug logging', 'ovride-smartship' ); ?></th>
            <td>
              <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[debug]" value="yes" <?php checked( self::enabled( 'debug' ) ); ?> />
                <?php echo esc_html__( 'Log SmartShip API calls to WooCommerce → Status → Logs.', 'ovride-smartship' ); ?>
              </label>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
      <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TEST ); ?>" />
      <?php wp_nonce_field( self::ACTION_TEST ); ?>
      <?php submit_button( __( 'Test connection', 'ovride-smartship' ), 'secondary', 'submit', false ); ?>
    </form>
    </div>
    <?php
  }
}
