import { useQuery } from '@tanstack/react-query';
import { useCallback, useEffect, useRef, useState } from 'react';
import agentwpClient from '../api/AgentWPClient';
import type { SearchResults } from '../types';

const DEBOUNCE_MS = 300;
const MIN_QUERY_LENGTH = 2;

interface SearchResponse {
  success: boolean;
  data?: {
    products?: Array<{ id: string | number; title: string; subtitle?: string }>;
    orders?: Array<{ id: string | number; title: string; subtitle?: string }>;
    customers?: Array<{ id: string | number; title: string; subtitle?: string }>;
  };
  error?: {
    code: string;
    message: string;
  };
}

const normalizeSearchResults = (data: SearchResponse['data']): SearchResults => ({
  products: (data?.products || []).map((item) => ({
    ...item,
    type: 'products' as const,
  })),
  orders: (data?.orders || []).map((item) => ({
    ...item,
    type: 'orders' as const,
  })),
  customers: (data?.customers || []).map((item) => ({
    ...item,
    type: 'customers' as const,
  })),
});

export function useSearchQuery(query: string, types: string[] = [], enabled = true) {
  return useQuery<SearchResponse, Error>({
    queryKey: ['search', query, types],
    queryFn: async () => {
      const response = await agentwpClient.search(query, types);
      return response as SearchResponse;
    },
    enabled: enabled && query.length >= MIN_QUERY_LENGTH,
    staleTime: 30 * 1000, // 30 seconds
    gcTime: 60 * 1000, // 1 minute
    retry: 1,
  });
}

export function useDebouncedSearch(initialQuery = '') {
  const [query, setQuery] = useState(initialQuery);
  const [debouncedQuery, setDebouncedQuery] = useState(initialQuery);
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const mountedRef = useRef(true);

  // Track mounted state to prevent state updates after unmount
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  useEffect(() => {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
    }

    timeoutRef.current = setTimeout(() => {
      if (mountedRef.current) {
        setDebouncedQuery(query);
      }
    }, DEBOUNCE_MS);

    return () => {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
      }
    };
  }, [query]);

  const { data, isLoading, isError, error } = useSearchQuery(debouncedQuery);

  const results: SearchResults =
    data?.success && data.data
      ? normalizeSearchResults(data.data)
      : { products: [], orders: [], customers: [] };

  const clear = useCallback(() => {
    setQuery('');
    setDebouncedQuery('');
  }, []);

  return {
    query,
    setQuery,
    debouncedQuery,
    results,
    isLoading,
    isError: isError || data?.success === false,
    error: error?.message || data?.error?.message || null,
    hasResults:
      results.products.length > 0 || results.orders.length > 0 || results.customers.length > 0,
    clear,
  };
}
