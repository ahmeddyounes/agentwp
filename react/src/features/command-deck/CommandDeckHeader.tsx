import type { ThemePreference } from '../../types';

interface CommandDeckHeaderProps {
  theme: 'light' | 'dark';
  themePreference: ThemePreference;
  onThemeToggle: () => void;
  onClose: () => void;
  isOffline?: boolean;
}

export function CommandDeckHeader({
  theme,
  themePreference,
  onThemeToggle,
  onClose,
  isOffline = false,
}: CommandDeckHeaderProps) {
  return (
    <div className="flex items-center justify-between border-b border-slate-700/60 px-4 py-3">
      <div className="flex items-center gap-3">
        <h2 className="text-sm font-semibold text-white">AgentWP</h2>
        {isOffline && (
          <span className="flex items-center gap-1.5 text-xs text-amber-400">
            <span className="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true" />
            Offline
          </span>
        )}
      </div>

      <div className="flex items-center gap-2">
        <button
          onClick={onThemeToggle}
          className="rounded-md p-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
          aria-label={`Switch to ${theme === 'dark' ? 'light' : 'dark'} mode`}
          title={`Current: ${themePreference === 'system' ? 'System' : themePreference}`}
        >
          {theme === 'dark' ? <SunIcon /> : <MoonIcon />}
        </button>

        <button
          onClick={onClose}
          className="rounded-md p-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
          aria-label="Close command deck"
        >
          <CloseIcon />
        </button>
      </div>
    </div>
  );
}

function SunIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4">
      <path
        d="M12 4.2v1.9m0 11.8v1.9M4.2 12h1.9m11.8 0h1.9M6.4 6.4l1.4 1.4m8.4 8.4 1.4 1.4M6.4 17.6l1.4-1.4m8.4-8.4 1.4-1.4"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="round"
      />
      <circle cx="12" cy="12" r="4.2" fill="none" stroke="currentColor" strokeWidth="1.6" />
    </svg>
  );
}

function MoonIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4">
      <path
        d="M15.5 4.5c-4 0-7.2 3.2-7.2 7.2 0 3.7 2.8 6.8 6.5 7.2 3.4.3 6.6-1.6 7.9-4.7-1.1.4-2.4.5-3.6.3-3.6-.5-6.4-3.7-6.4-7.3 0-1 .2-2 .6-2.9"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function CloseIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4">
      <path
        d="M6 6l12 12M6 18L18 6"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
      />
    </svg>
  );
}
