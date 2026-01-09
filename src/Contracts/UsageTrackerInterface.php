<?php
/**
 * Usage tracker interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for API usage tracking services.
 */
interface UsageTrackerInterface {

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
	): void;

	/**
	 * Get usage summary for a period.
	 *
	 * @param string $period The period (e.g., 'today', 'week', 'month').
	 * @return array Usage summary data.
	 */
	public function getUsageSummary( string $period ): array;

	/**
	 * Get total cost for a period.
	 *
	 * @param string $period The period.
	 * @return float Total cost in dollars.
	 */
	public function getTotalCost( string $period ): float;
}
