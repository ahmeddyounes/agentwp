<?php
/**
 * Executable tool for searching products.
 *
 * @package AgentWP\Intent\Tools
 */

namespace AgentWP\Intent\Tools;

use AgentWP\Contracts\ExecutableToolInterface;
use AgentWP\Contracts\ProductStockServiceInterface;

/**
 * Executes product search operations.
 *
 * Calls the ProductStockService and returns a stable result payload
 * suitable for AI consumption.
 */
class SearchProductTool implements ExecutableToolInterface {

	/**
	 * @var ProductStockServiceInterface
	 */
	private ProductStockServiceInterface $service;

	/**
	 * Initialize the tool.
	 *
	 * @param ProductStockServiceInterface $service Product stock service.
	 */
	public function __construct( ProductStockServiceInterface $service ) {
		$this->service = $service;
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'search_product';
	}

	/**
	 * Execute the product search.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Search results.
	 */
	public function execute( array $arguments ): array {
		// Use 'query' if provided, fall back to 'sku' for exact SKU searches.
		$query = isset( $arguments['query'] ) ? (string) $arguments['query'] : '';
		if ( '' === $query && isset( $arguments['sku'] ) ) {
			$query = (string) $arguments['sku'];
		}

		if ( '' === $query ) {
			return array(
				'success'  => false,
				'error'    => 'A search query or SKU is required.',
				'products' => array(),
			);
		}

		$products = $this->service->search_products( $query );

		return array(
			'success'  => true,
			'products' => $products,
			'count'    => count( $products ),
			'query'    => $query,
		);
	}
}
