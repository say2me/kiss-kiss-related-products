<?php
/**
 * Plugin class.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin container
 */
final class Plugin {
	/**
	 * Initable instances
	 *
	 * @var array<int, mixed>
	 */
	private array $initable_services = array();
	/**
	 * Undocumented variable
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Build.
	 *
	 * @param Container $container Dependecies container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Build required components to start app
	 *
	 * @param  string $id Service identifier.
	 * @param  object $service Service instance to add.
	 *
	 * @return void
	 * @throws ContainerException If service with given id already exists.
	 */
	public function add_service( $id, $service ): void {
		if ( $this->container->has( $id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new ContainerException( sprintf( 'Service with id %s already exists.', $id ) );
		}
		$this->container->set( $id, $service );
		$service = $this->container->get( $id );
		if ( is_a( $service, InitableInterface::class ) ) {
			$this->initable_services[] = $id;
		}
	}

	/**
	 * Build required components to start app with factory
	 *
	 * @param string   $id Service identifier.
	 * @param callable $factory Factory function to create service instance.
	 * @return mixed
	 */
	public function add_factory( $id, callable $factory ) {
		if ( $this->container->has( $id ) ) {
			return $this->container->get( $id );
		}
		$this->container->factory( $id, $factory );
		$service = $this->container->get( $id );
		if ( is_a( $service, InitableInterface::class ) ) {
			$this->initable_services[] = $id;
		}
		return $this;
	}

	/**
	 * Boot plugin
	 *
	 * @return void
	 */
	public function start(): void {
		// Translations load by themselves: WordPress has done it for .org plugins since 4.6.
		// Load all initable services.
		foreach ( $this->initable_services as $id ) {
			if ( $this->container->has( $id ) ) {
				$this->container->get( $id )->init();
			}
		}
	}

	/**
	 * Container getter.
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}
}
