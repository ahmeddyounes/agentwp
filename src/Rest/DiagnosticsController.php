<?php
/**
 * Diagnostics REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\Rest;

use AgentWP\Config\AgentWPConfig;
use AgentWP\Contracts\RateLimiterInterface;
use AgentWP\Plugin;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Search\Index;
use WP_REST_Request;
use WP_REST_Server;

class DiagnosticsController extends RestController {
	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/diagnostics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_diagnostics' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Return diagnostics snapshot.
	 *
	 * @openapi GET /agentwp/v1/diagnostics
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return \WP_REST_Response
	 */
	public function get_diagnostics( $request ) {
		unset( $request );

		$user_id = (int) get_current_user_id();

		return $this->response_success(
			array(
				'health'       => $this->build_health_payload(),
				'rest_logs'    => $this->get_rest_logs( $user_id ),
				'rate_limit'   => $this->get_rate_limit_status( $user_id ),
				'config'       => $this->get_config_flags(),
				'search_index' => $this->get_search_index_state(),
			)
		);
	}

	/**
	 * Build the health payload (matches /health endpoint).
	 *
	 * @return array<string, mixed>
	 */
	private function build_health_payload(): array {
		return array(
			'status'    => 'ok',
			'time'      => gmdate( 'c' ),
			'timestamp' => (int) ( time() * 1000 ),
			'version'   => defined( 'AGENTWP_VERSION' ) ? AGENTWP_VERSION : '',
		);
	}

	/**
	 * Get recent REST logs for the current user/guest.
	 *
	 * @param int $user_id Current user ID.
	 * @return array<string, mixed>
	 */
	private function get_rest_logs( int $user_id ): array {
		$log_limit = (int) AgentWPConfig::get( 'rest.log_limit', AgentWPConfig::REST_LOG_LIMIT );
		$key       = Plugin::TRANSIENT_PREFIX . AgentWPConfig::CACHE_PREFIX_REST_LOG . ( $user_id > 0 ? $user_id : 'guest' );
		$logs      = get_transient( $key );
		$logs      = is_array( $logs ) ? $logs : array();

		if ( $log_limit > 0 && count( $logs ) > $log_limit ) {
			$logs = array_slice( $logs, -1 * $log_limit );
		}

		return array(
			'limit'   => $log_limit,
			'total'   => count( $logs ),
			'entries' => array_values( $logs ),
		);
	}

	/**
	 * Get rate limit status for the current user.
	 *
	 * @param int $user_id Current user ID.
	 * @return array<string, mixed>
	 */
	private function get_rate_limit_status( int $user_id ): array {
		$rate_limiter = $this->resolve( RateLimiterInterface::class );

		if ( $user_id <= 0 || ! $rate_limiter instanceof RateLimiterInterface ) {
			return array(
				'enabled'     => false,
				'limit'       => null,
				'window'      => null,
				'remaining'   => null,
				'retry_after' => null,
			);
		}

		$limit  = method_exists( $rate_limiter, 'getLimit' )
			? (int) $rate_limiter->getLimit()
			: (int) AgentWPConfig::get( 'rate_limit.requests', AgentWPConfig::RATE_LIMIT_REQUESTS );
		$window = method_exists( $rate_limiter, 'getWindow' )
			? (int) $rate_limiter->getWindow()
			: (int) AgentWPConfig::get( 'rate_limit.window', AgentWPConfig::RATE_LIMIT_WINDOW );

		return array(
			'enabled'     => true,
			'limit'       => $limit,
			'window'      => $window,
			'remaining'   => $rate_limiter->getRemaining( $user_id ),
			'retry_after' => $rate_limiter->getRetryAfter( $user_id ),
		);
	}

	/**
	 * Get diagnostic config flags.
	 *
	 * @return array<string, mixed>
	 */
	private function get_config_flags(): array {
		$settings = $this->read_settings();

		return array(
			'demo_mode' => ! empty( $settings['demo_mode'] ),
			'model'     => isset( $settings['model'] ) ? (string) $settings['model'] : AgentWPConfig::OPENAI_DEFAULT_MODEL,
		);
	}

	/**
	 * Read stored settings with defaults via SettingsManager.
	 *
	 * @return array
	 */
	private function read_settings(): array {
		$settings_manager = $this->getSettingsManager();
		if ( $settings_manager ) {
			return $settings_manager->getAll();
		}

		return SettingsManager::getDefaults();
	}

	/**
	 * Get the SettingsManager service from the container.
	 *
	 * @return SettingsManager|null
	 */
	private function getSettingsManager(): ?SettingsManager {
		$manager = $this->resolve( SettingsManager::class );
		return $manager instanceof SettingsManager ? $manager : null;
	}

	/**
	 * Get search index state details.
	 *
	 * @return array<string, mixed>
	 */
	private function get_search_index_state(): array {
		$state = get_option( Index::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		foreach ( array( 'products', 'orders', 'customers' ) as $type ) {
			if ( ! isset( $state[ $type ] ) ) {
				$state[ $type ] = 0;
			}
		}

		$complete = array(
			'products'  => -1 === (int) $state['products'],
			'orders'    => -1 === (int) $state['orders'],
			'customers' => -1 === (int) $state['customers'],
		);

		return array(
			'version'          => (string) get_option( Index::VERSION_OPTION, '' ),
			'expected_version' => Index::VERSION,
			'state'            => $state,
			'complete'         => $complete,
		);
	}
}
