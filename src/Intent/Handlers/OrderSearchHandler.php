<?php
/**
 * Handle order search intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Handlers\OrderSearchHandler as OrderSearchService;
use AgentWP\Intent\Intent;

class OrderSearchHandler extends BaseHandler {
	/**
	 * Initialize order search intent handler.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct( Intent::ORDER_SEARCH );
	}

	/**
	 * @param array $context Context data.
	 * @return Response
	 */
	public function handle( array $context ): Response {
		$query = isset( $context['input'] ) ? (string) $context['input'] : '';
		$query = trim( $query );

		$search = new OrderSearchService();
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
			? 'I could not find any orders that match.'
			: sprintf( 'Found %d order%s matching your request.', $count, 1 === $count ? '' : 's' );

		return $this->build_response( $context, $message, $data );
	}
}
