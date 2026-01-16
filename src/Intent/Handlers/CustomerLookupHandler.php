<?php
/**
 * Handle customer lookup intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\OpenAIClient;
use AgentWP\AI\Response;
use AgentWP\AI\Functions\GetCustomerProfile;
use AgentWP\Contracts\ToolExecutorInterface;
use AgentWP\Handlers\CustomerHandler;
use AgentWP\Intent\Intent;
use AgentWP\Plugin;
use AgentWP\Plugin\SettingsManager;

class CustomerLookupHandler extends BaseHandler implements ToolExecutorInterface {
	/**
	 * @var CustomerHandler|null
	 */
	private $handler;

	/**
	 * @var SettingsManager|null
	 */
	private $settings;

	/**
	 * Initialize customer lookup intent handler.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct( Intent::CUSTOMER_LOOKUP );
	}

	/**
	 * Get customer handler (lazy-loaded).
	 *
	 * @return CustomerHandler
	 */
	protected function get_handler() {
		if ( ! $this->handler ) {
			$container = Plugin::container();
			if ( $container && $container->has( CustomerHandler::class ) ) {
				$this->handler = $container->get( CustomerHandler::class );
			} else {
				$this->handler = new CustomerHandler();
			}
		}
		return $this->handler;
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
		$tools  = array( new GetCustomerProfile() );

		$messages = array();
		$messages[] = array(
			'role'    => 'system',
			'content' => 'You are a customer success manager. Look up customer profiles and summarize key metrics (LTV, last order, health).',
		);
		$messages[] = array(
			'role'    => 'user',
			'content' => isset( $context['input'] ) ? $context['input'] : 'Lookup customer',
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
		if ( 'get_customer_profile' === $name ) {
			$service = $this->get_handler();
			return $service->handle( $arguments );
		}

		return array( 'error' => "Unknown tool: {$name}" );
	}
}