<?php
/**
 * Not found container exception.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts;

use Psr\Container\NotFoundExceptionInterface;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin container
 */
class NotFoundContainerException extends InvalidArgumentException implements NotFoundExceptionInterface {

}
