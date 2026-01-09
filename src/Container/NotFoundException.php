<?php
/**
 * Not found exception.
 *
 * @package AgentWP\Container
 */

namespace AgentWP\Container;

/**
 * Exception thrown when a requested entry is not found in the container.
 */
class NotFoundException extends ContainerException {

	/**
	 * Create a new NotFoundException.
	 *
	 * @param string $id The identifier that was not found.
	 */
	public function __construct( string $id ) {
		parent::__construct( sprintf( 'No binding found for "%s".', $id ) );
	}
}
