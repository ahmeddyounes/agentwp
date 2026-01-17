import { useQuery } from '@tanstack/react-query';
import agentwpClient from '../api/AgentWPClient';
import type { AnalyticsData, Period } from '../types';

interface AnalyticsResponse {
  success: boolean;
  data?: AnalyticsData;
  error?: {
    code: string;
    message: string;
  };
}

export function useAnalytics(period: Period, enabled = true) {
  return useQuery<AnalyticsResponse, Error>({
    queryKey: ['analytics', period],
    queryFn: async () => {
      const response = await agentwpClient.getAnalytics({ period });
      return response as AnalyticsResponse;
    },
    enabled,
    staleTime: 5 * 60 * 1000, // 5 minutes
    gcTime: 10 * 60 * 1000, // 10 minutes (formerly cacheTime)
    retry: 2,
    retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 30000),
  });
}

export function useAnalyticsData(period: Period) {
  const { data, isLoading, isError, error, refetch } = useAnalytics(period);

  return {
    analytics: data?.success ? data.data : null,
    isLoading,
    isError: isError || data?.success === false,
    error: error?.message || data?.error?.message || null,
    refetch,
  };
}
