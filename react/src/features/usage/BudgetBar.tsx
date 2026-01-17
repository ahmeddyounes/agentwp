interface BudgetBarProps {
  percentage: number;
  isOverBudget?: boolean;
}

export function BudgetBar({ percentage, isOverBudget = false }: BudgetBarProps) {
  const clampedPercentage = Math.min(Math.max(percentage, 0), 100);

  let barColor = 'bg-indigo-500';
  if (percentage >= 90) {
    barColor = 'bg-red-500';
  } else if (percentage >= 75) {
    barColor = 'bg-amber-500';
  }

  return (
    <div className="space-y-1.5">
      <div className="flex items-center justify-between text-xs">
        <span className="text-slate-400">Budget Usage</span>
        <span className={isOverBudget ? 'font-medium text-red-400' : 'text-slate-300'}>
          {clampedPercentage.toFixed(1)}%
        </span>
      </div>
      <div
        className="h-2 overflow-hidden rounded-full bg-slate-800"
        role="progressbar"
        aria-valuenow={clampedPercentage}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={`Budget usage: ${clampedPercentage.toFixed(1)}%`}
      >
        <div
          className={`h-full transition-all duration-300 ${barColor}`}
          style={{ width: `${clampedPercentage}%` }}
        />
      </div>
      {isOverBudget && (
        <p className="text-xs text-red-400" role="alert">
          Budget limit exceeded
        </p>
      )}
    </div>
  );
}
