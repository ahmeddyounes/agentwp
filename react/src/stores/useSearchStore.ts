import { create } from 'zustand';
import type { SearchResults } from '../types';
import { getEmptySearchResults } from '../utils/constants';

interface SearchState {
  results: SearchResults;
  isOpen: boolean;
  isLoading: boolean;
  activeIndex: number;
  query: string;
}

interface SearchActions {
  setResults: (results: SearchResults) => void;
  setResultsForType: (
    type: keyof SearchResults,
    results: SearchResults[keyof SearchResults],
  ) => void;
  setIsOpen: (isOpen: boolean) => void;
  setIsLoading: (isLoading: boolean) => void;
  setActiveIndex: (index: number) => void;
  incrementActiveIndex: () => void;
  decrementActiveIndex: () => void;
  setQuery: (query: string) => void;
  getTotalCount: () => number;
  reset: () => void;
}

const initialState: SearchState = {
  results: getEmptySearchResults(),
  isOpen: false,
  isLoading: false,
  activeIndex: -1,
  query: '',
};

export const useSearchStore = create<SearchState & SearchActions>((set, get) => ({
  ...initialState,

  setResults: (results) => set({ results }),

  setResultsForType: (type, results) =>
    set((state) => ({
      results: { ...state.results, [type]: results },
    })),

  setIsOpen: (isOpen) => set({ isOpen }),

  setIsLoading: (isLoading) => set({ isLoading }),

  setActiveIndex: (activeIndex) => set({ activeIndex }),

  incrementActiveIndex: () => {
    const { activeIndex, results } = get();
    const totalCount = results.products.length + results.orders.length + results.customers.length;
    if (totalCount === 0) return;
    set({ activeIndex: (activeIndex + 1) % totalCount });
  },

  decrementActiveIndex: () => {
    const { activeIndex, results } = get();
    const totalCount = results.products.length + results.orders.length + results.customers.length;
    if (totalCount === 0) return;
    set({
      activeIndex: activeIndex <= 0 ? totalCount - 1 : activeIndex - 1,
    });
  },

  setQuery: (query) => set({ query }),

  getTotalCount: () => {
    const { results } = get();
    return results.products.length + results.orders.length + results.customers.length;
  },

  reset: () =>
    set({
      results: getEmptySearchResults(),
      isOpen: false,
      isLoading: false,
      activeIndex: -1,
      query: '',
    }),
}));
