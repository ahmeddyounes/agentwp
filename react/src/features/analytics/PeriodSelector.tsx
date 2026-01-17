import type { Period } from '../../types';
import { PERIOD_OPTIONS } from '../../stores';

interface PeriodSelectorProps {
  value: Period;
  onChange: (period: Period) => void;
  disabled?: boolean;
}

export function PeriodSelector({ value, onChange, disabled = false }: PeriodSelectorProps) {
  return (
    <div className="flex items-center gap-1 rounded-lg border border-slate-700/60 bg-slate-900/60 p-1">
      {PERIOD_OPTIONS.map((option) => (
        <button
          key={option.value}
          onClick={() => onChange(option.value)}
          disabled={disabled}
          className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
            value === option.value
              ? 'bg-indigo-500 text-white'
              : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'
          } disabled:cursor-not-allowed disabled:opacity-50`}
          aria-pressed={value === option.value}
        >
          {option.label}
        </button>
      ))}
    </div>
  );
}
