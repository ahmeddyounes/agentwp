<?php
/**
 * Dependency injection container.
 *
 * @package AgentWP\Container
 */

namespace AgentWP\Container;

/**
 * Lightweight dependency injection container.
 *
 * Supports:
 * - Transient and singleton bindings
 * - Lazy resolution via callables
 * - Service tagging for group retrieval
 * - Instance registration
 */
class Container implements ContainerInterface {

	/**
	 * Registered bindings.
	 *
	 * @var array<string, array{resolver: callable|string, singleton: bool}>
	 */
	private array $bindings = array();

	/**
	 * Resolved singleton instances.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Service tags.
	 *
	 * @var array<string, array<string>>
	 */
	private array $tags = array();

	/**
	 * Currently resolving services (for circular dependency detection).
	 *
	 * @var array<string, bool>
	 */
	private array $resolving = array();

	/**
	 * {@inheritDoc}
	 */
	public function get( string $id ): mixed {
		// Return cached singleton instance if available.
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		// Check if binding exists.
		if ( ! isset( $this->bindings[ $id ] ) ) {
			throw new NotFoundException( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Safe in exception message context.
		}

		// Detect circular dependencies.
		if ( isset( $this->resolving[ $id ] ) ) {
			throw new ContainerException(
				sprintf( 'Circular dependency detected while resolving "%s".', $id ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Safe in exception message context.
			);
		}

		$this->resolving[ $id ] = true;

		try {
			$binding  = $this->bindings[ $id ];
			$resolver = $binding['resolver'];
			$result   = $this->resolve( $resolver );

			// Cache singleton instances.
			if ( $binding['singleton'] ) {
				$this->instances[ $id ] = $result;
			}

			return $result;
		} finally {
			unset( $this->resolving[ $id ] );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function has( string $id ): bool {
		return isset( $this->bindings[ $id ] ) || isset( $this->instances[ $id ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function bind( string $id, callable|string $resolver ): void {
		$this->bindings[ $id ] = array(
			'resolver'  => $resolver,
			'singleton' => false,
		);

		// Clear any cached instance.
		unset( $this->instances[ $id ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function singleton( string $id, callable|string $resolver ): void {
		$this->bindings[ $id ] = array(
			'resolver'  => $resolver,
			'singleton' => true,
		);

		// Clear any cached instance.
		unset( $this->instances[ $id ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function instance( string $id, object $instance ): void {
		$this->instances[ $id ] = $instance;

		// Register a binding that references the instances array (not capturing the object).
		// This prevents memory leaks when instance() is called multiple times for same ID.
		$this->bindings[ $id ] = array(
			'resolver'  => fn() => $this->instances[ $id ],
			'singleton' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function tag( string $id, string $tag ): void {
		if ( ! isset( $this->tags[ $tag ] ) ) {
			$this->tags[ $tag ] = array();
		}

		if ( ! in_array( $id, $this->tags[ $tag ], true ) ) {
			$this->tags[ $tag ][] = $id;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function tagged( string $tag ): array {
		if ( ! isset( $this->tags[ $tag ] ) ) {
			return array();
		}

		$services = array();
		foreach ( $this->tags[ $tag ] as $id ) {
			$services[] = $this->get( $id );
		}

		return $services;
	}

	/**
	 * Resolve a resolver to its value.
	 *
	 * @param callable|string $resolver The resolver.
	 * @return mixed The resolved value.
	 * @throws ContainerException If resolution fails.
	 */
	private function resolve( callable|string $resolver ): mixed {
		if ( is_callable( $resolver ) ) {
			return $resolver( $this );
		}

		if ( is_string( $resolver ) && class_exists( $resolver ) ) {
			return $this->build( $resolver );
		}

		throw new ContainerException(
			sprintf( 'Cannot resolve binding: %s', is_string( $resolver ) ? $resolver : 'callable' ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Safe in exception message context.
		);
	}

	/**
	 * Build an instance of a class with auto-wiring.
	 *
	 * @param string $class The class name.
	 * @return object The built instance.
	 * @throws ContainerException If the class cannot be instantiated.
	 */
	private function build( string $class ): object {
		// Detect circular dependencies during auto-wiring.
		if ( isset( $this->resolving[ $class ] ) ) {
			throw new ContainerException(
				sprintf( 'Circular dependency detected while auto-wiring "%s".', $class ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Safe in exception message context.
			);
		}

		$this->resolving[ $class ] = true;

		try {
			if ( ! class_exists( $class ) ) {
				throw new ContainerException(
					sprintf( 'Class "%s" does not exist.', $class )
				);
			}

			$reflection = new \ReflectionClass( $class );

			if ( ! $reflection->isInstantiable() ) {
				throw new ContainerException(
					sprintf( 'Class "%s" is not instantiable.', $class )
				);
			}

			$constructor = $reflection->getConstructor();

			// No constructor, just instantiate.
			if ( null === $constructor ) {
				return new $class();
			}

			$parameters = $constructor->getParameters();

			// No parameters, just instantiate.
			if ( empty( $parameters ) ) {
				return new $class();
			}

			$dependencies = $this->resolveDependencies( $parameters );

			return $reflection->newInstanceArgs( $dependencies );
		} finally {
			unset( $this->resolving[ $class ] );
		}
	}

	/**
	 * Resolve constructor dependencies.
	 *
	 * @param \ReflectionParameter[] $parameters The constructor parameters.
	 * @return array<mixed> The resolved dependencies.
	 * @throws ContainerException If a dependency cannot be resolved.
	 */
	private function resolveDependencies( array $parameters ): array {
		$dependencies = array();

		foreach ( $parameters as $parameter ) {
			$type = $parameter->getType();

			// Handle union types - try each type.
			if ( $type instanceof \ReflectionUnionType ) {
				$resolved = false;
				foreach ( $type->getTypes() as $unionType ) {
					if ( $unionType instanceof \ReflectionNamedType && ! $unionType->isBuiltin() ) {
						$typeName = $unionType->getName();
						if ( $this->has( $typeName ) ) {
							$dependencies[] = $this->get( $typeName );
							$resolved       = true;
							break;
						}
					}
				}

				if ( ! $resolved && $parameter->isDefaultValueAvailable() ) {
					$dependencies[] = $parameter->getDefaultValue();
					continue;
				}

				if ( ! $resolved && $parameter->allowsNull() ) {
					$dependencies[] = null;
					continue;
				}

					if ( ! $resolved ) {
						throw new ContainerException(
							sprintf( 'Cannot resolve union type parameter "%s".', $parameter->getName() ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Safe in exception message context.
						);
					}
					continue;
				}

			// Handle named types.
			if ( $type instanceof \ReflectionNamedType && ! $type->isBuiltin() ) {
				$typeName = $type->getName();

				if ( $this->has( $typeName ) ) {
					$dependencies[] = $this->get( $typeName );
					continue;
				}

				// Try to auto-wire if the class exists.
				if ( class_exists( $typeName ) ) {
					$dependencies[] = $this->build( $typeName );
					continue;
				}
			}

			// Use default value if available.
			if ( $parameter->isDefaultValueAvailable() ) {
				$dependencies[] = $parameter->getDefaultValue();
				continue;
			}

			// Allow null if nullable.
			if ( $parameter->allowsNull() ) {
				$dependencies[] = null;
				continue;
			}

				throw new ContainerException(
					sprintf(
						'Cannot resolve parameter "%s" for auto-wiring.',
						$parameter->getName() // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Safe in exception message context.
					)
				);
			}

		return $dependencies;
	}

	/**
	 * Flush all bindings and instances.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->bindings  = array();
		$this->instances = array();
		$this->tags      = array();
		$this->resolving = array();
	}

	/**
	 * Get all registered binding identifiers.
	 *
	 * @return array<string> The binding identifiers.
	 */
	public function getBindings(): array {
		return array_keys( $this->bindings );
	}
}
