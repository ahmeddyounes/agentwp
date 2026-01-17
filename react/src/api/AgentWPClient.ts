/**
 * Centralized API client for AgentWP.
 *
 * Provides a unified interface for all AgentWP REST API calls with:
 * - Consistent error handling
 * - Request/response transformation
 * - Automatic nonce injection
 * - Error categorization
 *
 * @package AgentWP
 */

const API_NAMESPACE = 'agentwp/v1' as const;

/**
 * Error codes used internally.
 */
const ERROR_CODES = {
  NETWORK_ERROR: 'agentwp_network_error',
  API_ERROR: 'agentwp_api_error',
} as const;

/**
 * Error types for categorization.
 */
const ERROR_TYPES = {
  RATE_LIMIT: 'rate_limit',
  AUTH_ERROR: 'auth_error',
  VALIDATION_ERROR: 'validation_error',
  NETWORK_ERROR: 'network_error',
  API_ERROR: 'api_error',
  UNKNOWN: 'unknown',
} as const;

type ErrorType = (typeof ERROR_TYPES)[keyof typeof ERROR_TYPES];

/**
 * Error types mapping for categorization.
 */
const ERROR_TYPE_MAP: Record<string, ErrorType> = {
  // Rate limiting
  agentwp_rate_limited: ERROR_TYPES.RATE_LIMIT,
  // Authentication/Authorization
  agentwp_forbidden: ERROR_TYPES.AUTH_ERROR,
  agentwp_unauthorized: ERROR_TYPES.AUTH_ERROR,
  agentwp_invalid_key: ERROR_TYPES.AUTH_ERROR,
  agentwp_openai_invalid: ERROR_TYPES.AUTH_ERROR,
  agentwp_missing_nonce: ERROR_TYPES.AUTH_ERROR,
  agentwp_invalid_nonce: ERROR_TYPES.AUTH_ERROR,
  // Validation
  agentwp_invalid_request: ERROR_TYPES.VALIDATION_ERROR,
  agentwp_validation_error: ERROR_TYPES.VALIDATION_ERROR,
  agentwp_invalid_period: ERROR_TYPES.VALIDATION_ERROR,
  agentwp_invalid_theme: ERROR_TYPES.VALIDATION_ERROR,
  agentwp_missing_prompt: ERROR_TYPES.VALIDATION_ERROR,
  // Network/API
  agentwp_network_error: ERROR_TYPES.NETWORK_ERROR,
  agentwp_openai_unreachable: ERROR_TYPES.NETWORK_ERROR,
  agentwp_api_error: ERROR_TYPES.API_ERROR,
  agentwp_encryption_failed: ERROR_TYPES.API_ERROR,
  agentwp_intent_failed: ERROR_TYPES.API_ERROR,
};

/**
 * Default error messages.
 */
const DEFAULT_ERROR_MESSAGES: Record<ErrorType, string> = {
  [ERROR_TYPES.RATE_LIMIT]: 'Too many requests. Please wait and retry.',
  [ERROR_TYPES.AUTH_ERROR]: 'Authentication failed. Please check your API key.',
  [ERROR_TYPES.VALIDATION_ERROR]: 'Please check your request and try again.',
  [ERROR_TYPES.NETWORK_ERROR]: 'Network error. Please check your connection.',
  [ERROR_TYPES.API_ERROR]: 'API server error. Please retry.',
  [ERROR_TYPES.UNKNOWN]: 'An unexpected error occurred.',
};

export interface ApiError {
  code: string;
  message: string;
  type: ErrorType;
  status: number;
  meta: Record<string, unknown>;
  retryAfter: number;
}

export type ApiResponse<T> =
  | { success: true; data: T; meta?: Record<string, unknown> }
  | { success: false; data: []; error: ApiError };

type RequestOptions = Omit<RequestInit, 'headers'> & {
  headers?: Record<string, string>;
  signal?: AbortSignal;
};

/**
 * AgentWP API Client class.
 */
export class AgentWPClient {
  /**
   * Get the REST API base URL.
   */
  getBaseUrl(): string {
    if (typeof window === 'undefined' || !window.agentwpSettings) {
      return `/${API_NAMESPACE}`;
    }
    const { root } = window.agentwpSettings;
    if (!root) {
      return `/${API_NAMESPACE}`;
    }
    // Ensure root ends with slash for proper URL joining.
    const normalizedRoot = root.endsWith('/') ? root : `${root}/`;
    return `${normalizedRoot}${API_NAMESPACE}`;
  }

