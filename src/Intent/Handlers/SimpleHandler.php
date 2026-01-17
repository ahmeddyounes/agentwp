<?php
/**
 * Simple handler base class.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Intent\Intent;

/**
 * Lightweight base class for handlers that don't require AI capabilities.
 *
 * Use this handler for:
 * - Direct database queries
 * - Simple data retrieval
 * - Non-agentic operations
 * - Performance-critical paths
 *
 * Contrast with AbstractAgenticHandler which provides AI-powered
 * agentic loops for complex, multi-step operations.
 *
 * Usage:
 * ```php
 * #[HandlesIntent(Intent::SIMPLE_OPERATION)]
 * class MySimpleHandler extends SimpleHandler {
 *     protected function process(array $context): Response {
 *         $data = $this->doSomethingDirect();
 *         return $this->build_response($context, 'Done!', ['data' => $data]);
 *     }
 * }
 * ```
 */
abstract class SimpleHandler extends BaseHandler {

	/**
	 * Process the intent directly without AI.
	 *
	 * This method must be implemented by subclasses to provide
	 * the direct processing logic.
	 *
	 * @param array $context Request context data.
	 * @return Response Response with results.
	 */
	abstract protected function process( array $context ): Response;

	/**
	 * Handle the intent using direct processing.
	 *
	 * This is a template method that delegates to process().
	 * Subclasses should override process() rather than this method.
	 *
	 * @param array $context Request context data.
	 * @return Response Response with results.
	 */
	public function handle( array $context ): Response {
		return $this->process( $context );
	}

	/**
	 * Handle multiple intents.
	 *
	 * Allows a single SimpleHandler to handle multiple intent types.
	 * Override this method if your handler supports multiple intents.
	 *
	 * @param string[] $intents Array of intent identifiers.
	 * @return bool True if all intents can be handled.
	 */
	public function canHandleMultiple( array $intents ): bool {
		foreach ( $intents as $intent ) {
			if ( ! $this->canHandle( $intent ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the default input when none is provided.
	 *
	 * Override in subclasses to provide intent-specific defaults.
	 *
	 * @return string Default user input.
	 */
	protected function getDefaultInput(): string {
		return '';
	}

	/**
	 * Check if context has required keys.
	 *
	 * Helper method for validating context data in process().
	 *
	 * @param array  $context Context to validate.
	 * @param string[] $keys   Required context keys.
	 * @return bool True if all keys are present.
	 */
	protected function hasContextKeys( array $context, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( ! isset( $context[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get a context value with fallback.
	 *
	 * @param array $context Context data.
	 * @param string $key     Context key.
	 * @param mixed  $default Fallback value.
	 * @return mixed Context value or default.
	 */
	protected function getContextValue( array $context, string $key, $default = null ) {
		return $context[ $key ] ?? $default;
	}

	/**
	 * Build an error response.
	 *
	 * Convenience method for building error responses.
	 *
	 * @param string $message Error message.
	 * @param int    $code    HTTP-style error code.
	 * @return Response Error response.
	 */
	protected function buildError( string $message, int $code = 400 ): Response {
		return Response::error( $message, $code );
	}

	/**
	 * Build a not found response.
	 *
	 * Convenience method for not found errors.
	 *
	 * @param string $message Not found message.
	 * @return Response Error response.
	 */
	protected function buildNotFound( string $message = 'Resource not found.' ): Response {
		return Response::error( $message, 404 );
	}

	/**
	 * Build a validation error response.
	 *
	 * Convenience method for validation errors.
	 *
	 * @param string         $message Error message.
	 * @param string[]|array $errors  Specific validation errors.
	 * @return Response Error response.
	 */
	protected function buildValidationError( string $message, array $errors = [] ): Response {
		$meta = ! empty( $errors ) ? [ 'errors' => $errors ] : [];

		return Response::error( $message, 422, $meta );
	}

	/**
	 * Build a success response with data.
	 *
	 * Convenience method for success responses.
	 *
	 * @param string $message Success message.
	 * @param array  $data    Response data.
	 * @return Response Success response.
	 */
	protected function buildSuccess( string $message = '', array $data = [] ): Response {
		if ( '' !== $message && ! isset( $data['message'] ) ) {
			$data['message'] = $message;
		}

		return Response::success( $data );
	}
}
