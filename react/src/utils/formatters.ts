/**
 * Number and currency formatting utilities.
 */

export const currencyFormatter = new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD',
  maximumFractionDigits: 0,
});

export const usageCurrencyFormatter = new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

export const numberFormatter = new Intl.NumberFormat('en-US');

export const formatCurrencyValue = (value: unknown): string => {
  if (typeof value !== 'number' || Number.isNaN(value)) {
    return value?.toString() ?? '';
  }
  return currencyFormatter.format(value);
};

export const formatUsageCost = (value: unknown): string => {
  if (typeof value !== 'number' || Number.isNaN(value)) {
    return usageCurrencyFormatter.format(0);
  }
  return usageCurrencyFormatter.format(value);
};

export const formatTokenCount = (value: unknown): string => {
  const parsed = typeof value === 'number' ? value : Number.parseInt(String(value ?? 0), 10);
  if (Number.isNaN(parsed)) {
    return numberFormatter.format(0);
  }
  return numberFormatter.format(parsed);
};
