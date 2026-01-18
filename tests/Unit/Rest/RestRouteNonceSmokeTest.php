<?php
/**
 * REST route nonce enforcement smoke test.
 *
 * @package AgentWP\Tests\Unit\Rest
 */

namespace {
	if ( ! class_exists( 'WP_REST_Server' ) ) {
		class WP_REST_Server {
			public const READABLE  = 'GET';
			public const CREATABLE = 'POST';
			public const EDITABLE  = 'POST, PUT, PATCH';
			public const DELETABLE = 'DELETE';
			public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
		}
	}
}

namespace AgentWP\Tests\Unit\Rest {
	use AgentWP\Rest\RestController;
	use AgentWP\Tests\TestCase;
	use WP_Mock;

	class RestRouteNonceSmokeTest extends TestCase {

		public function test_state_changing_routes_require_permissions_check(): void {
			$registered_routes = array();

			WP_Mock::userFunction(
				'register_rest_route',
				array(
					'times'  => '1+',
					'return' => function ( $namespace, $route, $args ) use ( &$registered_routes ) {
						$registered_routes[] = array(
							'namespace' => $namespace,
							'route'     => $route,
							'args'      => $args,
						);
						return true;
					},
				)
			);

			foreach ( $this->get_rest_controllers() as $controller ) {
				$controller->register_routes();
			}

			$this->assertNotEmpty( $registered_routes, 'Expected REST routes to be registered.' );

			foreach ( $registered_routes as $registered ) {
				$definitions = $this->normalize_route_definitions( $registered['args'] );
				foreach ( $definitions as $definition ) {
					if ( ! isset( $definition['methods'] ) ) {
						continue;
					}

					if ( ! $this->is_state_changing_method( $definition['methods'] ) ) {
						continue;
					}

					$this->assertArrayHasKey(
						'permission_callback',
						$definition,
						sprintf( 'Missing permission_callback for %s%s.', $registered['namespace'], $registered['route'] )
					);

					$permission_callback = $definition['permission_callback'];

					$this->assertIsArray( $permission_callback );
					$this->assertSame( 'permissions_check', $permission_callback[1] ?? null );
					$this->assertInstanceOf( RestController::class, $permission_callback[0] ?? null );
				}
			}
		}

		/**
		 * Get all REST controllers from src/Rest.
		 *
		 * @return array<int, RestController>
		 */
		private function get_rest_controllers(): array {
			$rest_dir = dirname( __DIR__, 3 ) . '/src/Rest';
			$files    = glob( $rest_dir . '/*Controller.php' );

			$controllers = array();

			foreach ( $files as $file ) {
				$basename = basename( $file, '.php' );
				if ( 'RestController' === $basename ) {
					continue;
				}

				$class = 'AgentWP\\Rest\\' . $basename;
				if ( ! class_exists( $class ) ) {
					$this->fail( sprintf( 'REST controller class not found: %s', $class ) );
				}

				$controllers[] = new $class();
			}

			return $controllers;
		}

		/**
		 * Normalize route definitions to a list.
		 *
		 * @param mixed $args Route args from register_rest_route.
		 * @return array<int, array>
		 */
		private function normalize_route_definitions( $args ): array {
			if ( ! is_array( $args ) ) {
				return array();
			}

			if ( isset( $args['methods'] ) || isset( $args['callback'] ) || isset( $args['permission_callback'] ) ) {
				return array( $args );
			}

			$definitions = array();
			foreach ( $args as $definition ) {
				if ( is_array( $definition ) ) {
					$definitions[] = $definition;
				}
			}

			return $definitions;
		}

		/**
		 * Check if the route methods include a state-changing verb.
		 *
		 * @param mixed $methods Route methods.
		 * @return bool
		 */
		private function is_state_changing_method( $methods ): bool {
			$method_list = $this->normalize_methods( $methods );
			foreach ( $method_list as $method ) {
				if ( ! in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Normalize methods into an uppercase list.
		 *
		 * @param mixed $methods Route methods.
		 * @return array<int, string>
		 */
		private function normalize_methods( $methods ): array {
			if ( is_string( $methods ) ) {
				$methods = preg_split( '/\s*,\s*/', $methods );
			}

			if ( ! is_array( $methods ) ) {
				return array();
			}

			$normalized = array();
			foreach ( $methods as $method ) {
				if ( ! is_string( $method ) ) {
					continue;
				}
				$method = strtoupper( trim( $method ) );
				if ( '' !== $method ) {
					$normalized[] = $method;
				}
			}

			return $normalized;
		}
	}
}
