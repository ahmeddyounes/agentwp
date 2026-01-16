<?php
/**
 * Handle analytics query intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\OpenAIClient;
use AgentWP\AI\Response;
use AgentWP\AI\Functions\GetSalesReport;
use AgentWP\Contracts\ToolExecutorInterface;
use AgentWP\Intent\Intent;
use AgentWP\Plugin;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Services\AnalyticsService;
use DateTime;
use DateTimeZone;

class AnalyticsQueryHandler extends BaseHandler implements ToolExecutorInterface {
	/**
	 * @var AnalyticsService|null
	 */
	private $service;

	/**
	 * @var SettingsManager|null
	 */
	private $settings;

	/**
	 * Initialize analytics intent handler.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct( Intent::ANALYTICS_QUERY );
	}

	/**
	 * Get analytics service (lazy-loaded).
	 *
	 * @return AnalyticsService|null
	 */
	protected function get_service() {
		if ( ! $this->service ) {
			$container = Plugin::container();
			if ( $container && $container->has( AnalyticsService::class ) ) {
				$this->service = $container->get( AnalyticsService::class );
			} else {
				$this->service = new AnalyticsService();
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
			return Response::error( 'OpenAI API key is missing. Please configure it in AgentWP settings.', 401 );
		}

		$client = $this->create_client( $api_key );
		$tools  = array( new GetSalesReport() );

		$messages = array();

		// System Prompt
		$messages[] = array(
			'role'    => 'system',
			'content' => 'You are an expert data analyst. Use get_sales_report to fetch data, then summarize the key metrics (Sales, Orders, Refunds) for the user. Highlight trends if applicable.',
		);

		$messages[] = array(
			'role'    => 'user',
			'content' => isset( $context['input'] ) ? $context['input'] : 'Show analytics',
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
		if ( 'get_sales_report' === $name ) {
			$period = isset( $arguments['period'] ) ? $arguments['period'] : 'today';
			
			$tz = new DateTimeZone( 'UTC' );
			if ( function_exists( 'wp_timezone' ) ) {
				$tz = wp_timezone();
			}

			$now = new DateTime( 'now', $tz );
			$start = clone $now;
			$end   = clone $now;

			switch ( $period ) {
				case 'today':
					$start->setTime( 0, 0, 0 );
					$end->setTime( 23, 59, 59 );
					break;
				case 'yesterday':
					$start->modify( '-1 day' )->setTime( 0, 0, 0 );
					$end->modify( '-1 day' )->setTime( 23, 59, 59 );
					break;
				case 'this_week':
					$start->modify( 'monday this week' )->setTime( 0, 0, 0 );
					$end->setTime( 23, 59, 59 );
					break;
				case 'last_week':
					$start->modify( 'monday last week' )->setTime( 0, 0, 0 );
					$end->modify( 'sunday last week' )->setTime( 23, 59, 59 );
					break;
				case 'this_month':
					$start->modify( 'first day of this month' )->setTime( 0, 0, 0 );
					$end->modify( 'last day of this month' )->setTime( 23, 59, 59 );
					break;
				case 'last_month':
					$start->modify( 'first day of last month' )->setTime( 0, 0, 0 );
					$end->modify( 'last day of last month' )->setTime( 23, 59, 59 );
					break;
				case 'custom':
					try {
						if ( ! empty( $arguments['start_date'] ) ) {
							$start = new DateTime( $arguments['start_date'], $tz );
							$start->setTime( 0, 0, 0 );
						}
						if ( ! empty( $arguments['end_date'] ) ) {
							$end = new DateTime( $arguments['end_date'], $tz );
							$end->setTime( 23, 59, 59 );
						}
					} catch ( \Exception $e ) {
						// Fallback to today if date parsing fails
						$start->setTime( 0, 0, 0 );
						$end->setTime( 23, 59, 59 );
					}
					break;
			}

			$service = $this->get_service();
			$report  = $service->get_report( $start->format( 'Y-m-d H:i:s' ), $end->format( 'Y-m-d H:i:s' ) );
			
			// Format for AI
			return array(
				'period'      => $period,
				'start'       => $start->format( 'Y-m-d' ),
				'end'         => $end->format( 'Y-m-d' ),
				'total_sales' => $report['total_sales'],
				'orders'      => $report['order_count'],
				'refunds'     => $report['total_refunds'],
			);
		}

		return array( 'error' => "Unknown tool: {$name}" );
	}
}