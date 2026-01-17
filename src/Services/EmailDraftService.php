<?php
/**
 * Email draft service.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Contracts\EmailDraftServiceInterface;
use AgentWP\Contracts\OrderRepositoryInterface;
use AgentWP\Contracts\PolicyInterface;
use AgentWP\DTO\ServiceResult;

/**
 * Service for email draft operations.
 */
class EmailDraftService implements EmailDraftServiceInterface {

	/**
	 * @var OrderRepositoryInterface|null
	 */
	private ?OrderRepositoryInterface $repository;

	/**
	 * @var PolicyInterface
	 */
	private PolicyInterface $policy;

	/**
	 * Constructor.
	 *
	 * @param PolicyInterface               $policy     Policy for capability checks.
	 * @param OrderRepositoryInterface|null $repository Order repository.
	 */
	public function __construct( PolicyInterface $policy, ?OrderRepositoryInterface $repository = null ) {
		$this->policy     = $policy;
		$this->repository = $repository;
	}

	/**
	 * Get order context for drafting an email.
	 *
	 * @param int $order_id Order ID.
	 * @return ServiceResult Result with order context data or error.
	 */
	public function get_order_context( int $order_id ): ServiceResult {
		if ( ! $this->policy->canDraftEmails() ) {
			return ServiceResult::permissionDenied();
		}

		if ( ! $this->repository ) {
			return ServiceResult::operationFailed( 'WooCommerce is not available to fetch order details.' );
		}

		if ( $order_id <= 0 ) {
			return ServiceResult::invalidInput( 'Invalid order ID.' );
		}

		$order = $this->repository->find( $order_id );
		if ( ! $order ) {
			return ServiceResult::notFound( 'Order', $order_id );
		}

		// Build simplified order context.
		$items = array();
		if ( is_array( $order->items ) ) {
			foreach ( $order->items as $item ) {
				$item_name = isset( $item['name'] ) ? $item['name'] : 'Item';
				$qty       = isset( $item['quantity'] ) ? $item['quantity'] : 1;
				$items[]   = $item_name . ' x' . $qty;
			}
		}

		$summary = sprintf(
			'Email draft for Order #%d (%s)',
			$order->id,
			$order->customerName
		);

		$context = array(
			'summary'   => $summary,
			'order_id'  => $order->id,
			'customer'  => $order->customerName,
			'total'     => $order->total,
			'currency'  => $order->currency ?? 'USD',
			'status'    => $order->status,
			'items'     => $items,
			'date'      => $order->dateCreated ? $order->dateCreated->format( 'Y-m-d' ) : '',
		);

		return ServiceResult::success(
			"Order context loaded for email draft.",
			array(
				'type'    => 'email',
				'context' => $context,
			)
		);
	}
}
