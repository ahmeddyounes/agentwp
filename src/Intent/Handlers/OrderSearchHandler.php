<?php
/**
 * Handle order search intents.
 *
 * @package AgentWP\Intent\Handlers
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\OpenAIClient;
use AgentWP\AI\Response;
use AgentWP\AI\Functions\SearchOrders;
use AgentWP\Contracts\ToolExecutorInterface;
use AgentWP\Intent\Intent;
use AgentWP\Plugin;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Services\OrderSearchService;

class OrderSearchHandler extends BaseHandler implements ToolExecutorInterface {
	/**
	 * @var OrderSearchService|null
	 */
	private $service;

	/**
	 * @var SettingsManager|null
	 */
	private $settings;

	/**
	 * Initialize order search intent handler.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct( Intent::ORDER_SEARCH );
	}

	/**
	 * Get search service (lazy-loaded).
	 *
	 * @return OrderSearchService
	 */
	protected function get_service() {
		if ( ! $this->service ) {
			$container = Plugin::container();
			if ( $container && $container->has( OrderSearchService::class ) ) {
				$this->service = $container->get( OrderSearchService::class );
			} else {
				$this->service = new OrderSearchService();
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
		$tools  = array( new SearchOrders() );

		$messages = array();
		$messages[] = array(
			'role'    => 'system',
			'content' => 'You are an order search assistant. Find orders based on user criteria (date, status, customer).',
		);
		$messages[] = array(
			'role'    => 'user',
			'content' => isset( $context['input'] ) ? $context['input'] : 'Find orders',
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

		return Response::error( 'Loop limit exceeded.', 500 );
	}

	/**
	 * Execute tool.
	 *
	 * @param string $name Tool name.
	 * @param array  $arguments Arguments.
	 * @return mixed
	 */
	public function execute_tool( string $name, array $arguments ) {
		if ( 'search_orders' === $name ) {
			$service = $this->get_service();
			
			// Map arguments to service format
			$search_args = array(
				'query'    => $arguments['query'] ?? '',
				'status'   => $arguments['status'] ?? '',
				'limit'    => $arguments['limit'] ?? 10,
				'email'    => $arguments['email'] ?? '',
				'order_id' => $arguments['order_id'] ?? 0,
			);

			if ( isset( $arguments['date_range'] ) ) {
				$search_args['date_range'] = $arguments['date_range'];
			}

			$result = $service->handle( $search_args );
			
			// $result is now an array, not a Response object
			return $result;
		}

		return array( 'error' => "Unknown tool: {$name}" );
	}
}
