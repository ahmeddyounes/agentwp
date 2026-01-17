import { useCallback, useState } from 'react';
import { useModalStore } from '../../stores/useModalStore';
import { PERIOD_OPTIONS } from '../../stores/useAnalyticsStore';
import { useAnalyticsData } from '../../hooks/useAnalytics';
import { useUsageData } from '../../hooks/useUsage';
import { PeriodSelector } from '../analytics';
import { UsageCard } from '../usage';
import { useDemoTour } from '../demo-tour';
import { useThemeStore } from '../../stores/useThemeStore';
import { ANALYTICS_DATA } from '../../utils/analytics-data';
import { formatCurrencyValue } from '../../utils/formatters';
import type { AnalyticsData, Period } from '../../types';

interface LandingPageProps {
  demoMode?: boolean;
  shadowRoot?: ShadowRoot | null;
  budgetLimit?: number;
}

export function LandingPage({ demoMode = false, shadowRoot, budgetLimit = 0 }: LandingPageProps) {
  const { open: openModal, isOpen } = useModalStore();
  const { resolved: theme } = useThemeStore();
  const [selectedPeriod, setSelectedPeriod] = useState<Period>('7d');

  // Analytics data from API
  const { analytics, isLoading: analyticsLoading } = useAnalyticsData(selectedPeriod);

  // Usage data
  const { usage, isLoading: usageLoading } = useUsageData('month');

  // Demo tour
  const { tourSeen, startTour } = useDemoTour({
    demoMode,
    resolvedTheme: theme,
    shadowRoot,
  });

  // Use demo data when in demo mode and no real data
  const displayAnalytics = analytics || (demoMode ? ANALYTICS_DATA[selectedPeriod] : null);

  const handleOpenCommandDeck = useCallback(() => {
    openModal();
  }, [openModal]);

  const handlePeriodChange = useCallback((period: Period) => {
    setSelectedPeriod(period);
  }, []);

  const isMac = typeof navigator !== 'undefined' && /Mac|iPod|iPhone|iPad/.test(navigator.platform);
  const shortcutKey = isMac ? 'Cmd' : 'Ctrl';

  return (
    <main
      className="relative mx-auto flex min-h-screen max-w-5xl animate-fade-in flex-col px-6 py-16 motion-reduce:animate-none"
      aria-hidden={isOpen}
    >
      <header className="max-w-2xl space-y-4" data-tour="hero">
        <div className="flex flex-wrap items-center gap-3">
          <p className="text-xs font-semibold uppercase tracking-[0.4em] text-slate-400">AgentWP</p>
          {demoMode && (
            <span className="inline-flex items-center rounded-full border border-amber-400/60 bg-amber-400/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.3em] text-amber-200">
              Demo
            </span>
          )}
        </div>
        <h1 className="text-4xl font-semibold text-white sm:text-5xl">
          Command Deck: instant actions for your store.
        </h1>
        <p className="text-base text-slate-300 sm:text-lg">
          Invoke the Command Deck with Cmd+K / Ctrl+K or the admin bar button. Responses render as
          markdown, with latency and token cost tracking for quick feedback.
        </p>
        {demoMode && !tourSeen && (
          <button
            type="button"
            onClick={startTour}
            className="inline-flex items-center justify-center rounded-full border border-slate-600/70 bg-slate-900/80 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition hover:border-slate-400/80 hover:bg-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400"
          >
            Take the tour
          </button>
        )}
      </header>

      <section className="mt-10 grid gap-6 sm:grid-cols-2">
        <div
          className="rounded-2xl border border-deck-border bg-deck-surface/80 p-6 shadow-deck"
          data-tour="sample-prompt"
        >
          <h2 className="text-lg font-semibold text-white">Try a sample prompt</h2>
          <p className="mt-2 text-sm text-slate-300">
            &ldquo;Summarize today&apos;s pending orders and draft a response for the two longest
            open tickets.&rdquo;
          </p>
          <button
            type="button"
            onClick={handleOpenCommandDeck}
            data-tour="open-command-deck"
            className="mt-6 inline-flex items-center justify-center gap-2 rounded-full border border-slate-600/60 bg-slate-900/60 px-4 py-2 text-sm font-semibold text-white transition hover:border-slate-400/80 hover:bg-slate-900/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400"
          >
            Open Command Deck
            <span className="rounded-full border border-slate-600/80 bg-slate-950/70 px-2 py-1 text-[11px] text-slate-300">
              {shortcutKey}+K
            </span>
          </button>
        </div>

        <div
          className="rounded-2xl border border-deck-border bg-deck-surface/60 p-6 text-sm text-slate-300 shadow-deck"
          data-tour="status-card"
        >
          <h2 className="text-lg font-semibold text-white">Command Deck status</h2>
          <ul className="mt-3 space-y-2 text-sm">
            <li>Modal state is persisted in session storage.</li>
            <li>Focus is trapped for keyboard-only navigation.</li>
            <li>Responses render markdown with accessible contrast.</li>
          </ul>
        </div>
      </section>

      {/* Usage Summary */}
      {usage && (
        <section className="mt-6">
          <UsageCard usage={usage} budgetLimit={budgetLimit} isLoading={usageLoading} />
        </section>
      )}

      {/* Analytics Section */}
      <section className="mt-10">
        <div
          className="rounded-2xl border border-deck-border bg-deck-surface/70 p-6 shadow-deck"
          data-tour="analytics"
        >
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                Analytics snapshot
              </p>
              <h2 className="mt-2 text-lg font-semibold text-white">
                {PERIOD_OPTIONS.find((p) => p.value === selectedPeriod)?.label ||
                  'Weekly revenue trend'}
              </h2>
              <p className="mt-1 text-sm text-slate-300">
                Track orders and response momentum before drafting outreach.
              </p>
            </div>
            <PeriodSelector value={selectedPeriod} onChange={handlePeriodChange} />
          </div>

          {analyticsLoading ? (
            <div className="mt-6 flex items-center justify-center py-12">
              <div className="h-8 w-8 animate-spin rounded-full border-2 border-slate-600 border-t-sky-400" />
            </div>
          ) : displayAnalytics ? (
            <AnalyticsChart data={displayAnalytics} />
          ) : (
            <div className="mt-6 rounded-2xl border border-slate-800/80 bg-slate-950/50 p-5">
              <p className="py-8 text-center text-sm text-slate-400">No analytics data available</p>
            </div>
          )}
        </div>
      </section>
    </main>
  );
}

