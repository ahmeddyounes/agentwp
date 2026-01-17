/**
 * Main AgentWP admin application.
 * Refactored from ~3,353 lines to a slim orchestration layer.
 */
import { useLayoutEffect, useEffect, useState, useCallback, useRef } from 'react';
import { createPortal } from 'react-dom';
import { LandingPage } from './features/landing';
import { CommandDeck } from './features/command-deck';
import { useModalStore } from './stores/useModalStore';
import { useThemeStore } from './stores/useThemeStore';
import { useKeyboardShortcuts } from './hooks/useKeyboardShortcuts';
import { usePrefersDark } from './hooks/usePrefersDark';
import { applyTheme } from './theme';
import agentwpClient from './api/AgentWPClient';
import { THEME_TRANSITION_MS } from './utils/constants';
import 'shepherd.js/dist/css/shepherd.css';

interface AppProps {
  shadowRoot?: ShadowRoot | null;
  portalRoot?: HTMLElement | null;
  themeTarget?: HTMLElement | null;
}

export default function App({
  shadowRoot = null,
  portalRoot = null,
  themeTarget = null,
}: AppProps) {
  const isOpen = useModalStore((s) => s.isOpen);
  const { resolved: theme, preference: themePreference, setSystemPrefersDark } = useThemeStore();

  // Track demo mode and budget limit from settings
  const [demoMode, setDemoMode] = useState(false);
  const [budgetLimit, setBudgetLimit] = useState(0);
  const hasAppliedThemeRef = useRef(false);
  const themeTransitionRef = useRef<number | null>(null);

  // Global keyboard shortcuts (Cmd+K, Escape)
  useKeyboardShortcuts({ shadowRoot });

  // System theme preference detection
  const prefersDark = usePrefersDark();

  // Sync system preference to store
  useEffect(() => {
    setSystemPrefersDark(prefersDark);
  }, [prefersDark, setSystemPrefersDark]);

  // Resolve theme target element
  const themeRoot =
    themeTarget ||
    (shadowRoot ? (shadowRoot.host as HTMLElement) : null) ||
    (typeof document !== 'undefined' ? document.documentElement : null);

  // Apply theme with transition effect
  useLayoutEffect(() => {
    if (!themeRoot) {
      return undefined;
    }

    // Add transition class if not first render
    if (hasAppliedThemeRef.current) {
      themeRoot.classList.add('awp-theme-transition');
      if (themeTransitionRef.current) {
        window.clearTimeout(themeTransitionRef.current);
      }
      themeTransitionRef.current = window.setTimeout(() => {
        themeRoot.classList.remove('awp-theme-transition');
      }, THEME_TRANSITION_MS);
    }

    applyTheme(theme, themeRoot);
    hasAppliedThemeRef.current = true;

    return () => {
      if (themeTransitionRef.current) {
        window.clearTimeout(themeTransitionRef.current);
      }
    };
  }, [theme, themeRoot]);

  // Persist theme preference to server when changed
  useEffect(() => {
    if (themePreference === 'system') {
      return;
    }
    // Fire and forget - don't block on server response
    agentwpClient.updateTheme(themePreference).catch(() => {
      // Ignore theme persistence errors
    });
  }, [themePreference]);

  // Fetch settings (demo mode, budget limit) on mount
  const fetchSettings = useCallback(async () => {
    type SettingsResponseData = {
      settings?: {
        budget_limit?: unknown;
        demo_mode?: unknown;
      };
    };

    try {
      const payload = await agentwpClient.getSettings<SettingsResponseData>();
      if (payload.success === false) {
        return;
      }
      const settings = payload.data.settings;
      if (settings) {
        const limit = Number.parseFloat(String(settings.budget_limit ?? 0));
        setBudgetLimit(Number.isFinite(limit) && limit >= 0 ? limit : 0);
        setDemoMode(Boolean(settings.demo_mode));
      }
    } catch {
      // Ignore settings fetch failures
    }
  }, []);

  useEffect(() => {
    fetchSettings();
  }, [fetchSettings]);

  // Get initial demo mode from window settings
  useEffect(() => {
    if (typeof window !== 'undefined') {
      const initialDemoMode = Boolean(window.agentwpSettings?.demoMode);
      if (initialDemoMode) {
        setDemoMode(initialDemoMode);
      }
    }
  }, []);

  return (
    <div className="agentwp-app min-h-screen text-slate-100">
      <LandingPage demoMode={demoMode} shadowRoot={shadowRoot} budgetLimit={budgetLimit} />

      {isOpen &&
        portalRoot &&
        createPortal(<CommandDeck onClose={() => useModalStore.getState().close()} />, portalRoot)}
    </div>
  );
}
