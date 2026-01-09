<?php
/**
 * Dependency injection container interface.
 *
 * @package AgentWP\Container
 */

namespace AgentWP\Container;

/**
 * PSR-11 inspired container interface for dependency injection.
 */
interface ContainerInterface {

	/**
	 * Finds an entry of the container by its identifier and returns it.
	 *
	 * @param string $id Identifier of the entry to look for.
	 * @return mixed Entry.
	 * @throws NotFoundException No entry was found for this identifier.
	 * @throws ContainerException Error while retrieving the entry.
	 */
	public function get( string $id ): mixed;

	/**
	 * Returns true if the container can return an entry for the given identifier.
	 *
	 * @param string $id Identifier of the entry to look for.
	 * @return bool
	 */
	public function has( string $id ): bool;

	/**
	 * Register a binding in the container.
	 *
	 * @param string          $id       Identifier for the binding.
	 * @param callable|string $resolver Resolver callable or class name.
	 * @return void
	 */
	public function bind( string $id, callable|string $resolver ): void;

	/**
	 * Register a singleton binding in the container.
	 *
	 * @param string          $id       Identifier for the binding.
	 * @param callable|string $resolver Resolver callable or class name.
	 * @return void
	 */
	public function singleton( string $id, callable|string $resolver ): void;

	/**
	 * Register an existing instance in the container.
	 *
	 * @param string $id       Identifier for the binding.
	 * @param object $instance The instance to register.
	 * @return void
	 */
	public function instance( string $id, object $instance ): void;

	/**
	 * Tag a service with a tag name for group retrieval.
	 *
	 * @param string $id  Service identifier.
	 * @param string $tag Tag name.
	 * @return void
	 */
	public function tag( string $id, string $tag ): void;

	/**
	 * Get all services tagged with a specific tag.
	 *
	 * @param string $tag Tag name.
	 * @return array<mixed> Array of resolved services.
	 */
	public function tagged( string $tag ): array;
}
