import { useQuery } from '@tanstack/react-query';
import agentwpClient from '../api/AgentWPClient.js';
import { HEALTH_CHECK_INTERVAL_MS } from '../utils/constants';

interface HealthResponse {
  success: boolean;
  data?: {
    status: string;
    timestamp: number;
  };
  error?: {
    code: string;
    message: string;
  };
}

export function useHealthCheck(enabled = true) {
  return useQuery<HealthResponse, Error>({
    queryKey: ['health'],
    queryFn: async () => {
      const response = await agentwpClient.getHealth();
      return response as HealthResponse;
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

  if (isError) return false;
  if (!data) return true; // Optimistic default

  return data.success !== false;
}
