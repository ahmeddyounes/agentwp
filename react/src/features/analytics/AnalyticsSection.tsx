import { Suspense } from 'react';
import type { Period, AnalyticsData } from '../../types';
import { PeriodSelector } from './PeriodSelector';

interface AnalyticsSectionProps {
  data: AnalyticsData | null;
  period: Period;
  onPeriodChange: (period: Period) => void;
  isLoading: boolean;
  error?: string | null;
  children?: React.ReactNode;
}

export function AnalyticsSection({
  data,
  period,
  onPeriodChange,
  isLoading,
  error,
  children,
}: AnalyticsSectionProps) {
  return (
    <section className="space-y-4" aria-labelledby="analytics-heading">
      <div className="flex items-center justify-between">
        <h3 id="analytics-heading" className="text-sm font-semibold text-white">
          Analytics
        </h3>
        <PeriodSelector value={period} onChange={onPeriodChange} disabled={isLoading} />
      </div>

      {error && (
        <div
          className="rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-300"
          role="alert"
        >
          {error}
        </div>
      )}

      {isLoading && !data && (
        <div className="flex items-center justify-center py-8">
          <LoadingSpinner />
        </div>
      )}

      {data && (
        <Suspense fallback={<ChartPlaceholder />}>
          <div className="space-y-4">{children}</div>
        </Suspense>
      )}
    </section>
  );
}

function LoadingSpinner() {
  return (
    <div className="flex items-center gap-2 text-slate-400">
      <svg
        className="h-5 w-5 animate-spin"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        viewBox="0 0 24 24"
        aria-hidden="true"
      >
        <circle
          className="opacity-25"
          cx="12"
          cy="12"
          r="10"
          stroke="currentColor"
          strokeWidth="4"
        />
        <path
          className="opacity-75"
          fill="currentColor"
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
        />
      </svg>
      <span>Loading analytics...</span>
    </div>
  );
}

function ChartPlaceholder() {
  return <div className="h-40 animate-pulse rounded-lg bg-slate-800/50" />;
}

export { PeriodSelector };
