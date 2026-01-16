<?php
/**
 * Handle order status intents.
 *
 * @package AgentWP\Intent\Handlers
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\OpenAIClient;
use AgentWP\AI\Response;
use AgentWP\AI\Functions\PrepareStatusUpdate;
use AgentWP\AI\Functions\PrepareBulkStatusUpdate;
use AgentWP\AI\Functions\ConfirmStatusUpdate;
use AgentWP\Contracts\ToolExecutorInterface;
use AgentWP\Intent\Intent;
use AgentWP\Plugin;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Services\OrderStatusService;

class OrderStatusHandler extends BaseHandler implements ToolExecutorInterface {
	/**
	 * @var OrderStatusService|null
	 */
	private $service;

	/**
	 * @var SettingsManager|null
	 */
	private $settings;

	/**
	 * Initialize order status intent handler.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct( Intent::ORDER_STATUS );
	}

	/**
	 * Get order status service (lazy-loaded).
	 *
	 * @return OrderStatusService
	 */
	protected function get_service() {
		if ( ! $this->service ) {
			$container = Plugin::container();
			if ( $container && $container->has( OrderStatusService::class ) ) {
				$this->service = $container->get( OrderStatusService::class );
			} else {
				$this->service = new OrderStatusService();
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
	 * Create OpenAI client.
	 *
	 * @param string $api_key API key.
	 * @return OpenAIClient
	 */
	protected function create_client( string $api_key ): OpenAIClient {
		return new OpenAIClient( $api_key );
	}

	/**
	 * @param array $context Context data.
	 * @return Response
	 */
	public function handle( array $context ): Response {
		$settings = $this->get_settings();
		$api_key  = $settings ? $settings->getApiKey() : '';

		if ( empty( $api_key ) ) {
			return Response::error( 'OpenAI API key is missing.', 401 );
		}

		$client = $this->create_client( $api_key );
		$tools  = array( new PrepareStatusUpdate(), new PrepareBulkStatusUpdate(), new ConfirmStatusUpdate() );

		$messages = array();
		$messages[] = array(
			'role'    => 'system',
			'content' => 'You are an expert WooCommerce assistant. You can check and update order statuses (single or bulk). Always prepare updates first.',
		);
		$messages[] = array(
			'role'    => 'user',
			'content' => isset( $context['input'] ) ? $context['input'] : 'Update order status',
		);

		for ( $i = 0; $i < 5; $i++ ) {
			$response = $client->chat( $messages, $tools );

			if ( ! $response->is_success() ) {
				return $response;
			}

			$data       = $response->get_data();
			$content    = isset( $data['content'] ) ? $data['content'] : '';
			$tool_calls = isset( $data['tool_calls'] ) ? $data['tool_calls'] : array();

			$assistant_msg = array(
				'role'    => 'assistant',
				'content' => $content,
			);
			if ( ! empty( $tool_calls ) ) {
				$assistant_msg['tool_calls'] = $tool_calls;
			}
			$messages[] = $assistant_msg;

			if ( empty( $tool_calls ) ) {
				return $this->build_response( $context, $content );
			}

			foreach ( $tool_calls as $call ) {
				$name      = isset( $call['function']['name'] ) ? $call['function']['name'] : '';
				$args_json = isset( $call['function']['arguments'] ) ? $call['function']['arguments'] : '{}';
				$args      = json_decode( $args_json, true );

				$result = $this->execute_tool( $name, $args );

				$messages[] = array(
					'role'         => 'tool',
					'tool_call_id' => $call['id'],
					'content'      => wp_json_encode( $result ),
				);
			}
		}

		return Response::error( 'I got stuck.', 500 );
	}

	/**
	 * Execute tool.
	 *
	 * @param string $name Tool name.
	 * @param array  $arguments Arguments.
	 * @return mixed
	 */
	public function execute_tool( string $name, array $arguments ) {
		$service = $this->get_service();

		switch ( $name ) {
			case 'prepare_status_update':
				$order_id = $arguments['order_id'] ?? 0;
				$status   = $arguments['new_status'] ?? '';
				$note     = $arguments['note'] ?? '';
				$notify   = $arguments['notify_customer'] ?? false;
				return $service->prepare_update( $order_id, $status, $note, $notify );

			case 'prepare_bulk_status_update':
				$order_ids = $arguments['order_ids'] ?? array();
				$status    = $arguments['new_status'] ?? '';
				$notify    = $arguments['notify_customer'] ?? false;
				return $service->prepare_bulk_update( $order_ids, $status, $notify );

			case 'confirm_status_update':
				$draft_id = $arguments['draft_id'] ?? '';
				return $service->confirm_update( $draft_id );

			default:
				return array( 'error' => "Unknown tool: {$name}" );
		}
	}
}
