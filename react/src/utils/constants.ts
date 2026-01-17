/**
 * Application constants.
 */

// Storage keys
export const OPEN_STATE_KEY = 'agentwp-command-deck-open';
export const DRAFT_HISTORY_KEY = 'agentwp-draft-history';
export const COMMAND_HISTORY_KEY = 'agentwp-command-history';
export const DEMO_TOUR_SEEN_KEY = 'agentwp-demo-tour-seen';

// Limits
export const MAX_DRAFT_HISTORY = 10;
export const MAX_COMMAND_HISTORY = 50;
export const MAX_COMMAND_FAVORITES = 50;

// Timing
export const DEMO_TOUR_START_DELAY_MS = 600;
export const HEALTH_CHECK_INTERVAL_MS = 5000;
export const THEME_TRANSITION_MS = 150;

// Search
export const SEARCH_TYPES = ['products', 'orders', 'customers'] as const;
export type SearchType = (typeof SEARCH_TYPES)[number];

// Period options
export const PERIOD_OPTIONS = [
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
  { value: '90d', label: 'Last 90 days' },
] as const;

// Admin trigger selectors
export const ADMIN_TRIGGER_SELECTORS = [
  '#wp-admin-bar-agentwp',
  '[data-agentwp-command-deck]',
  '#agentwp-command-deck',
];

// Development mode - safely detect Vite's import.meta.env.DEV
export const IS_DEV = (() => {
  try {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const meta = import.meta as any;
    return Boolean(meta?.env?.DEV);
  } catch {
    return false;
  }
})();

// Utility functions
export const logDev = (...args: unknown[]): void => {
  if (!IS_DEV || typeof console === 'undefined') {
    return;
  }
  console.error('[AgentWP]', ...args);
};

export const getInitialDemoMode = (): boolean => {
  if (typeof window === 'undefined') {
    return false;
  }
  return Boolean(
    (window as Window & { agentwpSettings?: { demoMode?: boolean } }).agentwpSettings?.demoMode,
  );
};

export const getInitialTourSeen = (): boolean => {
  if (typeof window === 'undefined') {
    return false;
  }
  try {
    return window.localStorage.getItem(DEMO_TOUR_SEEN_KEY) === '1';
  } catch {
    return false;
  }
};

export const getEmptySearchResults = (): Record<SearchType, never[]> =>
  SEARCH_TYPES.reduce(
    (accumulator, type) => {
      accumulator[type] = [];
      return accumulator;
    },
    {} as Record<SearchType, never[]>,
  );
