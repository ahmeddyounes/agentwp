<?php
/**
 * Handle product stock intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Handlers\StockHandler;
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
		$query = isset( $context['input'] ) ? (string) $context['input'] : '';
		$query = trim( $query );

		if ( '' === $query ) {
			$message = 'I can check product stock. Share a product name or SKU.';
			return $this->build_response( $context, $message );
		}

		$search = new StockHandler();
		$result = $search->handle(
			array(
				'query' => $query,
			)
		);

		if ( ! $result->is_success() ) {
			return $result;
		}

		$data    = $result->get_data();
		$count   = isset( $data['count'] ) ? intval( $data['count'] ) : 0;
		$message = 0 === $count
			? 'I could not find any products that match.'
			: sprintf( 'Found %d product%s matching your request.', $count, 1 === $count ? '' : 's' );

		return $this->build_response( $context, $message, $data );
	}
}
