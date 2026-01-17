import { useQuery } from '@tanstack/react-query';
import agentwpClient, { type ApiResponse } from '../api/AgentWPClient';
import type { AnalyticsData, Period } from '../types';

// Analytics endpoint response data (not yet in OpenAPI spec)
type AnalyticsResponseData = AnalyticsData;

export function useAnalytics(period: Period, enabled = true) {
  return useQuery<ApiResponse<AnalyticsResponseData>, Error>({
    queryKey: ['analytics', period],
    queryFn: async () => {
      const response = await agentwpClient.getAnalytics({ period });
      // Cast to the expected type since analytics endpoint is not in OpenAPI spec
      return response as ApiResponse<AnalyticsResponseData>;
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
    error: error?.message || (data && !data.success ? data.error?.message : null) || null,
    refetch,
  };
}
