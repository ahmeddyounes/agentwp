// Common types for the AgentWP React application

/**
 * Error type categories for error handling and display.
 */
export type ErrorType =
  | 'network_error'
  | 'rate_limit'
  | 'auth_error'
  | 'validation_error'
  | 'api_error'
  | 'unknown';

/**
 * Structured error state used throughout the application.
 * This is the canonical error representation for UI display and error handling.
 */
export interface ErrorState {
  /** Human-friendly error message for display */
  message: string;
  /** Error code identifier */
  code: string;
  /** Categorized error type */
  type: ErrorType;
  /** HTTP status code */
  status: number;
  /** Additional metadata from the error response */
  meta: Record<string, unknown>;
  /** Seconds to wait before retrying (for rate limit errors) */
  retryAfter: number;
  /** Whether this error can be retried */
  retryable: boolean;
}

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

/**
 * Draft types for different intents.
 */
export type DraftType = 'refund' | 'status' | 'stock' | 'email';

/**
 * Base preview structure with common fields across all draft types.
 * The `summary` field provides a human-readable one-liner for display.
 */
export interface BaseDraftPreview {
  /** Human-readable one-liner summarizing the draft operation */
  summary: string;
}

/**
 * Preview data for refund drafts.
 */
export interface RefundPreview extends BaseDraftPreview {
  order_id: number;
  amount: number;
  currency: string;
  reason: string;
  restock_items: boolean;
  customer_name: string;
}

/**
 * Preview data for single order status update drafts.
 */
export interface StatusPreview extends BaseDraftPreview {
  order_id: number;
  current_status: string;
  new_status: string;
  notify_customer: boolean;
  warning: string;
}

/**
 * Preview data for bulk order status update drafts.
 */
export interface BulkStatusPreview extends BaseDraftPreview {
  count: number;
  new_status: string;
  notify_customer: boolean;
  warning: string;
  orders: Array<{
    id: number;
    current: string;
    new: string;
  }>;
}

/**
 * Preview data for stock update drafts.
 */
export interface StockPreview extends BaseDraftPreview {
  product_id: number;
  product_name: string;
  product_sku: string;
  original_stock: number;
  new_stock: number;
}

/**
 * Context data for email drafts.
 */
export interface EmailContext extends BaseDraftPreview {
  order_id: number;
  customer: string;
  total: number;
  currency: string;
  status: string;
  items: string[];
  date: string;
}

/**
 * Union type for all draft preview types.
 */
export type DraftPreview =
  | RefundPreview
  | StatusPreview
  | BulkStatusPreview
  | StockPreview
  | EmailContext;

/**
 * Unified draft response structure returned by prepare_* tools.
 * All draft types follow this consistent shape.
 */
export interface DraftResponse {
  /** Unique draft identifier (type-prefixed, e.g., 'ref_abc123') */
  draft_id: string;
  /** Draft type identifier */
  type: DraftType;
  /** Human-readable preview data (always includes `summary` field) */
  preview: DraftPreview;
  /** Unix timestamp when the draft expires */
  expires_at: number;
  /** Time-to-live in seconds */
  ttl: number;
  /** Success indicator */
  success: boolean;
  /** Human-readable message for display */
  message: string;
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
