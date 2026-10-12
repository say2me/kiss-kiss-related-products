<?php
/**
 * Plugin class.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts;

use Psr\Container\ContainerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin container
 */
class Container implements ContainerInterface {
	/**
	 * List on classes instances
	 *
	 * @var array<string, mixed>
	 */
	private $instances = array();

	/**
	 * List of factories
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Add object
	 *
	 * @param string $id Abstract object name.
	 * @param mixed  $instance Instance of object.
	 *
	 * @return mixed
	 * @throws ContainerException Error while retrieving the entry.
	 */
	public function set( string $id, $instance ) {
		if ( $this->has( $id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new ContainerException( sprintf( 'Service with id %s already exists.', $id ) );
		}
		$this->instances[ $id ] = $instance;
		return $instance;
	}

	/**
	 * Replace an existing object, for a default that a later module supersedes.
	 *
	 * @param string $id Abstract object name.
	 * @param mixed  $instance Instance of object.
	 *
	 * @return mixed
	 */
	public function replace( string $id, $instance ) {
		$this->instances[ $id ] = $instance;
		return $instance;
	}

	/**
	 * Get service instance by id.
	 *
	 * @param mixed $id Abstract object name.
	 *
	 * @throws NotFoundContainerException Error No entry was found for this identifier.
	 * @throws ContainerException Error while retrieving the entry.
	 *
	 * @return mixed
	 */
	public function get( $id ) {
		if ( ! $this->has( $id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new NotFoundContainerException( sprintf( 'No entry was found for %s identifier ', $id ) );
		}

		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}
		// If the service is a factory, create a new instance.
		if ( isset( $this->factories[ $id ] ) ) {
			$this->instances[ $id ] = call_user_func( $this->factories[ $id ], $this );
			return $this->instances[ $id ];
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw new ContainerException( 'Error while retrieving the entry' );
	}

	/**
	 * Does object instance or factory exists
	 *
	 * @param string $id Object Id.
	 *
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->instances[ $id ] ) || isset( $this->factories[ $id ] );
	}

	/**
	 * Register a factory, new instance each time
	 *
	 * @param string   $id Object id.
	 * @param callable $factory Factory function that returns a new instance of the object.
	 * @throws ContainerException Error while retrieving the entry.
	 *
	 * @return void
	 */
	public function factory( string $id, callable $factory ): void {
		if ( $this->has( $id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new ContainerException( sprintf( 'Service with id %s already exists.', $id ) );
		}
		$this->factories[ $id ] = $factory;
	}
}
