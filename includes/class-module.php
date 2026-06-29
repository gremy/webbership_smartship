<?php
declare(strict_types=1);

namespace Ovride\Smartship;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for plugin modules.
 *
 * @package Ovride\Smartship
 */
abstract class Module {
  abstract public function name(): string;
  abstract public function is_supported(): bool;
  abstract public function register_hooks(): void;
}
