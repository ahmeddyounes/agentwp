import { create } from 'zustand';
import type { AnalyticsData, Period } from '../types';
import { PERIOD_OPTIONS } from '../utils/constants';

interface AnalyticsState {
  data: AnalyticsData | null;
  selectedPeriod: Period;
  isLoading: boolean;
  error: string | null;
}

interface AnalyticsActions {
  setData: (data: AnalyticsData | null) => void;
  setSelectedPeriod: (period: Period) => void;
  setIsLoading: (isLoading: boolean) => void;
  setError: (error: string | null) => void;
  reset: () => void;
}

const initialState: AnalyticsState = {
  data: null,
  selectedPeriod: '7d',
  isLoading: false,
  error: null,
};

export const useAnalyticsStore = create<AnalyticsState & AnalyticsActions>((set) => ({
  ...initialState,

  setData: (data) => set({ data }),

  setSelectedPeriod: (selectedPeriod) => set({ selectedPeriod }),

  setIsLoading: (isLoading) => set({ isLoading }),

  setError: (error) => set({ error }),

  reset: () => set(initialState),
}));

// Re-export for convenience
export { PERIOD_OPTIONS };
