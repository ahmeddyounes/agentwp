import { http, HttpResponse } from 'msw';

const API_BASE = '/wp-json/agentwp/v1';

// Mock data
const mockHealthResponse = {
  success: true,
  data: {
    status: 'healthy',
    timestamp: Date.now(),
  },
};

const mockUsageResponse = {
  success: true,
  data: {
    total_tokens: 150000,
    total_cost_usd: 0.45,
    breakdown_by_intent: [
      { intent: 'analytics', count: 25, tokens: 50000, cost: 0.15 },
      { intent: 'search', count: 50, tokens: 75000, cost: 0.22 },
      { intent: 'other', count: 10, tokens: 25000, cost: 0.08 },
    ],
    daily_trend: [
      { date: '2024-01-01', tokens: 10000, cost: 0.03 },
      { date: '2024-01-02', tokens: 15000, cost: 0.045 },
      { date: '2024-01-03', tokens: 12000, cost: 0.036 },
    ],
    period_start: '2024-01-01',
    period_end: '2024-01-31',
  },
};

const mockAnalyticsResponse = {
  success: true,
  data: {
    label: 'Last 7 days',
    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    current: [4200, 4360, 4520, 4680, 4840, 5000, 5160],
    previous: [3900, 4020, 4140, 4260, 4380, 4500, 4620],
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
};

const mockSearchResponse = {
  success: true,
  data: {
    products: [
      { id: 1, title: 'Blue Widget', subtitle: '$19.99' },
      { id: 2, title: 'Red Widget', subtitle: '$24.99' },
    ],
    orders: [{ id: 101, title: 'Order #101', subtitle: 'Pending' }],
    customers: [{ id: 201, title: 'John Doe', subtitle: 'john@example.com' }],
  },
};

const mockSettingsResponse = {
  success: true,
  data: {
    theme: 'system',
    budget_limit: 10.0,
    demo_mode: false,
  },
};

const mockIntentResponse = {
  success: true,
  data: {
    response: 'Here are the sales analytics for the last 7 days...',
    intent: 'analytics',
    metrics: {
      latency_ms: 1200,
      token_cost: 0.002,
    },
  },
};

// Handlers
export const handlers = [
  // Health check
  http.get(`${API_BASE}/health`, () => {
    return HttpResponse.json(mockHealthResponse);
  }),

  // Usage
  http.get(`${API_BASE}/usage`, () => {
    return HttpResponse.json(mockUsageResponse);
  }),

  // Analytics
  http.get(`${API_BASE}/analytics`, () => {
    return HttpResponse.json(mockAnalyticsResponse);
  }),

  // Search
  http.get(`${API_BASE}/search`, ({ request }) => {
    const url = new URL(request.url);
    const query = url.searchParams.get('q');

    if (!query || query.length < 2) {
      return HttpResponse.json({
        success: true,
        data: { products: [], orders: [], customers: [] },
      });
    }

    return HttpResponse.json(mockSearchResponse);
  }),

  // Settings
  http.get(`${API_BASE}/settings`, () => {
    return HttpResponse.json(mockSettingsResponse);
  }),

  http.post(`${API_BASE}/settings`, async ({ request }) => {
    const body = (await request.json()) as Record<string, unknown>;
    return HttpResponse.json({
      success: true,
      data: { ...mockSettingsResponse.data, ...body },
    });
  }),

  // Theme
  http.get(`${API_BASE}/theme`, () => {
    return HttpResponse.json({
      success: true,
      data: { theme: 'system' },
    });
  }),

  http.post(`${API_BASE}/theme`, async ({ request }) => {
    const body = (await request.json()) as { theme: string };
    return HttpResponse.json({
      success: true,
      data: { theme: body.theme },
    });
  }),

  // Intent processing
  http.post(`${API_BASE}/intent`, () => {
    return HttpResponse.json(mockIntentResponse);
  }),

  // History
  http.get(`${API_BASE}/history`, () => {
    return HttpResponse.json({
      success: true,
      data: { history: [], favorites: [] },
    });
  }),

  http.post(`${API_BASE}/history`, () => {
    return HttpResponse.json({ success: true });
  }),
];

// Error handlers for testing error scenarios
export const errorHandlers = {
  networkError: http.get(`${API_BASE}/health`, () => {
    return HttpResponse.error();
  }),

  rateLimited: http.post(`${API_BASE}/intent`, () => {
    return HttpResponse.json(
      {
        success: false,
        data: [],
        error: {
          code: 'agentwp_rate_limited',
          message: 'Too many requests. Please wait and retry.',
          type: 'rate_limit',
          meta: {
            retry_after: 60,
          },
        },
      },
      { status: 429, headers: { 'Retry-After': '60' } },
    );
  }),

  unauthorized: http.get(`${API_BASE}/settings`, () => {
    return HttpResponse.json(
      {
        success: false,
        data: [],
        error: {
          code: 'agentwp_unauthorized',
          message: 'Authentication required. Please log in.',
          type: 'auth_error',
          meta: {},
        },
      },
      { status: 401 },
    );
  }),

  serverError: http.post(`${API_BASE}/intent`, () => {
    return HttpResponse.json(
      {
        success: false,
        data: [],
        error: {
          code: 'agentwp_api_error',
          message: 'Internal server error',
          type: 'api_error',
          meta: {},
        },
      },
      { status: 500 },
    );
  }),
};
