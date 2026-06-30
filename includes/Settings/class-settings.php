<?php
declare(strict_types=1);

namespace Webbership\Smartship\Settings;

use Webbership\Smartship\Api\SmartShipClient;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings page + accessors (WP Settings API).
 *
 * @package Webbership\Smartship\Settings
 */
final class Settings {
  public const OPTION       = 'webbership_smartship_settings';
  public const GROUP        = 'webbership_smartship_settings_group';
  public const PAGE         = 'webbership-smartship';
  public const CAPABILITY   = 'manage_woocommerce';
  public const KEY_CONSTANT = 'WEBBERSHIP_SMARTSHIP_API_KEY';

  public const ACTION_TEST       = 'webbership_smartship_test_connection';
  public const LAST_ERROR_OPTION = 'webbership_smartship_last_error';

  public function register_hooks(): void {
    add_action( 'admin_menu', [ $this, 'add_menu' ] );
    add_action( 'admin_init', [ $this, 'register_settings' ] );
    add_action( 'admin_post_' . self::ACTION_TEST, [ $this, 'handle_test_connection' ] );
  }

  public function handle_test_connection(): void {
    if ( ! current_user_can( self::CAPABILITY ) ) {
      wp_die( esc_html__( 'You do not have permission to do this.', 'webbership-smartship' ) );
    }
    check_admin_referer( self::ACTION_TEST );

    $result  = ( new SmartShipClient( self::api_key() ) )->validate_credentials();
    $success = (bool) $result['ok'];
    update_option( self::LAST_ERROR_OPTION, $success ? '' : (string) $result['message'], false );

    wp_safe_redirect( add_query_arg(
      [ 'page' => self::PAGE, 'webbership_ss_test' => $success ? 'ok' : 'fail' ],
      admin_url( 'admin.php' )
    ) );
    exit;
  }

  public static function defaults(): array {
    return [ 'api_key' => '', 'debug' => 'no', 'sender_id' => 0, 'iban' => '' ];
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

  public static function sender_id(): int {
    return (int) self::get()['sender_id'];
  }

  public static function iban(): string {
    return (string) self::get()['iban'];
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
      __( 'SmartShip', 'webbership-smartship' ),
      __( 'SmartShip', 'webbership-smartship' ),
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

    $out = [
      'api_key' => $api_key,
      'debug'   => ( isset( $input['debug'] ) && 'yes' === $input['debug'] ) ? 'yes' : 'no',
    ];
    $out['sender_id'] = isset( $input['sender_id'] ) ? absint( $input['sender_id'] ) : (int) $current['sender_id'];

    // Blank clears; a non-empty IBAN must look like RO + 22 chars, else keep the
    // stored one and flag it — never persist garbage that /awb/new would reject.
    $iban = isset( $input['iban'] ) ? strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( (string) $input['iban'] ) ) ) : (string) $current['iban'];
    if ( '' !== $iban && ! preg_match( '/^RO[0-9A-Z]{22}$/', $iban ) ) {
      add_settings_error( self::OPTION, 'iban', __( 'IBAN looks invalid — expected RO + 22 characters.', 'webbership-smartship' ) );
      $iban = (string) $current['iban'];
    }
    $out['iban'] = $iban;

