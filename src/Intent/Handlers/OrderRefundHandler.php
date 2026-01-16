<?php
/**
 * Handle order refund intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\OpenAIClient;
use AgentWP\AI\Response;
use AgentWP\AI\Functions\PrepareRefund;
use AgentWP\AI\Functions\ConfirmRefund;
use AgentWP\Contracts\ToolExecutorInterface;
use AgentWP\Intent\Intent;
use AgentWP\Plugin;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Services\OrderRefundService;

class OrderRefundHandler extends BaseHandler implements ToolExecutorInterface {
	/**
	 * @var OrderRefundService|null
	 */
	private $service;

	/**
	 * @var SettingsManager|null
	 */
	private $settings;

	/**
	 * Initialize order refund intent handler.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct( Intent::ORDER_REFUND );
	}

	/**
	 * Get the refund service (lazy-loaded).
	 *
	 * @return OrderRefundService
	 */
	protected function get_service() {
		if ( ! $this->service ) {
			$container = Plugin::container();
			if ( $container && $container->has( OrderRefundService::class ) ) {
				$this->service = $container->get( OrderRefundService::class );
			} else {
				$this->service = new OrderRefundService();
			}
		}
		return $this->service;
	}

	/**
	 * Get settings manager (lazy-loaded).
	 *
	 * @return SettingsManager|null
	 */
	protected function get_settings() {
		if ( ! $this->settings ) {
			$container = Plugin::container();
			if ( $container && $container->has( SettingsManager::class ) ) {
				$this->settings = $container->get( SettingsManager::class );
			}
		}
		return $this->settings;
	}

	/**
	 * @param array $context Context data.
	 * @return Response
	 */
	public function handle( array $context ): Response {
		$settings = $this->get_settings();
		$api_key  = $settings ? $settings->getApiKey() : '';

		if ( empty( $api_key ) ) {
			return Response::error( 'OpenAI API key is missing. Please configure it in AgentWP settings.', 401 );
		}

		$client = $this->create_client( $api_key );
		$tools  = array( new PrepareRefund(), new ConfirmRefund() );
		
		$messages = array();
		
		// Optional: Add system prompt from context or defaults
		if ( ! empty( $context['system_prompt'] ) ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $context['system_prompt'],
			);
		} else {
			$messages[] = array(
				'role'    => 'system',
				'content' => 'You are an expert WooCommerce assistant. You can help process refunds. Always verify order details before confirming.',
			);
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => isset( $context['input'] ) ? $context['input'] : 'Refund order',
		);

		// Interaction loop (max 5 turns)
		for ( $i = 0; $i < 5; $i++ ) {
			$response = $client->chat( $messages, $tools );

			if ( ! $response->is_success() ) {
				return $response;
			}

			$data       = $response->get_data();
			$content    = isset( $data['content'] ) ? $data['content'] : '';
			$tool_calls = isset( $data['tool_calls'] ) ? $data['tool_calls'] : array();

			// Add assistant message to history
			$assistant_msg = array(
				'role'    => 'assistant',
				'content' => $content,
			);
			if ( ! empty( $tool_calls ) ) {
				$assistant_msg['tool_calls'] = $tool_calls;
			}
			$messages[] = $assistant_msg;

			// If no tool calls, we are done
			if ( empty( $tool_calls ) ) {
				return $this->build_response( $context, $content );
			}

			// Execute tools
			foreach ( $tool_calls as $call ) {
				$name = isset( $call['function']['name'] ) ? $call['function']['name'] : '';
				$args_json = isset( $call['function']['arguments'] ) ? $call['function']['arguments'] : '{}';
				$args = json_decode( $args_json, true );

				if ( ! is_array( $args ) ) {
					$args = array();
				}

				$result = $this->execute_tool( $name, $args );

				$messages[] = array(
					'role'         => 'tool',
					'tool_call_id' => $call['id'],
					'content'      => wp_json_encode( $result ),
				);
			}
		}

		return Response::error( 'I got stuck in a loop while processing your request. Please try again.', 500 );
	}

	/**
	 * Create OpenAI client.
	 *
	 * @param string $api_key API key.
	 * @return OpenAIClient
	 */
	protected function create_client( string $api_key ): OpenAIClient {
		return new OpenAIClient( $api_key );
	}

	/**
	 * Execute a named tool with arguments.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return mixed Tool execution result.
	 */
	public function execute_tool( string $name, array $arguments ) {
		$service = $this->get_service();

		switch ( $name ) {
			case 'prepare_refund':
				$order_id      = isset( $arguments['order_id'] ) ? (int) $arguments['order_id'] : 0;
				$amount        = isset( $arguments['amount'] ) ? $arguments['amount'] : null;
				$reason        = isset( $arguments['reason'] ) ? $arguments['reason'] : '';
				$restock_items = isset( $arguments['restock_items'] ) ? (bool) $arguments['restock_items'] : true;

				return $service->prepare_refund( $order_id, $amount, $reason, $restock_items );

			case 'confirm_refund':
				$draft_id = isset( $arguments['draft_id'] ) ? (string) $arguments['draft_id'] : '';
				return $service->confirm_refund( $draft_id );

			default:
				return array( 'error' => "Unknown tool: {$name}" );
		}
	}
}