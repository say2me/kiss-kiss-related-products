<?php
/**
 * Not found container exception.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts;

use Psr\Container\ContainerExceptionInterface;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin container
 */
class ContainerException extends Exception implements ContainerExceptionInterface {

}
