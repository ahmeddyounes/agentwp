import { create } from 'zustand';
import type { UsageSummary } from '../types';

const DEFAULT_USAGE_SUMMARY: UsageSummary = {
  totalTokens: 0,
  totalCostUsd: 0,
  breakdownByIntent: [],
  dailyTrend: [],
  periodStart: '',
  periodEnd: '',
};

interface UsageState {
  summary: UsageSummary;
  baseline: UsageSummary | null;
  isLoading: boolean;
  budgetLimit: number;
  error: string | null;
}

interface UsageActions {
  setSummary: (summary: UsageSummary) => void;
  setBaseline: (baseline: UsageSummary | null) => void;
  setIsLoading: (isLoading: boolean) => void;
  setBudgetLimit: (limit: number) => void;
  setError: (error: string | null) => void;
  getBudgetPercentage: () => number;
  isOverBudget: () => boolean;
  reset: () => void;
}

const initialState: UsageState = {
  summary: DEFAULT_USAGE_SUMMARY,
  baseline: null,
  isLoading: false,
  budgetLimit: 0,
  error: null,
};

export const useUsageStore = create<UsageState & UsageActions>((set, get) => ({
  ...initialState,

  setSummary: (summary) => set({ summary }),

  setBaseline: (baseline) => set({ baseline }),

  setIsLoading: (isLoading) => set({ isLoading }),

  setBudgetLimit: (budgetLimit) => set({ budgetLimit }),

  setError: (error) => set({ error }),

  getBudgetPercentage: () => {
    const { summary, budgetLimit } = get();
    if (budgetLimit <= 0) return 0;
    return Math.min((summary.totalCostUsd / budgetLimit) * 100, 100);
  },

  isOverBudget: () => {
    const { summary, budgetLimit } = get();
    if (budgetLimit <= 0) return false;
    return summary.totalCostUsd >= budgetLimit;
  },

  reset: () => set(initialState),
}));

export { DEFAULT_USAGE_SUMMARY };
