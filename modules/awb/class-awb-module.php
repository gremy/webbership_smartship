<?php
declare(strict_types=1);

namespace Ovride\Smartship\Modules\Awb;

defined( 'ABSPATH' ) || exit;

use Ovride\Smartship\Module;
use Ovride\Smartship\Dependencies;

/**
 * @package Ovride\Smartship\Modules\Awb
 */
final class AwbModule extends Module {
  public function name(): string { return 'awb'; }

  public function is_supported(): bool { return Dependencies::woocommerce_active(); }

  public function register_hooks(): void {
    require_once OVRIDE_SMARTSHIP_DIR . 'modules/awb/data/class-awb-payload.php';
    require_once OVRIDE_SMARTSHIP_DIR . 'modules/awb/admin/class-awb-metabox.php';
    require_once OVRIDE_SMARTSHIP_DIR . 'modules/awb/admin/class-awb-print.php';
    ( new Admin\AwbMetabox() )->register_hooks();
    ( new Admin\AwbPrint() )->register_hooks();
  }
}
