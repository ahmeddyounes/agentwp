/**
 * Analytics mock data and utilities for demo mode.
 */

import type { AnalyticsData, Period } from '../types';

export const buildDayLabels = (days: number, prefix = 'Day'): string[] =>
  Array.from({ length: days }, (_, index) => `${prefix} ${index + 1}`);

export const buildRevenueSeries = (
  days: number,
  base: number,
  trend: number,
  variance: number,
  phase = 0,
): number[] =>
  Array.from({ length: days }, (_, index) =>
    Math.round(base + index * trend + Math.sin(index * 0.45 + phase) * variance),
  );

export const hexToRgba = (hex: string, alpha: number): string => {
  const cleanHex = hex.replace('#', '');
  if (cleanHex.length !== 6) {
    return hex;
  }
  const r = parseInt(cleanHex.slice(0, 2), 16);
  const g = parseInt(cleanHex.slice(2, 4), 16);
  const b = parseInt(cleanHex.slice(4, 6), 16);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
};

// Use shared AnalyticsData type from types/index.ts
export const ANALYTICS_DATA: Record<Period, AnalyticsData> = {
  '7d': {
    label: 'Last 7 days',
    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    current: buildRevenueSeries(7, 4200, 160, 420, 0.2),
    previous: buildRevenueSeries(7, 3900, 120, 360, 1.1),
    metrics: {
      labels: ['Revenue', 'Shipping', 'Discounts', 'Returns'],
      current: [128000, 18200, 9600, 4100],
      previous: [116500, 16750, 10100, 4600],
    },
    categories: {
      labels: ['Accessories', 'Home', 'Wellness', 'Apparel'],
      values: [45200, 38200, 29600, 15000],
    },
  },
  '30d': {
    label: 'Last 30 days',
    labels: buildDayLabels(30),
    current: buildRevenueSeries(30, 3800, 55, 520, 0.3),
    previous: buildRevenueSeries(30, 3600, 45, 480, 1.2),
    metrics: {
      labels: ['Revenue', 'Shipping', 'Discounts', 'Returns'],
      current: [540000, 61200, 45200, 12300],
      previous: [498000, 58500, 47600, 13800],
    },
    categories: {
      labels: ['Accessories', 'Home', 'Wellness', 'Apparel'],
      values: [188000, 162000, 115000, 75000],
    },
  },
  '90d': {
    label: 'Last 90 days',
    labels: buildDayLabels(90, 'D'),
    current: buildRevenueSeries(90, 3500, 22, 620, 0.4),
    previous: buildRevenueSeries(90, 3300, 18, 560, 1.4),
    metrics: {
      labels: ['Revenue', 'Shipping', 'Discounts', 'Returns'],
      current: [1480000, 166500, 128000, 44200],
      previous: [1375000, 158000, 141000, 46800],
    },
    categories: {
      labels: ['Accessories', 'Home', 'Wellness', 'Apparel'],
      values: [510000, 436000, 318000, 216000],
    },
  },
};
