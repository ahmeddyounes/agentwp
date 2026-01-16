<?php
/**
 * Handle email draft intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\OpenAIClient;
use AgentWP\AI\Response;
use AgentWP\AI\Functions\DraftEmail;
use AgentWP\Contracts\ToolExecutorInterface;
use AgentWP\Contracts\OrderRepositoryInterface;
use AgentWP\Intent\Intent;
use AgentWP\Plugin;
use AgentWP\Plugin\SettingsManager;

class EmailDraftHandler extends BaseHandler implements ToolExecutorInterface {
	/**
	 * @var OrderRepositoryInterface|null
	 */
	private $repository;

	/**
	 * @var SettingsManager|null
	 */
	private $settings;

	/**
	 * Initialize email draft intent handler.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct( Intent::EMAIL_DRAFT );
	}

	/**
	 * Get order repository (lazy-loaded).
	 *
	 * @return OrderRepositoryInterface|null
	 */
	protected function get_repository() {
		if ( ! $this->repository ) {
			$container = Plugin::container();
			if ( $container && $container->has( OrderRepositoryInterface::class ) ) {
				$this->repository = $container->get( OrderRepositoryInterface::class );
			}
		}
		return $this->repository;
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
			return Response::error( 'OpenAI API key is missing. Please configure it in AgentWP settings.', 401 );
		}

		$client = $this->create_client( $api_key );
		$tools  = array( new DraftEmail() );

		$messages = array();

		// System Prompt
		$messages[] = array(
			'role'    => 'system',
			'content' => 'You are an expert customer support agent. Use the draft_email tool to get order context, then write the email content for the user to review. Do not send it.',
		);

		$messages[] = array(
			'role'    => 'user',
			'content' => isset( $context['input'] ) ? $context['input'] : 'Draft email',
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
				$name      = isset( $call['function']['name'] ) ? $call['function']['name'] : '';
				$args_json = isset( $call['function']['arguments'] ) ? $call['function']['arguments'] : '{}';
				$args      = json_decode( $args_json, true );

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
	 * Execute a named tool with arguments.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return mixed Tool execution result.
	 */
	public function execute_tool( string $name, array $arguments ) {
		if ( 'draft_email' === $name ) {
			$order_id = isset( $arguments['order_id'] ) ? (int) $arguments['order_id'] : 0;
			$repo     = $this->get_repository();

			if ( ! $repo ) {
				return array( 'error' => 'WooCommerce is not available to fetch order details.' );
			}

			$order = $repo->find( $order_id );
			if ( ! $order ) {
				return array( 'error' => "Order #{$order_id} not found." );
			}

			// Return simplified order context
			$items = array();
			if ( is_array( $order->items ) ) {
				foreach ( $order->items as $item ) {
					$name = isset( $item['name'] ) ? $item['name'] : 'Item';
					$qty  = isset( $item['quantity'] ) ? $item['quantity'] : 1;
					$items[] = $name . ' x' . $qty;
				}
			}

			return array(
				'order_id' => $order->id,
				'customer' => $order->customerName,
				'total'    => $order->total,
				'status'   => $order->status,
				'items'    => $items,
				'date'     => $order->dateCreated ? $order->dateCreated->format( 'Y-m-d' ) : '',
			);
		}

		return array( 'error' => "Unknown tool: {$name}" );
	}
}