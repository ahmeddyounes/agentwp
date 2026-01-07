const THEME_STORAGE_KEY = 'agentwp-theme-preference';
const VALID_THEMES = new Set(['light', 'dark']);

const normalizeTheme = (value) => (VALID_THEMES.has(value) ? value : null);

export const isValidTheme = (value) => VALID_THEMES.has(value);

export const getStoredTheme = () => {
  if (typeof window === 'undefined') {
    return null;
  }
  try {
    return normalizeTheme(window.localStorage.getItem(THEME_STORAGE_KEY));
  } catch (error) {
    return null;
  }
};

export const getServerTheme = () => {
  if (typeof window === 'undefined') {
    return null;
  }
  return normalizeTheme(window.agentwpSettings?.theme);
};

export const getSystemTheme = (fallback = 'light') => {
  if (typeof window === 'undefined' || !window.matchMedia) {
    return fallback;
  }
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
};

export const getInitialThemePreference = () => {
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

export const resolveTheme = (preference, prefersDark) => {
  if (isValidTheme(preference)) {
    return preference;
  }
  const systemTheme = prefersDark !== undefined ? (prefersDark ? 'dark' : 'light') : getSystemTheme();
  return systemTheme;
};

export const applyTheme = (theme) => {
  if (typeof document === 'undefined') {
    return;
  }
  const root = document.documentElement;
  root.dataset.theme = theme;
  root.style.colorScheme = theme;
};

export { THEME_STORAGE_KEY };
