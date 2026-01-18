<?php
/**
 * Architecture boundary tests.
 *
 * These tests guard key architectural boundaries to prevent regressions:
 * - Services must not call forbidden WordPress/WooCommerce globals
 * - Services must depend only on interfaces (contracts)
 * - Controllers must resolve dependencies via container
 * - Infrastructure classes implement their contracts
 *
 * @package AgentWP\Tests\Integration\Architecture
 */

namespace AgentWP\Tests\Integration\Architecture;

use AgentWP\Tests\TestCase;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionParameter;
use ReflectionNamedType;

/**
 * Tests that enforce architectural boundaries.
 */
class BoundariesTest extends TestCase {

	/**
	 * Path to src directory.
	 *
	 * @var string
	 */
	private string $srcPath;

	/**
	 * PHP Parser instance.
	 *
	 * @var \PhpParser\Parser
	 */
	private $parser;

	/**
	 * Node finder instance.
	 *
	 * @var NodeFinder
	 */
	private NodeFinder $nodeFinder;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->srcPath    = dirname( __DIR__, 3 ) . '/src';
		$this->parser     = ( new ParserFactory() )->createForNewestSupportedVersion();
		$this->nodeFinder = new NodeFinder();
	}

	// -------------------------------------------------------------------------
	// Services Layer Boundaries
	// -------------------------------------------------------------------------

	/**
	 * WordPress/WooCommerce functions forbidden in src/Services.
	 *
	 * This mirrors ForbiddenServicesGlobalsRule but runs at test time
	 * as a backup guard against regressions.
	 *
	 * @var array<string, string>
	 */
	private const FORBIDDEN_FUNCTIONS = array(
		// WordPress capability/user functions.
		'current_user_can',
		'user_can',
		'wp_get_current_user',

		// WordPress options.
		'get_option',
		'update_option',
		'delete_option',
		'add_option',

		// WordPress meta.
		'get_post_meta',
		'update_post_meta',
		'delete_post_meta',
		'add_post_meta',
		'get_user_meta',
		'update_user_meta',
		'delete_user_meta',

		// WordPress transients/cache.
		'get_transient',
		'set_transient',
		'delete_transient',
		'wp_cache_get',
		'wp_cache_set',
		'wp_cache_delete',

		// WordPress hooks.
		'apply_filters',
		'do_action',
		'add_filter',
		'add_action',

		// WooCommerce order functions.
		'wc_get_order',
		'wc_get_orders',
		'wc_create_order',

		// WooCommerce product functions.
		'wc_get_product',
		'wc_get_products',
		'wc_get_product_id_by_sku',
		'wc_update_product_stock',

		// WooCommerce refund functions.
		'wc_create_refund',

		// WooCommerce status functions.
		'wc_get_order_statuses',

		// WooCommerce price functions.
		'wc_price',
		'wc_format_decimal',
		'wc_get_price_decimals',
		'get_woocommerce_currency',

		// WordPress timezone/time.
		'wp_timezone',
		'wp_timezone_string',
		'current_time',
		'wp_date',
	);

	/**
	 * Test that Services do not call forbidden WordPress/WooCommerce functions.
	 *
	 * This test scans all PHP files in src/Services and checks for calls to
	 * forbidden functions. Services should use injected interfaces instead.
	 *
	 * @dataProvider servicesFilesProvider
	 */
	public function test_services_do_not_call_forbidden_functions( string $filePath ): void {
		$code = file_get_contents( $filePath );
		$this->assertNotFalse( $code, "Failed to read file: {$filePath}" );

		try {
			$ast = $this->parser->parse( $code );
		} catch ( \Throwable $e ) {
			$this->fail( "Failed to parse file {$filePath}: " . $e->getMessage() );
		}

		$violations = array();

		// Find all function calls.
		$funcCalls = $this->nodeFinder->findInstanceOf( $ast, Node\Expr\FuncCall::class );
		foreach ( $funcCalls as $call ) {
			if ( ! $call->name instanceof Node\Name ) {
				continue;
			}

			$funcName = $call->name->toString();
			if ( in_array( $funcName, self::FORBIDDEN_FUNCTIONS, true ) ) {
				$violations[] = sprintf(
					'%s() called at line %d',
					$funcName,
					$call->getLine()
				);
			}
		}

		$this->assertEmpty(
			$violations,
			sprintf(
				"Forbidden function calls in %s:\n- %s\n\nServices must use injected interfaces instead of WordPress/WooCommerce globals.",
				basename( $filePath ),
				implode( "\n- ", $violations )
			)
		);
	}

	/**
	 * Data provider for Services files.
	 *
	 * @return array<string, array{string}>
	 */
	public static function servicesFilesProvider(): array {
		$srcPath = dirname( __DIR__, 3 ) . '/src/Services';
		$files   = self::getPhpFilesRecursive( $srcPath );
		$data    = array();

		foreach ( $files as $file ) {
			$name          = str_replace( $srcPath . '/', '', $file );
			$data[ $name ] = array( $file );
		}

		return $data;
	}

	/**
	 * Test that Services only depend on interfaces in their constructors.
	 *
	 * Services should depend on contracts (interfaces) not concrete implementations.
	 * This ensures loose coupling and testability.
	 *
	 * @dataProvider serviceClassesProvider
	 */
	public function test_services_depend_only_on_interfaces( string $className ): void {
		if ( ! class_exists( $className ) ) {
			$this->markTestSkipped( "Class {$className} does not exist" );
		}

		$reflection  = new ReflectionClass( $className );
		$constructor = $reflection->getConstructor();

		if ( ! $constructor ) {
			// No constructor means no dependencies to check.
			$this->assertTrue( true );
			return;
		}

		$violations = array();

		foreach ( $constructor->getParameters() as $param ) {
			$type = $param->getType();

			// Skip parameters without type hints or with union/intersection types.
			if ( ! $type instanceof ReflectionNamedType ) {
				continue;
			}

			$typeName = $type->getName();

			// Skip built-in types.
			if ( $type->isBuiltin() ) {
				continue;
			}

			// Check if the type is an interface.
			if ( ! interface_exists( $typeName ) && ! $this->isAllowedServiceDependency( $typeName ) ) {
				$violations[] = sprintf(
					'Parameter $%s depends on concrete class %s instead of an interface',
					$param->getName(),
					$typeName
				);
			}
		}

		$this->assertEmpty(
			$violations,
			sprintf(
				"Service %s has concrete dependencies:\n- %s\n\nServices should depend on interfaces from src/Contracts.",
				$className,
				implode( "\n- ", $violations )
			)
		);
	}

	/**
	 * Check if a dependency type is allowed (some concrete types are acceptable).
	 *
	 * @param string $typeName Type name.
	 * @return bool Whether the type is allowed.
	 */
	private function isAllowedServiceDependency( string $typeName ): bool {
		// Allow DTOs, value objects, exceptions, and certain internal types.
		$allowedPatterns = array(
			'\\DTO\\',
			'\\ValueObject\\',
			'\\Exception',
			// Allow internal OrderSearch sub-services to compose each other.
			'\\Services\\OrderSearch\\',
			// Allow PHP built-in classes.
			'DateTimeZone',
			'DateTime',
			'DateTimeImmutable',
			// Allow SettingsManager as configuration (should eventually have interface).
			'\\Plugin\\SettingsManager',
		);

		foreach ( $allowedPatterns as $pattern ) {
			if ( str_contains( $typeName, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Data provider for Service classes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function serviceClassesProvider(): array {
		$srcPath = dirname( __DIR__, 3 ) . '/src/Services';
		$files   = self::getPhpFilesRecursive( $srcPath );
		$data    = array();

		foreach ( $files as $file ) {
			$className = self::fileToClassName( $file );
			if ( $className ) {
				$shortName            = str_replace( 'AgentWP\\Services\\', '', $className );
				$data[ $shortName ] = array( $className );
			}
		}

		return $data;
	}

	// -------------------------------------------------------------------------
	// Controller Layer Boundaries
	// -------------------------------------------------------------------------

	/**
	 * Test that REST controllers extend the base RestController.
	 *
	 * All REST controllers must extend RestController to get the resolve()
	 * and resolveRequired() methods for container-based dependency injection.
	 *
	 * This test uses AST analysis instead of reflection because WordPress
	 * classes like WP_REST_Controller are not available in unit tests.
	 *
	 * @dataProvider restControllerFilesProvider
	 */
	public function test_rest_controllers_extend_base_controller( string $filePath ): void {
		$code = file_get_contents( $filePath );
		$this->assertNotFalse( $code, "Failed to read file: {$filePath}" );

		try {
			$ast = $this->parser->parse( $code );
		} catch ( \Throwable $e ) {
			$this->fail( "Failed to parse file {$filePath}: " . $e->getMessage() );
		}

		$classes = $this->nodeFinder->findInstanceOf( $ast, Node\Stmt\Class_::class );

		foreach ( $classes as $class ) {
			if ( ! $class->extends ) {
				continue;
			}

			$extendsName = $class->extends->toString();

			// Controllers should extend RestController.
			$this->assertTrue(
				str_ends_with( $extendsName, 'RestController' ) ||
				str_ends_with( $extendsName, 'WP_REST_Controller' ),
				sprintf(
					'REST controller %s must extend RestController to use container resolution (found: %s)',
					$class->name->toString(),
					$extendsName
				)
			);
		}
	}

	/**
	 * Test that REST controllers do not directly instantiate services.
	 *
	 * Controllers should resolve services via the container using resolve()
	 * or resolveRequired(), never by direct instantiation with `new`.
	 *
	 * @dataProvider restControllerFilesProvider
	 */
	public function test_controllers_do_not_directly_instantiate_services( string $filePath ): void {
		$code = file_get_contents( $filePath );
		$this->assertNotFalse( $code, "Failed to read file: {$filePath}" );

		try {
			$ast = $this->parser->parse( $code );
		} catch ( \Throwable $e ) {
			$this->fail( "Failed to parse file {$filePath}: " . $e->getMessage() );
		}

		$violations = array();

		// Find all `new` expressions.
		$newExprs = $this->nodeFinder->findInstanceOf( $ast, Node\Expr\New_::class );
		foreach ( $newExprs as $new ) {
			if ( ! $new->class instanceof Node\Name ) {
				continue;
			}

			$className = $new->class->toString();

			// Check if it's instantiating a service.
			if ( $this->isServiceClass( $className ) ) {
				$violations[] = sprintf(
					'Direct instantiation of %s at line %d',
					$className,
					$new->getLine()
				);
			}
		}

		$this->assertEmpty(
			$violations,
			sprintf(
				"Controller %s directly instantiates services:\n- %s\n\nUse \$this->resolve() or \$this->resolveRequired() instead.",
				basename( $filePath ),
				implode( "\n- ", $violations )
			)
		);
	}

	/**
	 * Check if a class name appears to be a service class.
	 *
	 * @param string $className Class name.
	 * @return bool Whether it looks like a service.
	 */
	private function isServiceClass( string $className ): bool {
		$servicePatterns = array(
			'Service',
			'Handler',
			'Engine',
			'Registry',
			'Manager',
			'Gateway',
			'Repository',
		);

		foreach ( $servicePatterns as $pattern ) {
			if ( str_contains( $className, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Data provider for REST controller files.
	 *
	 * @return array<string, array{string}>
	 */
	public static function restControllerFilesProvider(): array {
		$srcPath = dirname( __DIR__, 3 ) . '/src/Rest';
		$files   = self::getPhpFilesRecursive( $srcPath );
		$data    = array();

		foreach ( $files as $file ) {
			$name          = basename( $file );
			$data[ $name ] = array( $file );
		}

		return $data;
	}

	// -------------------------------------------------------------------------
	// Infrastructure Layer Boundaries
	// -------------------------------------------------------------------------

	/**
	 * Test that Infrastructure classes implement their declared interfaces.
	 *
	 * Gateway and repository classes in Infrastructure must implement the
	 * corresponding interface from Contracts.
	 *
	 * @dataProvider infrastructureClassesProvider
	 */
	public function test_infrastructure_classes_implement_contracts( string $className ): void {
		if ( ! class_exists( $className ) ) {
			$this->markTestSkipped( "Class {$className} does not exist" );
		}

		$reflection = new ReflectionClass( $className );

		// Skip abstract classes.
		if ( $reflection->isAbstract() ) {
			$this->assertTrue( true );
			return;
		}

		$interfaces = $reflection->getInterfaceNames();

		// Filter to only AgentWP interfaces.
		$agentWpInterfaces = array_filter(
			$interfaces,
			fn( $i ) => str_starts_with( $i, 'AgentWP\\' )
		);

		// Infrastructure classes that are adapters should implement at least one contract.
		$adapterSuffixes = array( 'Gateway', 'Repository', 'Cache', 'Handler', 'Storage' );
		$shortName       = $reflection->getShortName();

		$isAdapter = false;
		foreach ( $adapterSuffixes as $suffix ) {
			if ( str_ends_with( $shortName, $suffix ) ) {
				$isAdapter = true;
				break;
			}
		}

		if ( $isAdapter ) {
			$this->assertNotEmpty(
				$agentWpInterfaces,
				sprintf(
					'Infrastructure adapter %s should implement a contract interface from src/Contracts',
					$className
				)
			);
		} else {
			// Non-adapter infrastructure classes are acceptable without interfaces.
			$this->assertTrue( true );
		}
	}

	/**
	 * Data provider for Infrastructure classes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function infrastructureClassesProvider(): array {
		$srcPath = dirname( __DIR__, 3 ) . '/src/Infrastructure';
		$files   = self::getPhpFilesRecursive( $srcPath );
		$data    = array();

		foreach ( $files as $file ) {
			$className = self::fileToClassName( $file );
			if ( $className ) {
				$shortName          = basename( $file, '.php' );
				$data[ $shortName ] = array( $className );
			}
		}

		return $data;
	}

	// -------------------------------------------------------------------------
	// Intent Handler Boundaries
	// -------------------------------------------------------------------------

	/**
	 * Test that Intent handlers implement the Handler interface.
	 *
	 * All handlers in src/Intent/Handlers must implement the Handler interface
	 * to be usable by the HandlerRegistry.
	 *
	 * @dataProvider intentHandlerClassesProvider
	 */
	public function test_intent_handlers_implement_handler_interface( string $className ): void {
		if ( ! class_exists( $className ) ) {
			$this->markTestSkipped( "Class {$className} does not exist" );
		}

		$reflection = new ReflectionClass( $className );

		// Skip abstract classes.
		if ( $reflection->isAbstract() ) {
			$this->assertTrue( true );
			return;
		}

		$handlerInterface = 'AgentWP\\Intent\\Handler';

		$this->assertTrue(
			$reflection->implementsInterface( $handlerInterface ) ||
			$reflection->isSubclassOf( $handlerInterface ),
			sprintf(
				'Intent handler %s must implement %s',
				$className,
				$handlerInterface
			)
		);
	}

	/**
	 * Test that Intent handlers depend only on interfaces.
	 *
	 * Handlers should receive their dependencies via constructor injection
	 * using interface types, not concrete implementations.
	 *
	 * @dataProvider intentHandlerClassesProvider
	 */
	public function test_intent_handlers_depend_only_on_interfaces( string $className ): void {
		if ( ! class_exists( $className ) ) {
			$this->markTestSkipped( "Class {$className} does not exist" );
		}

		$reflection  = new ReflectionClass( $className );
		$constructor = $reflection->getConstructor();

		if ( ! $constructor ) {
			$this->assertTrue( true );
			return;
		}

		// Skip abstract classes.
		if ( $reflection->isAbstract() ) {
			$this->assertTrue( true );
			return;
		}

		$violations = array();

		foreach ( $constructor->getParameters() as $param ) {
			$type = $param->getType();

			if ( ! $type instanceof ReflectionNamedType ) {
				continue;
			}

			$typeName = $type->getName();

			if ( $type->isBuiltin() ) {
				continue;
			}

			// Allow interfaces and certain allowed types.
			if ( ! interface_exists( $typeName ) && ! $this->isAllowedHandlerDependency( $typeName ) ) {
				$violations[] = sprintf(
					'Parameter $%s depends on concrete class %s',
					$param->getName(),
					$typeName
				);
			}
		}

		$this->assertEmpty(
			$violations,
			sprintf(
				"Handler %s has concrete dependencies:\n- %s\n\nHandlers should depend on interfaces.",
				$className,
				implode( "\n- ", $violations )
			)
		);
	}

	/**
	 * Check if a dependency type is allowed for handlers.
	 *
	 * @param string $typeName Type name.
	 * @return bool Whether the type is allowed.
	 */
	private function isAllowedHandlerDependency( string $typeName ): bool {
		$allowedPatterns = array(
			'\\DTO\\',
			'\\ValueObject\\',
			'\\Exception',
			'\\Intent\\Intent',
			'FunctionRegistry',
			'ToolRegistry',
		);

		foreach ( $allowedPatterns as $pattern ) {
			if ( str_contains( $typeName, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Data provider for Intent handler classes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function intentHandlerClassesProvider(): array {
		$srcPath = dirname( __DIR__, 3 ) . '/src/Intent/Handlers';

		if ( ! is_dir( $srcPath ) ) {
			return array();
		}

		$files = self::getPhpFilesRecursive( $srcPath );
		$data  = array();

		foreach ( $files as $file ) {
			$className = self::fileToClassName( $file );
			if ( $className ) {
				$shortName          = basename( $file, '.php' );
				$data[ $shortName ] = array( $className );
			}
		}

		return $data;
	}

	// -------------------------------------------------------------------------
	// Provider Layer Boundaries
	// -------------------------------------------------------------------------

	/**
	 * Test that Providers do not call forbidden functions.
	 *
	 * Providers register services but should not call WordPress/WooCommerce
	 * functions at registration time. Those calls should be deferred to
	 * the infrastructure layer.
	 *
	 * @dataProvider providerFilesProvider
	 */
	public function test_providers_do_not_call_forbidden_functions_at_registration( string $filePath ): void {
		$code = file_get_contents( $filePath );
		$this->assertNotFalse( $code, "Failed to read file: {$filePath}" );

		try {
			$ast = $this->parser->parse( $code );
		} catch ( \Throwable $e ) {
			$this->fail( "Failed to parse file {$filePath}: " . $e->getMessage() );
		}

		// Only check within the register() method.
		$violations = array();

		$classes = $this->nodeFinder->findInstanceOf( $ast, Node\Stmt\Class_::class );
		foreach ( $classes as $class ) {
			foreach ( $class->getMethods() as $method ) {
				if ( 'register' !== $method->name->toString() ) {
					continue;
				}

				$funcCalls = $this->nodeFinder->findInstanceOf(
					$method->stmts ?? array(),
					Node\Expr\FuncCall::class
				);

				foreach ( $funcCalls as $call ) {
					if ( ! $call->name instanceof Node\Name ) {
						continue;
					}

					$funcName = $call->name->toString();

					// Check for direct WP/WC function calls in register().
					// Allow closures to call them (deferred execution).
					if ( in_array( $funcName, self::FORBIDDEN_FUNCTIONS, true ) ) {
						$violations[] = sprintf(
							'%s() called at line %d in register()',
							$funcName,
							$call->getLine()
						);
					}
				}
			}
		}

		$this->assertEmpty(
			$violations,
			sprintf(
				"Provider %s calls forbidden functions during registration:\n- %s\n\nDefer these calls to the infrastructure layer.",
				basename( $filePath ),
				implode( "\n- ", $violations )
			)
		);
	}

	/**
	 * Data provider for Provider files.
	 *
	 * @return array<string, array{string}>
	 */
	public static function providerFilesProvider(): array {
		$srcPath = dirname( __DIR__, 3 ) . '/src/Providers';

		if ( ! is_dir( $srcPath ) ) {
			return array();
		}

		$files = self::getPhpFilesRecursive( $srcPath );
		$data  = array();

		foreach ( $files as $file ) {
			$name          = basename( $file );
			$data[ $name ] = array( $file );
		}

		return $data;
	}

	// -------------------------------------------------------------------------
	// Helper Methods
	// -------------------------------------------------------------------------

	/**
	 * Get all PHP files in a directory recursively.
	 *
	 * @param string $directory Directory path.
	 * @return array<string> File paths.
	 */
	private static function getPhpFilesRecursive( string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$files    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}

	/**
	 * Convert a file path to a class name.
	 *
	 * @param string $filePath File path.
	 * @return string|null Class name or null.
	 */
	private static function fileToClassName( string $filePath ): ?string {
		$srcPath = dirname( __DIR__, 3 ) . '/src';

		if ( ! str_starts_with( $filePath, $srcPath ) ) {
			return null;
		}

		$relativePath = substr( $filePath, strlen( $srcPath ) + 1 );
		$relativePath = str_replace( '.php', '', $relativePath );
		$relativePath = str_replace( '/', '\\', $relativePath );

		return 'AgentWP\\' . $relativePath;
	}
}
