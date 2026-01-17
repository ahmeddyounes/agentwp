<?php
/**
 * Base handler for intent responses.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Intent\Handler;
use AgentWP\Intent\Intent;

abstract class BaseHandler implements Handler {
	protected string $intent;

	/**
	 * @param string $intent Intent identifier.
	 */
	public function __construct( string $intent ) {
		$this->intent = Intent::normalize( $intent );
	}

	/**
	 * @param string $intent Intent identifier.
	 * @return bool
	 */
	public function canHandle( string $intent ): bool {
		return Intent::normalize( $intent ) === $this->intent;
	}

	/**
	 * Get the intent this handler can process.
	 *
	 * @deprecated 2.0.0 Use the #[HandlesIntent] attribute instead for intent declaration.
	 *             This method will be removed when all handlers use attributes.
	 *             Migration: Add #[HandlesIntent(Intent::YOUR_INTENT)] to your handler class.
	 * @see \AgentWP\Intent\Attributes\HandlesIntent
	 *
	 * @return string The intent identifier.
	 */
	public function getIntent(): string {
		$this->triggerDeprecationWarning(
			'getIntent()',
			'Use the #[HandlesIntent] attribute instead for intent declaration.'
		);
		return $this->intent;
	}

	/**
	 * Trigger a deprecation warning in development mode.
	 *
	 * @param string $method     The deprecated method name.
	 * @param string $suggestion Migration suggestion.
	 * @return void
	 */
	private function triggerDeprecationWarning( string $method, string $suggestion ): void {
		// Only trigger warnings in development mode.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Avoid duplicate warnings by tracking which deprecations have been triggered.
		static $triggered = array();
		$key = static::class . '::' . $method;
		if ( isset( $triggered[ $key ] ) ) {
			return;
		}
		$triggered[ $key ] = true;

		$message = sprintf(
			'AgentWP Deprecation: %s::%s is deprecated since version 2.0.0 and will be removed in a future release. %s',
			static::class,
			$method,
			$suggestion
		);

		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( static::class . '::' . $method, esc_html( $message ), '2.0.0' );
		} elseif ( function_exists( 'trigger_error' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			trigger_error( esc_html( $message ), E_USER_DEPRECATED );
		}
	}

	/**
	 * @param array  $context Context data.
	 * @param string $message Response message.
	 * @param array  $data Additional payload data.
	 * @return Response
	 */
	protected function build_response( array $context, $message, array $data = array() ) {
		$payload = array_merge(
			array(
				'intent'  => $this->intent,
				'message' => $message,
			),
			$data
		);

		if ( isset( $context['store'] ) ) {
			$payload['store'] = $context['store'];
		}

		if ( isset( $context['user'] ) ) {
			$payload['user'] = $context['user'];
		}

		if ( isset( $context['recent_orders'] ) ) {
			$payload['recent_orders'] = $context['recent_orders'];
		}

		if ( isset( $context['function_suggestions'] ) ) {
			$payload['function_suggestions'] = $context['function_suggestions'];
		}

		return Response::success( $payload );
	}
}
