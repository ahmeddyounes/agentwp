import { useQuery } from '@tanstack/react-query';
import agentwpClient, { type ApiResponse } from '../api/AgentWPClient';
import type { components } from '../types/api';
import type { UsageSummary } from '../types';

type UsageResponseData = components['schemas']['UsageResponseData'];
type UsagePeriod = 'day' | 'week' | 'month';

const normalizeUsageSummary = (payload: UsageResponseData | undefined): UsageSummary => ({
  totalTokens: Number.parseInt(String(payload?.total_tokens ?? 0), 10) || 0,
  totalCostUsd: Number.parseFloat(String(payload?.total_cost_usd ?? 0)) || 0,
  breakdownByIntent: [], // breakdown_by_intent is an open record type in the schema
  dailyTrend: [], // daily_trend is an open array type in the schema
  periodStart: payload?.period_start ?? '',
  periodEnd: payload?.period_end ?? '',
});

export function useUsage(period: UsagePeriod = 'month', enabled = true) {
  return useQuery<ApiResponse<UsageResponseData>, Error>({
    queryKey: ['usage', period],
    queryFn: async () => {
      return await agentwpClient.getUsage(period);
    },
    enabled,
    staleTime: 2 * 60 * 1000, // 2 minutes
    gcTime: 5 * 60 * 1000, // 5 minutes
    retry: 2,
    retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 30000),
  });
}

export function useUsageData(period: UsagePeriod = 'month') {
  const { data, isLoading, isError, error, refetch } = useUsage(period);

  return {
    usage: data?.success && data.data ? normalizeUsageSummary(data.data) : null,
    isLoading,
    isError: isError || data?.success === false,
    error: error?.message || (data && !data.success ? data.error?.message : null) || null,
    refetch,
  };
}
