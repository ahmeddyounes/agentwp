import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { ThemePreference } from '../types';

const THEME_STORAGE_KEY = 'agentwp-theme';

interface ThemeState {
  preference: ThemePreference;
  resolved: 'light' | 'dark';
  systemPrefersDark: boolean;
}

interface ThemeActions {
  setPreference: (preference: ThemePreference) => void;
  setSystemPrefersDark: (prefersDark: boolean) => void;
  toggle: () => void;
}

const resolveTheme = (
  preference: ThemePreference,
  systemPrefersDark: boolean,
): 'light' | 'dark' => {
  if (preference === 'system') {
    return systemPrefersDark ? 'dark' : 'light';
  }
  return preference;
};

const getSystemPrefersDark = (): boolean => {
  if (typeof window === 'undefined' || !window.matchMedia) {
    return false;
  }
  return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const getInitialPreference = (): ThemePreference => {
  if (typeof window === 'undefined') return 'system';

  // First, check localStorage for user's persisted preference
  try {
    const stored = window.localStorage.getItem(THEME_STORAGE_KEY);
    if (stored === 'light' || stored === 'dark' || stored === 'system') {
      return stored;
    }
  } catch {
    // Ignore storage errors
  }

  // Fallback to server-provided theme from window.agentwpSettings
  const serverTheme = window.agentwpSettings?.theme;
  if (serverTheme === 'light' || serverTheme === 'dark') {
    return serverTheme;
  }

  return 'system';
};

export const useThemeStore = create<ThemeState & ThemeActions>()(
  persist(
    (set, get) => ({
      preference: getInitialPreference(),
      systemPrefersDark: getSystemPrefersDark(),
      resolved: resolveTheme(getInitialPreference(), getSystemPrefersDark()),

      setPreference: (preference) => {
        const { systemPrefersDark } = get();
        set({
          preference,
          resolved: resolveTheme(preference, systemPrefersDark),
        });
      },

      setSystemPrefersDark: (prefersDark) => {
        const { preference } = get();
        set({
          systemPrefersDark: prefersDark,
          resolved: resolveTheme(preference, prefersDark),
        });
      },

      toggle: () => {
        const { resolved, systemPrefersDark } = get();
        const newPreference: ThemePreference = resolved === 'dark' ? 'light' : 'dark';
        set({
          preference: newPreference,
          resolved: resolveTheme(newPreference, systemPrefersDark),
        });
      },
    }),
    {
      name: THEME_STORAGE_KEY,
      partialize: (state) => ({ preference: state.preference }),
      onRehydrateStorage: () => (_state, error) => {
        if (error) {
          console.warn('Failed to rehydrate theme store:', error);
        }
      },
    },
  ),
);
