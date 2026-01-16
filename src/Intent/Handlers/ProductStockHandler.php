<?php
/**
 * Handle product stock intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\OpenAIClient;
use AgentWP\AI\Response;
use AgentWP\AI\Functions\PrepareStockUpdate;
use AgentWP\AI\Functions\ConfirmStockUpdate;
use AgentWP\AI\Functions\SearchProduct;
use AgentWP\Contracts\ToolExecutorInterface;
use AgentWP\Intent\Intent;
use AgentWP\Plugin;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Services\ProductStockService;

class ProductStockHandler extends BaseHandler implements ToolExecutorInterface {
	/**
	 * @var ProductStockService|null
	 */
	private $service;

	/**
	 * @var SettingsManager|null
	 */
	private $settings;

	/**
	 * Initialize product stock intent handler.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct( Intent::PRODUCT_STOCK );
	}

	/**
	 * Get stock service (lazy-loaded).
	 *
	 * @return ProductStockService
	 */
	protected function get_service() {
		if ( ! $this->service ) {
			$container = Plugin::container();
			if ( $container && $container->has( ProductStockService::class ) ) {
				$this->service = $container->get( ProductStockService::class );
			} else {
				$this->service = new ProductStockService();
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
		$tools  = array( new SearchProduct(), new PrepareStockUpdate(), new ConfirmStockUpdate() );

		$messages = array();
		$messages[] = array(
			'role'    => 'system',
			'content' => 'You are an expert inventory manager. Help the user check stock or update it. Always search for products first to get IDs.',
		);
		$messages[] = array(
			'role'    => 'user',
			'content' => isset( $context['input'] ) ? $context['input'] : 'Check stock',
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
		$service = $this->get_service();

		switch ( $name ) {
			case 'search_product':
				$query = $arguments['query'] ?? '';
				return $service->search_products( $query );

			case 'prepare_stock_update':
				$product_id = $arguments['product_id'] ?? 0;
				$quantity   = $arguments['quantity'] ?? 0;
				$operation  = $arguments['operation'] ?? 'set';
				return $service->prepare_update( $product_id, $quantity, $operation );

			case 'confirm_stock_update':
				$draft_id = $arguments['draft_id'] ?? '';
				return $service->confirm_update( $draft_id );

			default:
				return array( 'error' => "Unknown tool: {$name}" );
		}
	}
}