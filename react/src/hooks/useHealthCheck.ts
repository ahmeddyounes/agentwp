import { useQuery } from '@tanstack/react-query';
import agentwpClient, { getApiError, type ApiResponse } from '../api/AgentWPClient';
import type { components } from '../types/api';
import { HEALTH_CHECK_INTERVAL_MS } from '../utils/constants';

type HealthResponseData = components['schemas']['HealthResponseData'];

export function useHealthCheck(enabled = true) {
  return useQuery<ApiResponse<HealthResponseData>, Error>({
    queryKey: ['health'],
    queryFn: async () => {
      return await agentwpClient.getHealth();
    },
    refetchInterval: HEALTH_CHECK_INTERVAL_MS,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: false, // Prevent double-fetching with interval
    refetchOnMount: true,
    refetchOnReconnect: true,
    enabled,
    staleTime: HEALTH_CHECK_INTERVAL_MS - 1000,
    retry: 1,
    retryDelay: 1000,
  });
}

export function useIsOnline() {
  const { data, isError } = useHealthCheck();
  const apiError = getApiError(data);

  if (isError || apiError) return false;
  if (!data) return true; // Optimistic default

  return data.success !== false;
}
