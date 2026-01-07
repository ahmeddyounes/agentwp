<?php
/**
 * Handle customer lookup intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Handlers\CustomerHandler;
use AgentWP\Intent\Intent;

class CustomerLookupHandler extends BaseHandler {
	/**
	 * Initialize customer lookup intent handler.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct( Intent::CUSTOMER_LOOKUP );
	}

	/**
	 * @param array $context Context data.
	 * @return Response
	 */
	public function handle( array $context ): Response {
		$query = isset( $context['input'] ) ? (string) $context['input'] : '';
		$query = trim( $query );

		if ( '' === $query ) {
			$message = 'I can look up customer profiles. Share an email or customer ID.';
			return $this->build_response( $context, $message );
		}

		$email       = $this->extract_email( $query );
		$customer_id = $this->extract_customer_id( $query );
		if ( '' !== $email && ! ctype_digit( $query ) ) {
			$customer_id = 0;
		}

		if ( '' === $email && 0 === $customer_id ) {
			$message = 'Share an email address or customer ID to look up their profile.';
			return $this->build_response( $context, $message );
		}

		$customer = new CustomerHandler();
		$result   = $customer->handle(
			array(
				'email'       => $email,
				'customer_id' => $customer_id,
			)
		);

		if ( ! $result->is_success() ) {
			return $result;
		}

		$data         = $result->get_data();
		$total_orders = isset( $data['total_orders'] ) ? intval( $data['total_orders'] ) : 0;
		$message      = 0 === $total_orders
			? 'I could not find any orders for that customer.'
			: sprintf( 'Found %d order%s for that customer.', $total_orders, 1 === $total_orders ? '' : 's' );

		return $this->build_response( $context, $message, $data );
	}

	/**
	 * @param string $query Raw query.
	 * @return string
	 */
	private function extract_email( $query ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return '';
		}

		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $query, $matches ) ) {
			return sanitize_email( $matches[0] );
		}

		return '';
	}

	/**
	 * @param string $query Raw query.
	 * @return int
	 */
	private function extract_customer_id( $query ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return 0;
		}

		if ( ctype_digit( $query ) ) {
			return absint( $query );
		}

		if ( preg_match( '/\b(\d+)\b/', $query, $matches ) ) {
			return absint( $matches[1] );
		}

		return 0;
	}
}
