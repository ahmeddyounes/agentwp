import { useQuery } from '@tanstack/react-query';
import agentwpClient from '../api/AgentWPClient';
import type { UsageSummary } from '../types';

interface UsageResponse {
  success: boolean;
  data?: {
    total_tokens: number;
    total_cost_usd: number;
    breakdown_by_intent: Array<{
      intent: string;
      count: number;
      tokens: number;
      cost: number;
    }>;
    daily_trend: Array<{
      date: string;
      tokens: number;
      cost: number;
    }>;
    period_start: string;
    period_end: string;
  };
  error?: {
    code: string;
    message: string;
  };
}

const normalizeUsageSummary = (payload: UsageResponse['data']): UsageSummary => ({
  totalTokens: Number.parseInt(String(payload?.total_tokens ?? 0), 10) || 0,
  totalCostUsd: Number.parseFloat(String(payload?.total_cost_usd ?? 0)) || 0,
  breakdownByIntent: Array.isArray(payload?.breakdown_by_intent) ? payload.breakdown_by_intent : [],
  dailyTrend: Array.isArray(payload?.daily_trend) ? payload.daily_trend : [],
  periodStart: payload?.period_start ?? '',
  periodEnd: payload?.period_end ?? '',
});

export function useUsage(period = 'month', enabled = true) {
  return useQuery<UsageResponse, Error>({
    queryKey: ['usage', period],
    queryFn: async () => {
      const response = await agentwpClient.getUsage(period);
      return response as UsageResponse;
    },
    enabled,
    staleTime: 2 * 60 * 1000, // 2 minutes
    gcTime: 5 * 60 * 1000, // 5 minutes
    retry: 2,
    retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 30000),
  });
}

export function useUsageData(period = 'month') {
  const { data, isLoading, isError, error, refetch } = useUsage(period);

  return {
    usage: data?.success && data.data ? normalizeUsageSummary(data.data) : null,
    isLoading,
    isError: isError || data?.success === false,
    error: error?.message || data?.error?.message || null,
    refetch,
  };
}