  /**
   * Get the REST nonce for authenticated requests.
   */
  getNonce(): string {
    if (typeof window === 'undefined' || !window.agentwpSettings) {
      return '';
    }
    return window.agentwpSettings.nonce || '';
  }

  /**
   * Build headers for API requests.
   */
  buildHeaders(customHeaders: Record<string, string> = {}): Record<string, string> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      ...customHeaders,
    };

    const nonce = this.getNonce();
    if (nonce) {
      headers['X-WP-Nonce'] = nonce;
    }

    return headers;
  }

  /**
   * Categorize an error based on error code.
   */
  categorizeError(errorCode: string): ErrorType {
    return ERROR_TYPE_MAP[errorCode] || ERROR_TYPES.UNKNOWN;
  }

  /**
   * Build error object from response.
   */
  buildErrorResponse(response: Response, data: unknown = null): ApiResponse<never> {
    const payloadObject =
      typeof data === 'object' && data !== null ? (data as Record<string, unknown>) : {};
    const errorPayload = (payloadObject.error as Record<string, unknown>) || {};
    const retryAfterHeader = Number.parseInt(response.headers.get('Retry-After') || '0', 10);

    const errorCode = typeof errorPayload.code === 'string' ? errorPayload.code : '';
    const rawType = typeof errorPayload.type === 'string' ? errorPayload.type : '';
    const meta =
      typeof errorPayload.meta === 'object' && errorPayload.meta !== null
        ? (errorPayload.meta as Record<string, unknown>)
        : {};

    const categorized = this.categorizeError(errorCode);
    const errorType: ErrorType = (rawType as ErrorType) || categorized;

    const message =
      (typeof errorPayload.message === 'string' ? errorPayload.message : '') ||
      DEFAULT_ERROR_MESSAGES[errorType] ||
      DEFAULT_ERROR_MESSAGES[ERROR_TYPES.UNKNOWN];

    const retryAfterMetaValue = meta.retry_after;
    let retryAfterMeta = 0;
    if (typeof retryAfterMetaValue === 'number' && Number.isFinite(retryAfterMetaValue)) {
      retryAfterMeta = retryAfterMetaValue;
    } else if (typeof retryAfterMetaValue === 'string') {
      const parsed = Number.parseInt(retryAfterMetaValue, 10);
      retryAfterMeta = Number.isFinite(parsed) ? parsed : 0;
    }

    return {
      success: false,
      data: [] as [],
      error: {
        code: errorCode,
        message,
        type: errorType,
        status: response.status || 0,
        meta,
        retryAfter:
          Number.isFinite(retryAfterHeader) && retryAfterHeader > 0
            ? retryAfterHeader
            : retryAfterMeta,
      },
    };
  }

  /**
   * Handle fetch response with proper categorization.
   * Returns error objects instead of throwing for API errors.
   */
  async handleResponse<T = unknown>(response: Response): Promise<ApiResponse<T>> {
    if (!response.ok) {
      let data: unknown = null;
      try {
        data = await response.json();
      } catch {
        // JSON parse failed, use empty object.
      }
      return this.buildErrorResponse(response, data);
    }

    try {
      return (await response.json()) as ApiResponse<T>;
    } catch {
      // JSON parse failed on successful response - return error object for consistency.
      return {
        success: false,
        data: [] as [],
        error: {
          code: ERROR_CODES.API_ERROR,
          message: DEFAULT_ERROR_MESSAGES[ERROR_TYPES.API_ERROR],
          type: ERROR_TYPES.API_ERROR,
          status: response.status || 0,
          meta: {},
          retryAfter: 0,
        },
      };
    }
  }

  /**
   * Make a generic API request.
   * Returns error response objects for API errors and network failures.
   * Only throws for abort signals to allow proper cancellation handling.
   */
  async request<T = unknown>(
    endpoint: string,
    options: RequestOptions = {},
  ): Promise<ApiResponse<T>> {
    const { signal, ...restOptions } = options;
    const url = `${this.getBaseUrl()}${endpoint}`;

    const config: RequestInit = {
      credentials: 'same-origin',
      signal,
      ...restOptions,
      headers: this.buildHeaders(restOptions.headers || {}),
    };

    try {
      const response = await fetch(url, config);
      return await this.handleResponse<T>(response);
    } catch (error: unknown) {
      // Re-throw abort errors to allow proper cancellation handling.
      if (error instanceof Error && error.name === 'AbortError') {
        throw error;
      }

      const message = error instanceof Error ? error.message : '';

      // Return error object for network failures (consistent with API errors).
      return {
        success: false,
        data: [] as [],
        error: {
          code: ERROR_CODES.NETWORK_ERROR,
          message: message || DEFAULT_ERROR_MESSAGES[ERROR_TYPES.NETWORK_ERROR],
          type: ERROR_TYPES.NETWORK_ERROR,
          status: 0,
          meta: {},
          retryAfter: 0,
        },
      };
    }
  }

  /**
   * Process an intent request.
   */
  async processIntent<T = unknown>(
    prompt: string,
    context: Record<string, unknown> = {},
    options: RequestOptions = {},
  ): Promise<ApiResponse<T>> {
    return await this.request<T>('/intent', {
      method: 'POST',
      body: JSON.stringify({ prompt, context }),
      ...options,
    });
  }

  /**
   * Search orders, products, or customers.
   */
  async search<T = unknown>(
    query: string,
    types: string[] = [],
    options: RequestOptions = {},
  ): Promise<ApiResponse<T>> {
    const params = new URLSearchParams({ q: query });
    if (types.length > 0) {
      params.append('types', types.join(','));
    }
    return await this.request<T>(`/search?${params.toString()}`, {
      method: 'GET',
      ...options,
    });
  }

  /**
   * Get usage statistics.
   */
  async getUsage<T = unknown>(
    period = 'month',
    options: RequestOptions = {},
  ): Promise<ApiResponse<T>> {
    return await this.request<T>(`/usage?period=${encodeURIComponent(period)}`, {
      method: 'GET',
      ...options,
    });
  }

  /**
   * Get settings.
   */
  async getSettings<T = unknown>(options: RequestOptions = {}): Promise<ApiResponse<T>> {
    return await this.request<T>('/settings', {
      method: 'GET',
      ...options,
    });
  }

  /**
   * Update settings.
   */
  async updateSettings<T = unknown>(
    settings: Record<string, unknown>,
    options: RequestOptions = {},
  ): Promise<ApiResponse<T>> {
    return await this.request<T>('/settings', {
      method: 'POST',
      body: JSON.stringify(settings),
      ...options,
    });
  }

  /**
   * Update API key.
   */
  async updateApiKey<T = unknown>(
    apiKey: string,
    options: RequestOptions = {},
  ): Promise<ApiResponse<T>> {
    return await this.request<T>('/settings/api-key', {
      method: 'POST',
      body: JSON.stringify({ api_key: apiKey }),
      ...options,
    });
  }

  /**
   * Get theme preference.
   */
  async getTheme<T = unknown>(options: RequestOptions = {}): Promise<ApiResponse<T>> {
    return await this.request<T>('/theme', {
      method: 'GET',
      ...options,
    });
  }

  /**
   * Update theme preference.
   */
  async updateTheme<T = unknown>(
    theme: string,
    options: RequestOptions = {},
  ): Promise<ApiResponse<T>> {
    return await this.request<T>('/theme', {
      method: 'POST',
      body: JSON.stringify({ theme }),
      ...options,
    });
  }

  /**
   * Get command history.
   */
  async getHistory<T = unknown>(options: RequestOptions = {}): Promise<ApiResponse<T>> {
    return await this.request<T>('/history', {
      method: 'GET',
      ...options,
    });
  }

  /**
   * Update command history.
   */
  async updateHistory<T = unknown>(
    history: unknown[],
    favorites: unknown[],
    options: RequestOptions = {},
  ): Promise<ApiResponse<T>> {
    return await this.request<T>('/history', {
      method: 'POST',
      body: JSON.stringify({ history, favorites }),
      ...options,
    });
  }

  /**
   * Get health status.
   */
  async getHealth<T = unknown>(options: RequestOptions = {}): Promise<ApiResponse<T>> {
    return await this.request<T>('/health', {
      method: 'GET',
      ...options,
    });
  }

  /**
   * Get analytics data.
   */
  async getAnalytics<T = unknown>(
    params: Record<string, string> = {},
    options: RequestOptions = {},
  ): Promise<ApiResponse<T>> {
    const queryString = new URLSearchParams(params).toString();
    return await this.request<T>(`/analytics${queryString ? `?${queryString}` : ''}`, {
      method: 'GET',
      ...options,
    });
  }
}

// Export singleton instance.
export default new AgentWPClient();
