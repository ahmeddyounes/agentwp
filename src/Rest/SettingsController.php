<?php
/**
 * Settings REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\Rest;

use AgentWP\Rest\RestController;
use AgentWP\Config\AgentWPConfig;
use AgentWP\Contracts\AuditLoggerInterface;
use AgentWP\Contracts\CurrentUserContextInterface;
use AgentWP\Contracts\OpenAIKeyValidatorInterface;
use AgentWP\Contracts\UsageTrackerInterface;
use AgentWP\DTO\ApiKeyRequestDTO;
use AgentWP\DTO\SettingsUpdateDTO;
use AgentWP\DTO\UsageQueryDTO;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Security\ApiKeyStorage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class SettingsController extends RestController {

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/settings/api-key',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_api_key' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/usage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_usage' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Get settings payload.
	 *
	 * @openapi GET /agentwp/v1/settings
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return WP_REST_Response
	 */
	public function get_settings( $request ) {
		unset( $request );

		$storage = $this->getApiKeyStorage();
		if ( $storage ) {
			$storage->rotatePrimary();
		}

		$settings = $this->read_settings();
		$last4    = $storage ? $storage->getPrimaryLast4() : '';
		$has_key  = $storage ? $storage->hasPrimaryKey() : false;

		return $this->response_success(
			array(
				'settings'       => $settings,
				'api_key_last4'  => $last4,
				'has_api_key'    => $has_key,
				'api_key_status' => $has_key ? 'stored' : 'missing',
			)
		);
	}

	/**
	 * Update settings payload.
	 *
	 * @openapi POST /agentwp/v1/settings
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$dto = new SettingsUpdateDTO( $request );

		if ( ! $dto->isValid() ) {
			$error = $dto->getError();
			return $this->response_error(
				AgentWPConfig::ERROR_CODE_INVALID_REQUEST,
				$error ? $error->get_error_message() : __( 'Invalid request.', 'agentwp' ),
				400
			);
		}

		$settings = $this->read_settings();
		$updated  = $dto->applyTo( $settings );

		$settings_manager = $this->getSettingsManager();
		if ( $settings_manager ) {
			$settings_manager->update( $updated );
		} else {
			update_option( SettingsManager::OPTION_SETTINGS, $updated, false );
		}

		return $this->response_success(
			array(
				'updated'  => true,
				'settings' => $updated,
			)
		);
	}

	/**
	 * Validate and store API key.
	 *
	 * @openapi POST /agentwp/v1/settings/api-key
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return WP_REST_Response
	 */
	public function update_api_key( $request ) {
		$dto = new ApiKeyRequestDTO( $request );

		if ( ! $dto->isValid() ) {
			$error = $dto->getError();
			return $this->response_error(
				AgentWPConfig::ERROR_CODE_INVALID_REQUEST,
				$error ? $error->get_error_message() : __( 'Invalid request.', 'agentwp' ),
				400
			);
		}

		$storage = $this->getApiKeyStorage();
		if ( ! $storage ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_ENCRYPTION_FAILED, __( 'API key storage service unavailable.', 'agentwp' ), 500 );
		}

		if ( $dto->isEmpty() ) {
			$storage->deletePrimary();
			$this->auditApiKeyAction( 'deleted', '' );

			return $this->response_success(
				array(
					'stored' => false,
					'last4'  => '',
				)
			);
		}

		if ( ! $dto->hasValidFormat() ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_INVALID_KEY, __( 'API key format looks invalid.', 'agentwp' ), 400 );
		}

		$api_key   = $dto->getApiKey();
		$validator = $this->getOpenAIKeyValidator();
		if ( $validator ) {
			$openai_validation = $validator->validate( $api_key );
			if ( is_wp_error( $openai_validation ) ) {
				return $this->response_error( (string) $openai_validation->get_error_code(), $openai_validation->get_error_message(), 400 );
			}
		}

		$result = $storage->storePrimary( $api_key );
		if ( is_wp_error( $result ) ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_ENCRYPTION_FAILED, $result->get_error_message(), 500 );
		}

		$last4 = $storage->extractLast4( $api_key );
		$this->auditApiKeyAction( 'stored', $last4 );

		return $this->response_success(
			array(
				'stored' => true,
				'last4'  => $last4,
			)
		);
	}

	/**
	 * Get usage statistics.
	 *
	 * @openapi GET /agentwp/v1/usage
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return WP_REST_Response
	 */
	public function get_usage( $request ) {
		$dto = new UsageQueryDTO( $request );

		if ( ! $dto->isValid() ) {
			$error = $dto->getError();
			return $this->response_error(
				AgentWPConfig::ERROR_CODE_INVALID_REQUEST,
				$error ? $error->get_error_message() : __( 'Invalid request.', 'agentwp' ),
				400
			);
		}

		if ( ! $dto->hasValidPeriod() ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_INVALID_PERIOD, __( 'Invalid usage period.', 'agentwp' ), 400 );
		}

		$period       = $dto->getPeriod();
		$usageTracker = $this->getUsageTracker();
		$usage        = $usageTracker
			? $usageTracker->getUsageSummary( $period )
			: SettingsManager::getDefaultUsageStats();

		return $this->response_success(
			array(
				'period'              => $period,
				'total_tokens'        => isset( $usage['total_tokens'] ) ? $usage['total_tokens'] : 0,
				'total_cost_usd'      => isset( $usage['total_cost_usd'] ) ? $usage['total_cost_usd'] : 0,
				'breakdown_by_intent' => isset( $usage['breakdown_by_intent'] ) ? $usage['breakdown_by_intent'] : array(),
				'daily_trend'         => isset( $usage['daily_trend'] ) ? $usage['daily_trend'] : array(),
				'period_start'        => isset( $usage['period_start'] ) ? $usage['period_start'] : '',
				'period_end'          => isset( $usage['period_end'] ) ? $usage['period_end'] : '',
			)
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

		// Fallback to static defaults if container not available.
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
	 * Get the ApiKeyStorage service from the container.
	 *
	 * @return ApiKeyStorage|null
	 */
	private function getApiKeyStorage(): ?ApiKeyStorage {
		$storage = $this->resolve( ApiKeyStorage::class );
		return $storage instanceof ApiKeyStorage ? $storage : null;
	}

	/**
	 * Get the OpenAI key validator service from the container.
	 *
	 * @return OpenAIKeyValidatorInterface|null
	 */
	private function getOpenAIKeyValidator(): ?OpenAIKeyValidatorInterface {
		$validator = $this->resolve( OpenAIKeyValidatorInterface::class );
		return $validator instanceof OpenAIKeyValidatorInterface ? $validator : null;
	}

	/**
	 * Get the usage tracker service from the container.
	 *
	 * @return UsageTrackerInterface|null
	 */
	private function getUsageTracker(): ?UsageTrackerInterface {
		$tracker = $this->resolve( UsageTrackerInterface::class );
		return $tracker instanceof UsageTrackerInterface ? $tracker : null;
	}

	/**
	 * Get the audit logger service from the container.
	 *
	 * @return AuditLoggerInterface|null
	 */
	private function getAuditLogger(): ?AuditLoggerInterface {
		$logger = $this->resolve( AuditLoggerInterface::class );
		return $logger instanceof AuditLoggerInterface ? $logger : null;
	}

	/**
	 * Log an API key audit event.
	 *
	 * @param string $action   Action performed: 'stored', 'deleted'.
	 * @param string $key_last4 Last 4 characters of the key.
	 * @return void
	 */
	private function auditApiKeyAction( string $action, string $key_last4 ): void {
		$logger = $this->getAuditLogger();
		if ( ! $logger ) {
			return;
		}

		$logger->logApiKeyUpdate( $action, $this->getCurrentUserId(), $key_last4 );
	}

	/**
	 * Get the current user context service from the container.
	 *
	 * @return CurrentUserContextInterface|null
	 */
	private function getCurrentUserContext(): ?CurrentUserContextInterface {
		$context = $this->resolve( CurrentUserContextInterface::class );
		return $context instanceof CurrentUserContextInterface ? $context : null;
	}

	/**
	 * Get the current user ID from the injected context or fallback to WP global.
	 *
	 * @return int User ID.
	 */
	private function getCurrentUserId(): int {
		$context = $this->getCurrentUserContext();
		if ( $context !== null ) {
			return $context->getUserId();
		}

		// Fallback for backwards compatibility.
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}
}
