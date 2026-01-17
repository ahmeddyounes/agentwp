import { create } from 'zustand';
import type { ErrorState, Metrics } from '../types';
import { OPEN_STATE_KEY } from '../utils/constants';

interface ModalState {
  isOpen: boolean;
  loading: boolean;
  prompt: string;
  response: string;
  errorState: ErrorState | null;
  metrics: Metrics;
  retryAttempt: number;
}

interface ModalActions {
  open: () => void;
  close: () => void;
  toggle: () => void;
  setLoading: (loading: boolean) => void;
  setPrompt: (prompt: string) => void;
  setResponse: (response: string) => void;
  appendResponse: (text: string) => void;
  setError: (error: ErrorState | null) => void;
  setMetrics: (metrics: Partial<Metrics>) => void;
  incrementRetry: () => void;
  resetRetry: () => void;
  reset: () => void;
}

const getInitialOpenState = (): boolean => {
  if (typeof window === 'undefined') return false;
  try {
    return window.sessionStorage.getItem(OPEN_STATE_KEY) === 'true';
  } catch {
    return false;
  }
};

const persistOpenState = (isOpen: boolean): void => {
  if (typeof window === 'undefined') return;
  try {
    if (isOpen) {
      window.sessionStorage.setItem(OPEN_STATE_KEY, 'true');
    } else {
      window.sessionStorage.removeItem(OPEN_STATE_KEY);
    }
  } catch {
    // Ignore storage errors
  }
};

const initialState: ModalState = {
  isOpen: getInitialOpenState(),
  loading: false,
  prompt: '',
  response: '',
  errorState: null,
  metrics: { latencyMs: null, tokenCost: null },
  retryAttempt: 0,
};

export const useModalStore = create<ModalState & ModalActions>((set) => ({
  ...initialState,

  open: () => {
    persistOpenState(true);
    set({ isOpen: true });
  },

  close: () => {
    persistOpenState(false);
    set({ isOpen: false });
  },

  toggle: () => {
    set((state) => {
      const newOpen = !state.isOpen;
      persistOpenState(newOpen);
      return { isOpen: newOpen };
    });
  },

  setLoading: (loading) => set({ loading }),

  setPrompt: (prompt) => set({ prompt }),

  setResponse: (response) => set({ response }),

  appendResponse: (text) => set((state) => ({ response: state.response + text })),

  setError: (errorState) => set({ errorState }),

  setMetrics: (metrics) =>
    set((state) => ({
      metrics: { ...state.metrics, ...metrics },
    })),

  incrementRetry: () => set((state) => ({ retryAttempt: state.retryAttempt + 1 })),

  resetRetry: () => set({ retryAttempt: 0 }),

  reset: () =>
    set({
      loading: false,
      prompt: '',
      response: '',
      errorState: null,
      metrics: { latencyMs: null, tokenCost: null },
      retryAttempt: 0,
    }),
}));
