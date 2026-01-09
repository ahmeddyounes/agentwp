<?php
/**
 * Parsed response DTO.
 *
 * @package AgentWP\AI\Client
 */

namespace AgentWP\AI\Client;

/**
 * Represents a parsed OpenAI response.
 */
final class ParsedResponse {

	/**
	 * Whether parsing was successful.
	 *
	 * @var bool
	 */
	public readonly bool $success;

	/**
	 * Error message if parsing failed.
	 *
	 * @var string
	 */
	public readonly string $error;

	/**
	 * Response content.
	 *
	 * @var string
	 */
	public readonly string $content;

	/**
	 * Tool calls from the response.
	 *
	 * @var array
	 */
	public readonly array $toolCalls;

	/**
	 * Usage information.
	 *
	 * @var array
	 */
	public readonly array $usage;

	/**
	 * Raw response data.
	 *
	 * @var array
	 */
	public readonly array $raw;

	/**
	 * Model name.
	 *
	 * @var string
	 */
	public readonly string $model;

	/**
	 * Create a new ParsedResponse.
	 *
	 * @param bool   $success   Whether parsing was successful.
	 * @param string $error     Error message.
	 * @param string $content   Response content.
	 * @param array  $toolCalls Tool calls.
	 * @param array  $usage     Usage information.
	 * @param array  $raw       Raw response data.
	 * @param string $model     Model name.
	 */
	private function __construct(
		bool $success,
		string $error,
		string $content,
		array $toolCalls,
		array $usage,
		array $raw,
		string $model
	) {
		$this->success   = $success;
		$this->error     = $error;
		$this->content   = $content;
		$this->toolCalls = $toolCalls;
		$this->usage     = $usage;
		$this->raw       = $raw;
		$this->model     = $model;
	}

	/**
	 * Create a successful parsed response.
	 *
	 * @param string $content   Response content.
	 * @param array  $toolCalls Tool calls.
	 * @param array  $usage     Usage information.
	 * @param array  $raw       Raw response data.
	 * @param string $model     Model name.
	 * @return self
	 */
	public static function success(
		string $content,
		array $toolCalls,
		array $usage,
		array $raw,
		string $model
	): self {
		return new self(
			success: true,
			error: '',
			content: $content,
			toolCalls: $toolCalls,
			usage: $usage,
			raw: $raw,
			model: $model
		);
	}

	/**
	 * Create an error parsed response.
	 *
	 * @param string $error Error message.
	 * @return self
	 */
	public static function error( string $error ): self {
		return new self(
			success: false,
			error: $error,
			content: '',
			toolCalls: array(),
			usage: array(),
			raw: array(),
			model: ''
		);
	}

	/**
	 * Convert to legacy array format for backward compatibility.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'success'    => $this->success,
			'error'      => $this->error,
			'content'    => $this->content,
			'tool_calls' => $this->toolCalls,
			'usage'      => $this->usage,
			'raw'        => $this->raw,
			'model'      => $this->model,
		);
	}
}