    return $out;
  }

  public function render_page(): void {
    if ( ! current_user_can( self::CAPABILITY ) ) {
      wp_die( esc_html__( 'You do not have permission to view this page.', 'webbership-smartship' ) );
    }
    ?>
    <div class="wrap">
      <h1><?php echo esc_html__( 'SmartShip', 'webbership-smartship' ); ?></h1>
      <p class="description" style="max-width:46em">
        <?php echo esc_html__( 'Connect your SmartShip.ro account below. Then turn on live checkout rates under WooCommerce → Settings → Shipping (add the "SmartShip Live Rates" method to a zone), and issue AWBs from the "SmartShip AWB" box on each order.', 'webbership-smartship' ); ?>
      </p>
      <?php
      if ( isset( $_GET['webbership_ss_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag
        $ok = 'ok' === sanitize_key( wp_unslash( $_GET['webbership_ss_test'] ) );
        printf(
          '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
          $ok ? 'success' : 'error',
          $ok
            ? esc_html__( 'SmartShip connection OK.', 'webbership-smartship' )
            : esc_html( sprintf(
                /* translators: %s: error message from SmartShip. */
                __( 'SmartShip connection failed: %s', 'webbership-smartship' ),
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
              <label for="webbership_smartship_api_key"><?php echo esc_html__( 'API key', 'webbership-smartship' ); ?></label>
            </th>
            <td>
              <?php if ( self::key_is_constant() ) : ?>
                <p class="description">
                  <?php echo esc_html__( 'Defined in wp-config.php (WEBBERSHIP_SMARTSHIP_API_KEY); the database value is ignored.', 'webbership-smartship' ); ?>
                </p>
              <?php else : ?>
                <input
                  name="<?php echo esc_attr( self::OPTION ); ?>[api_key]"
                  id="webbership_smartship_api_key"
                  type="password"
                  value=""
                  autocomplete="new-password"
                  class="regular-text" />
                <p class="description">
                  <?php echo esc_html__( 'Find your key in your SmartShip.ro account under API access. The stored key is hidden — leave this blank to keep the current one. After saving, use "Test connection" below to verify it.', 'webbership-smartship' ); ?>
                </p>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="webbership_smartship_sender"><?php echo esc_html__( 'Default sender', 'webbership-smartship' ); ?></label></th>
            <td>
              <?php
              $current_sender = self::sender_id();
              $senders        = self::api_key() !== '' ? ( new \Webbership\Smartship\Api\SmartShipClient( self::api_key() ) )->get_senders() : [ 'ok' => false ];
              if ( ! empty( $senders['ok'] ) && ! empty( $senders['senders'] ) ) :
                ?>
                <select name="<?php echo esc_attr( self::OPTION ); ?>[sender_id]" id="webbership_smartship_sender">
                  <?php foreach ( (array) $senders['senders'] as $snd ) :
                    $sid = (int) ( $snd['id'] ?? 0 );
                    $lbl = trim( ( $snd['nume'] ?? '' ) . ' — ' . ( $snd['localitate'] ?? '' ), ' —' );
                    ?>
                    <option value="<?php echo esc_attr( (string) $sid ); ?>" <?php selected( $current_sender, $sid ); ?>><?php echo esc_html( $lbl ); ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="description"><?php echo esc_html__( 'The pickup address printed on AWBs and used as the rate origin. Add or edit senders in your SmartShip.ro account.', 'webbership-smartship' ); ?></p>
              <?php else : ?>
                <p class="description"><?php echo esc_html__( 'Save a valid API key first, then reload to choose a sender.', 'webbership-smartship' ); ?></p>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="webbership_smartship_iban"><?php echo esc_html__( 'IBAN (for cash-on-delivery)', 'webbership-smartship' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[iban]" id="webbership_smartship_iban" value="<?php echo esc_attr( self::iban() ); ?>" placeholder="RO00 BANK 0000 0000 0000 0000" />
              <p class="description" style="max-width:46em">
                <?php echo esc_html__( 'Only used for cash-on-delivery (ramburs) orders. SmartShip requires the payout IBAN on every COD shipment, and its API cannot read the one saved on your SmartShip account — so enter the same IBAN here. Leave blank if you do not ship cash-on-delivery.', 'webbership-smartship' ); ?>
              </p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php echo esc_html__( 'Debug logging', 'webbership-smartship' ); ?></th>
            <td>
              <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[debug]" value="yes" <?php checked( self::enabled( 'debug' ) ); ?> />
                <?php echo esc_html__( 'Log SmartShip API calls to WooCommerce → Status → Logs.', 'webbership-smartship' ); ?>
              </label>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
      <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TEST ); ?>" />
      <?php wp_nonce_field( self::ACTION_TEST ); ?>
      <?php submit_button( __( 'Test connection', 'webbership-smartship' ), 'secondary', 'submit', false ); ?>
    </form>
    </div>
    <?php
  }
}
