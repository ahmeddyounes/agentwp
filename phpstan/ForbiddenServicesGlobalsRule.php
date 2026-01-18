<?php
/**
 * PHPStan rule to forbid WordPress/WooCommerce global function calls in src/Services.
 *
 * This rule enforces module boundaries by preventing Services from directly calling
 * WordPress or WooCommerce globals. Services should use injected gateways/interfaces
 * from src/Infrastructure and src/Security/Policy instead.
 *
 * @package AgentWP\PHPStan
 */

declare( strict_types=1 );

namespace AgentWP\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<FuncCall>
 */
class ForbiddenServicesGlobalsRule implements Rule {

	/**
	 * WordPress/WooCommerce functions forbidden in src/Services.
	 *
	 * These functions have wrappers in src/Infrastructure or src/Security/Policy.
	 *
	 * @var array<string, string>
	 */
	private const FORBIDDEN_FUNCTIONS = array(
		// WordPress capability/user functions - use PolicyInterface.
		'current_user_can'       => 'Use PolicyInterface from dependency injection.',
		'user_can'               => 'Use PolicyInterface from dependency injection.',
		'wp_get_current_user'    => 'Use WPFunctions->getCurrentUser() or inject user context.',

		// WordPress options - use OptionsInterface.
		'get_option'             => 'Use OptionsInterface from dependency injection.',
		'update_option'          => 'Use OptionsInterface from dependency injection.',
		'delete_option'          => 'Use OptionsInterface from dependency injection.',
		'add_option'             => 'Use OptionsInterface from dependency injection.',

		// WordPress meta - use repository interfaces.
		'get_post_meta'          => 'Use a repository interface from dependency injection.',
		'update_post_meta'       => 'Use a repository interface from dependency injection.',
		'delete_post_meta'       => 'Use a repository interface from dependency injection.',
		'add_post_meta'          => 'Use a repository interface from dependency injection.',
		'get_user_meta'          => 'Use a gateway interface from dependency injection.',
		'update_user_meta'       => 'Use a gateway interface from dependency injection.',
		'delete_user_meta'       => 'Use a gateway interface from dependency injection.',

		// WordPress transients/cache - use CacheInterface or TransientCacheInterface.
		'get_transient'          => 'Use TransientCacheInterface from dependency injection.',
		'set_transient'          => 'Use TransientCacheInterface from dependency injection.',
		'delete_transient'       => 'Use TransientCacheInterface from dependency injection.',
		'wp_cache_get'           => 'Use CacheInterface from dependency injection.',
		'wp_cache_set'           => 'Use CacheInterface from dependency injection.',
		'wp_cache_delete'        => 'Use CacheInterface from dependency injection.',

		// WordPress hooks - use WPFunctions wrapper if needed.
		'apply_filters'          => 'Use WPFunctions->applyFilters() or avoid hooks in services.',
		'do_action'              => 'Use WPFunctions->doAction() or avoid hooks in services.',
		'add_filter'             => 'Hooks should be registered in Providers, not Services.',
		'add_action'             => 'Hooks should be registered in Providers, not Services.',

		// WooCommerce order functions - use WooCommerceOrderGatewayInterface.
		'wc_get_order'           => 'Use WooCommerceOrderGatewayInterface or OrderRepositoryInterface.',
		'wc_get_orders'          => 'Use WooCommerceOrderGatewayInterface or OrderRepositoryInterface.',
		'wc_create_order'        => 'Use WooCommerceOrderGatewayInterface.',

		// WooCommerce product functions - use WooCommerceStockGatewayInterface.
		'wc_get_product'         => 'Use WooCommerceStockGatewayInterface.',
		'wc_get_products'        => 'Use WooCommerceStockGatewayInterface.',
		'wc_get_product_id_by_sku' => 'Use WooCommerceStockGatewayInterface.',
		'wc_update_product_stock' => 'Use WooCommerceStockGatewayInterface.',

		// WooCommerce refund functions - use WooCommerceRefundGatewayInterface.
		'wc_create_refund'       => 'Use WooCommerceRefundGatewayInterface.',

		// WooCommerce status functions - use WooCommerceConfigGatewayInterface.
		'wc_get_order_statuses'  => 'Use WooCommerceOrderGatewayInterface or WooCommerceConfigGatewayInterface.',

		// WooCommerce price functions - use WooCommercePriceFormatterInterface.
		'wc_price'               => 'Use WooCommercePriceFormatterInterface.',
		'wc_format_decimal'      => 'Use WooCommercePriceFormatterInterface.',
		'wc_get_price_decimals'  => 'Use WooCommercePriceFormatterInterface.',
		'get_woocommerce_currency' => 'Use WooCommerceConfigGatewayInterface.',

		// WooCommerce timezone - use ClockInterface.
		'wp_timezone'            => 'Use ClockInterface->now()->getTimezone().',
		'wp_timezone_string'     => 'Use ClockInterface->now()->getTimezone()->getName().',

		// WordPress timezone/time - use ClockInterface.
		'current_time'           => 'Use ClockInterface.',
		'wp_date'                => 'Use ClockInterface.',
	);

	/**
	 * Path pattern for Services directory.
	 *
	 * @var string
	 */
	private const SERVICES_PATH_PATTERN = '#[/\\\\]src[/\\\\]Services[/\\\\]#';

	/**
	 * {@inheritDoc}
	 */
	public function getNodeType(): string {
		return FuncCall::class;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param FuncCall $node The AST node.
	 * @param Scope    $scope The analysis scope.
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	public function processNode( Node $node, Scope $scope ): array {
		// Only check files in src/Services.
		$file = $scope->getFile();
		if ( ! preg_match( self::SERVICES_PATH_PATTERN, $file ) ) {
			return array();
		}

		// Get function name.
		if ( ! $node->name instanceof Name ) {
			return array();
		}

		$functionName = $node->name->toString();

		// Check if function is forbidden.
		if ( ! isset( self::FORBIDDEN_FUNCTIONS[ $functionName ] ) ) {
			return array();
		}

		$suggestion = self::FORBIDDEN_FUNCTIONS[ $functionName ];

		return array(
			RuleErrorBuilder::message(
				sprintf(
					'Function %s() is forbidden in src/Services to enforce module boundaries. %s',
					$functionName,
					$suggestion
				)
			)->identifier( 'agentwp.forbiddenServicesGlobal' )->build(),
		);
	}
}
