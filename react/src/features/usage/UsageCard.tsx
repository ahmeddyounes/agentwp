import type { UsageSummary } from '../../types';
import { BudgetBar } from './BudgetBar';
import { usageCurrencyFormatter, numberFormatter } from '../../utils/formatters';

interface UsageCardProps {
  usage: UsageSummary;
  budgetLimit: number;
  isLoading?: boolean;
}

export function UsageCard({ usage, budgetLimit, isLoading = false }: UsageCardProps) {
  const budgetPercentage = budgetLimit > 0 ? (usage.totalCostUsd / budgetLimit) * 100 : 0;
  const isOverBudget = budgetLimit > 0 && usage.totalCostUsd >= budgetLimit;

  if (isLoading) {
    return (
      <div className="animate-pulse rounded-xl border border-slate-700/60 bg-slate-900/60 p-4">
        <div className="h-4 w-24 rounded bg-slate-800" />
        <div className="mt-3 h-8 w-32 rounded bg-slate-800" />
        <div className="mt-4 h-2 rounded-full bg-slate-800" />
      </div>
    );
  }

  return (
    <div className="rounded-xl border border-slate-700/60 bg-slate-900/60 p-4">
      <h4 className="text-xs font-medium uppercase tracking-wider text-slate-400">
        Usage This Period
      </h4>

      <div className="mt-3 grid grid-cols-2 gap-4">
        <div>
          <p className="text-2xl font-semibold text-white">
            {usageCurrencyFormatter.format(usage.totalCostUsd)}
          </p>
          <p className="text-xs text-slate-500">Total Cost</p>
        </div>
        <div>
          <p className="text-2xl font-semibold text-white">
            {numberFormatter.format(usage.totalTokens)}
          </p>
          <p className="text-xs text-slate-500">Tokens Used</p>
        </div>
      </div>

      {budgetLimit > 0 && (
        <div className="mt-4">
          <BudgetBar percentage={budgetPercentage} isOverBudget={isOverBudget} />
          <p className="mt-1 text-xs text-slate-500">
            Limit: {usageCurrencyFormatter.format(budgetLimit)}
          </p>
        </div>
      )}

      {usage.breakdownByIntent.length > 0 && (
        <div className="mt-4 border-t border-slate-700/60 pt-4">
          <p className="mb-2 text-xs font-medium uppercase tracking-wider text-slate-400">
            By Intent
          </p>
          <div className="space-y-2">
            {usage.breakdownByIntent.slice(0, 3).map((item) => (
              <div key={item.intent} className="flex items-center justify-between text-sm">
                <span className="capitalize text-slate-300">{item.intent}</span>
                <span className="text-slate-400">{usageCurrencyFormatter.format(item.cost)}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
