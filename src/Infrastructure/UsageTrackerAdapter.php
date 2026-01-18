<?php
/**
 * Usage tracker adapter.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Billing\UsageTracker;
use AgentWP\Contracts\UsageTrackerInterface;

/**
 * Adapter that wraps the static UsageTracker class to implement UsageTrackerInterface.
 *
 * This allows usage tracking to be injected and mocked in tests.
 */
final class UsageTrackerAdapter implements UsageTrackerInterface {

	/**
	 * {@inheritDoc}
	 */
	public function logUsage(
		string $model,
		int $inputTokens,
		int $outputTokens,
		string $intentType,
		string $timestamp = ''
	): void {
		UsageTracker::log_usage( $model, $inputTokens, $outputTokens, $intentType, $timestamp );
	}

	/**
	 * {@inheritDoc}
	 */
	public function getUsageSummary( string $period ): array {
		return UsageTracker::get_usage_summary( $period );
	}

	/**
	 * {@inheritDoc}
	 */
	public function getTotalCost( string $period ): float {
		$summary = $this->getUsageSummary( $period );

		return isset( $summary['total_cost_usd'] ) ? (float) $summary['total_cost_usd'] : 0.0;
	}
}