// Inline Analytics Chart - uses shared AnalyticsData type
function AnalyticsChart({ data }: { data: AnalyticsData }) {
  const maxValue = Math.max(...data.current, ...(data.previous || []));
  const normalizedCurrent = data.current.map((v) => (v / maxValue) * 100);

  return (
    <div className="mt-6 rounded-2xl border border-slate-800/80 bg-slate-950/50 p-5">
      <div className="flex items-center justify-between text-xs text-slate-400">
        <span>Revenue</span>
        <span>{data.labels.length} days</span>
      </div>
      <div className="mt-4 flex h-40 items-end gap-1">
        {normalizedCurrent.slice(0, 14).map((height, index) => (
          <div
            key={data.labels[index] || index}
            className="flex flex-1 flex-col items-center justify-end gap-1"
          >
            <div
              className="w-full max-w-6 rounded-full bg-gradient-to-b from-sky-400/80 via-sky-500/60 to-sky-700/80"
              style={{ height: `${Math.max(height, 4)}%` }}
            />
          </div>
        ))}
      </div>
      <div className="mt-2 flex justify-between text-[10px] text-slate-500">
        <span>{data.labels[0]}</span>
        <span>{data.labels[data.labels.length - 1]}</span>
      </div>
      {data.categories && (
        <div className="mt-6 grid gap-3 sm:grid-cols-4">
          {data.categories.labels.map((label, index) => (
            <div
              key={label}
              className="rounded-xl border border-slate-800/80 bg-slate-950/40 px-3 py-2"
            >
              <p className="text-xs text-slate-400">{label}</p>
              <p className="text-lg font-semibold text-white">
                {formatCurrencyValue(data.categories?.values[index] ?? 0)}
              </p>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
