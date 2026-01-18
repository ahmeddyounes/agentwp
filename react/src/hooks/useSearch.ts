import { useQuery } from '@tanstack/react-query';
import { useCallback, useEffect, useRef, useState } from 'react';
import agentwpClient, { type ApiResponse } from '../api/AgentWPClient';
import type { components } from '../types/api';
import type { SearchResults } from '../types';

const DEBOUNCE_MS = 300;
const MIN_QUERY_LENGTH = 2;

type SearchResponseData = components['schemas']['SearchResponseData'];
type SearchResult = components['schemas']['SearchResultItem'];

const mapSearchResult = (
  item: SearchResult,
  type: 'products' | 'orders' | 'customers',
): SearchResults['products'][number] => ({
  id: item.id ?? 0,
  title: item.primary ?? '',
  subtitle: item.secondary,
  type,
});

const normalizeSearchResults = (data: SearchResponseData | undefined): SearchResults => {
  const results = data?.results || {};
  return {
    products: (results.products || []).map((item) => mapSearchResult(item, 'products')),
    orders: (results.orders || []).map((item) => mapSearchResult(item, 'orders')),
    customers: (results.customers || []).map((item) => mapSearchResult(item, 'customers')),
  };
};

export function useSearchQuery(query: string, types: string[] = [], enabled = true) {
  return useQuery<ApiResponse<SearchResponseData>, Error>({
    queryKey: ['search', query, types],
    queryFn: async () => {
      return await agentwpClient.search(query, types);
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
    error: error?.message || (data && !data.success ? data.error?.message : null) || null,
    hasResults:
      results.products.length > 0 || results.orders.length > 0 || results.customers.length > 0,
    clear,
  };
}
