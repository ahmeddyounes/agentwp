import type { ThemePreference } from './types';

export const THEME_STORAGE_KEY = 'agentwp-theme-preference' as const;

type ResolvedTheme = Exclude<ThemePreference, 'system'>;

const VALID_THEMES: ReadonlySet<ResolvedTheme> = new Set<ResolvedTheme>(['light', 'dark']);

const normalizeTheme = (value: unknown): ResolvedTheme | null => {
  if (typeof value !== 'string') {
    return null;
  }
  return VALID_THEMES.has(value as ResolvedTheme) ? (value as ResolvedTheme) : null;
};

export const isValidTheme = (value: unknown): value is ResolvedTheme =>
  normalizeTheme(value) !== null;

export const getStoredTheme = (): ResolvedTheme | null => {
  if (typeof window === 'undefined') {
    return null;
  }
  try {
    return normalizeTheme(window.localStorage.getItem(THEME_STORAGE_KEY));
  } catch {
    return null;
  }
};

export const getServerTheme = (): ResolvedTheme | null => {
  if (typeof window === 'undefined') {
    return null;
  }
  return normalizeTheme(window.agentwpSettings?.theme);
};

export const getSystemTheme = (fallback: ResolvedTheme = 'light'): ResolvedTheme => {
  if (typeof window === 'undefined' || !window.matchMedia) {
    return fallback;
  }
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
};

export const getInitialThemePreference = (): ThemePreference => {
  const serverTheme = getServerTheme();
  if (serverTheme) {
    return serverTheme;
  }
  const storedTheme = getStoredTheme();
  if (storedTheme) {
    return storedTheme;
  }
  return 'system';
};

export const resolveTheme = (preference: ThemePreference, prefersDark?: boolean): ResolvedTheme => {
  if (isValidTheme(preference)) {
    return preference;
  }
  const systemTheme =
    prefersDark !== undefined ? (prefersDark ? 'dark' : 'light') : getSystemTheme();
  return systemTheme;
};

export const applyTheme = (theme: ResolvedTheme, target: HTMLElement | null = null): void => {
  const root = target || (typeof document !== 'undefined' ? document.documentElement : null);
  if (!root) {
    return;
  }
  root.dataset.theme = theme;
  root.style.colorScheme = theme;
};
