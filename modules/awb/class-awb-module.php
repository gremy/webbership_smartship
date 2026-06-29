<?php
declare(strict_types=1);

namespace Webbership\Smartship\Modules\Awb;

defined( 'ABSPATH' ) || exit;

use Webbership\Smartship\Module;
use Webbership\Smartship\Dependencies;

/**
 * @package Webbership\Smartship\Modules\Awb
 */
final class AwbModule extends Module {
  public function name(): string { return 'awb'; }

  public function is_supported(): bool { return Dependencies::woocommerce_active(); }

  public function register_hooks(): void {
    require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/awb/data/class-awb-payload.php';
    require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/awb/admin/class-awb-metabox.php';
    require_once WEBBERSHIP_SMARTSHIP_DIR . 'modules/awb/admin/class-awb-print.php';
    ( new Admin\AwbMetabox() )->register_hooks();
    ( new Admin\AwbPrint() )->register_hooks();
  }
}
