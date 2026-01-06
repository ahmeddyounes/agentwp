<?php
/**
 * OpenAI response wrapper.
 *
 * @package AgentWP
 */

namespace AgentWP\AI;

class Response {
	/**
	 * @var bool
	 */
	private $success;

	/**
	 * @var int
	 */
	private $status;

	/**
	 * @var string
	 */
	private $message;

	/**
	 * @var array
	 */
	private $data;

	/**
	 * @var array
	 */
	private $meta;

	/**
	 * @param bool   $success Success flag.
	 * @param int    $status HTTP status.
	 * @param string $message Error or status message.
	 * @param array  $data Parsed response data.
	 * @param array  $meta Metadata payload.
	 */
	public function __construct( $success, $status, $message, array $data, array $meta = array() ) {
		$this->success = (bool) $success;
		$this->status  = (int) $status;
		$this->message = is_string( $message ) ? $message : '';
		$this->data    = $data;
		$this->meta    = $meta;
	}

	/**
	 * Create a success response.
	 *
	 * @param array $data Payload data.
	 * @param array $meta Metadata.
	 * @return self
	 */
	public static function success( array $data, array $meta = array() ) {
		return new self( true, 200, '', $data, $meta );
	}

	/**
	 * Create an error response.
	 *
	 * @param string $message Error message.
	 * @param int    $status HTTP status.
	 * @param array  $meta Metadata.
	 * @return self
	 */
	public static function error( $message, $status = 500, array $meta = array() ) {
		return new self( false, $status, $message, array(), $meta );
	}

	/**
	 * @return bool
	 */
	public function is_success() {
		return $this->success;
	}

	/**
	 * @return int
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * @return string
	 */
	public function get_message() {
		return $this->message;
	}

	/**
	 * @return array
	 */
	public function get_data() {
		return $this->data;
	}

	/**
	 * @return array
	 */
	public function get_meta() {
		return $this->meta;
	}

	/**
	 * @return array
	 */
	public function to_array() {
		return array(
			'success' => $this->success,
			'status'  => $this->status,
			'message' => $this->message,
			'data'    => $this->data,
			'meta'    => $this->meta,
		);
	}
}
