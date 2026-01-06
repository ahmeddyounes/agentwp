<?php
/**
 * Handle product stock intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Intent\Intent;

class ProductStockHandler extends BaseHandler {
	public function __construct() {
		parent::__construct( Intent::PRODUCT_STOCK );
	}

	/**
	 * @param array $context Context data.
	 * @return Response
	 */
	public function handle( array $context ): Response {
		$message = 'I can check product stock. Share a product name or SKU.';
		return $this->build_response( $context, $message );
	}
}
