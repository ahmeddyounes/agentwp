<?php
/**
 * Usage tracker service implementation.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Billing\UsageTracker;
use AgentWP\Contracts\UsageTrackerInterface;
use AgentWP\DTO\UsageSummaryDTO;

/**
 * Usage tracker service that wraps the static UsageTracker for DI-based access.
 */
final class UsageTrackerService implements UsageTrackerInterface {

	/**
	 * Log API usage.
	 *
	 * @param string $model        The model used.
	 * @param int    $inputTokens  Input token count.
	 * @param int    $outputTokens Output token count.
	 * @param string $intentType   The intent type.
	 * @param string $timestamp    Optional timestamp.
	 * @return void
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
	 * Get usage summary for a period.
	 *
	 * @param string $period The period (e.g., 'today', 'week', 'month').
	 * @return array Usage summary data.
	 */
	public function getUsageSummary( string $period ): array {
		$rawSummary = UsageTracker::get_usage_summary( $period );

		// Validate structure via DTO (for internal consistency).
		$summaryDTO = UsageSummaryDTO::fromArray( $rawSummary );

		return $summaryDTO->toArray();
	}

	/**
	 * Get usage summary as DTO.
	 *
	 * @param string $period The period (e.g., 'today', 'week', 'month').
	 * @return UsageSummaryDTO Usage summary DTO.
	 */
	public function getUsageSummaryAsDTO( string $period ): UsageSummaryDTO {
		$rawSummary = UsageTracker::get_usage_summary( $period );

		return UsageSummaryDTO::fromArray( $rawSummary );
	}

	/**
	 * Get total cost for a period.
	 *
	 * @param string $period The period.
	 * @return float Total cost in dollars.
	 */
	public function getTotalCost( string $period ): float {
		$summary = $this->getUsageSummary( $period );
		return isset( $summary['total_cost_usd'] ) ? (float) $summary['total_cost_usd'] : 0.0;
	}
}
