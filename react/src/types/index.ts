// Common types for the AgentWP React application

export interface UsageSummary {
  totalTokens: number;
  totalCostUsd: number;
  breakdownByIntent: IntentBreakdown[];
  dailyTrend: DailyUsage[];
  periodStart: string;
  periodEnd: string;
}

export interface IntentBreakdown {
  intent: string;
  count: number;
  tokens: number;
  cost: number;
}

export interface DailyUsage {
  date: string;
  tokens: number;
  cost: number;
}

export interface Metrics {
  latencyMs: number | null;
  tokenCost: number | null;
}

export interface SearchResults {
  products: SearchResult[];
  orders: SearchResult[];
  customers: SearchResult[];
}

export interface SearchResult {
  id: string | number;
  title: string;
  subtitle?: string;
  type: 'products' | 'orders' | 'customers';
}

export interface CommandEntry {
  id: string;
  prompt: string;
  response: string;
  timestamp: number;
  metrics?: Metrics;
}

export interface DraftEntry {
  id: string;
  subject: string;
  body: string;
  timestamp: number;
}

export interface AnalyticsData {
  label: string;
  labels: string[];
  current: number[];
  previous: number[];
  metrics: {
    labels: string[];
    current: number[];
    previous: number[];
  };
  categories: {
    labels: string[];
    values: number[];
  };
}

export type ThemePreference = 'light' | 'dark' | 'system';
export type Period = '7d' | '30d' | '90d';

export interface AgentWPSettings {
  root?: string;
  nonce?: string;
  theme?: string;
  supportEmail?: string;
  version?: string;
  demoMode?: boolean;
  [key: string]: unknown;
}

declare global {
  interface Window {
    agentwpSettings?: AgentWPSettings;
  }
}
