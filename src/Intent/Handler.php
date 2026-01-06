<?php
/**
 * Intent handler contract.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

use AgentWP\AI\Response;

interface Handler {
	/**
	 * @param string $intent Intent identifier.
	 * @return bool
	 */
	public function canHandle( string $intent ): bool;

	/**
	 * @param array $context Enriched request context.
	 * @return Response
	 */
	public function handle( array $context ): Response;
}
