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

const API_NAMESPACE = 'agentwp/v1';

/**
 * Error codes used internally
 */
const ERROR_CODES = {
  NETWORK_ERROR: 'agentwp_network_error',
  API_ERROR: 'agentwp_api_error',
};

/**
 * Error types for categorization
 */
const ERROR_TYPES = {
  RATE_LIMIT: 'rate_limit',
  AUTH_ERROR: 'auth_error',
  VALIDATION_ERROR: 'validation_error',
  NETWORK_ERROR: 'network_error',
  API_ERROR: 'api_error',
  UNKNOWN: 'unknown',
};

/**
 * Error types mapping for categorization
 */
const ERROR_TYPE_MAP = {
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
 * Default error messages
 */
const DEFAULT_ERROR_MESSAGES = {
  [ERROR_TYPES.RATE_LIMIT]: 'Too many requests. Please wait and retry.',
  [ERROR_TYPES.AUTH_ERROR]: 'Authentication failed. Please check your API key.',
  [ERROR_TYPES.VALIDATION_ERROR]: 'Please check your request and try again.',
  [ERROR_TYPES.NETWORK_ERROR]: 'Network error. Please check your connection.',
  [ERROR_TYPES.API_ERROR]: 'API server error. Please retry.',
  [ERROR_TYPES.UNKNOWN]: 'An unexpected error occurred.',
};

/**
 * AgentWP API Client class
 */
export class AgentWPClient {
  /**
   * Get the REST API base URL
   */
  getBaseUrl() {
    if (typeof window === 'undefined' || !window.agentwpSettings) {
      return `/${API_NAMESPACE}`;
    }
    const { root } = window.agentwpSettings;
    if (!root) {
      return `/${API_NAMESPACE}`;
    }
    // Ensure root ends with slash for proper URL joining
    const normalizedRoot = root.endsWith('/') ? root : `${root}/`;
    return `${normalizedRoot}${API_NAMESPACE}`;
  }

  /**
   * Get the REST nonce for authenticated requests
   */
  getNonce() {
    if (typeof window === 'undefined' || !window.agentwpSettings) {
      return '';
    }
    return window.agentwpSettings.nonce || '';
  }

  /**
   * Build headers for API requests
   */
  buildHeaders(customHeaders = {}) {
    const headers = {
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
   * Categorize an error based on error code
   */
  categorizeError(errorCode) {
    return ERROR_TYPE_MAP[errorCode] || ERROR_TYPES.UNKNOWN;
  }

  /**
   * Build error object from response
   */
  buildErrorResponse(response, data = null) {
    const errorPayload = data?.error || {};
    const retryAfterHeader = Number.parseInt(
      response.headers.get('Retry-After') || 0,
      10
    );

    const errorCode = errorPayload?.code || '';
    const errorType = errorPayload?.type || this.categorizeError(errorCode);

    return {
      success: false,
      data: [],
      error: {
        code: errorCode,
        message: errorPayload?.message || DEFAULT_ERROR_MESSAGES[errorType] || DEFAULT_ERROR_MESSAGES[ERROR_TYPES.UNKNOWN],
        type: errorType,
        status: response.status || 0,
        meta: errorPayload?.meta || {},
        retryAfter:
          Number.isFinite(retryAfterHeader) && retryAfterHeader > 0
            ? retryAfterHeader
            : errorPayload?.meta?.retry_after || 0,
      },
    };
  }

  /**
   * Handle fetch response with proper categorization.
   * Returns error objects instead of throwing for API errors.
   */
  async handleResponse(response) {
    if (!response.ok) {
      let data = null;
      try {
        data = await response.json();
      } catch (e) {
        // JSON parse failed, use empty object
      }
      return this.buildErrorResponse(response, data);
    }

    try {
      return await response.json();
    } catch (e) {
      // JSON parse failed on successful response - return error object for consistency
      return {
        success: false,
        data: [],
        error: {
          code: ERROR_CODES.API_ERROR,
          message: DEFAULT_ERROR_MESSAGES[ERROR_TYPES.API_ERROR],
          type: ERROR_TYPES.API_ERROR,
          status: response.status,
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
  async request(endpoint, options = {}) {
    const { signal, ...restOptions } = options;
    const url = `${this.getBaseUrl()}${endpoint}`;

    const config = {
      credentials: 'same-origin',
      signal,
      ...restOptions,
      headers: this.buildHeaders(restOptions.headers || {}),
    };

    try {
      const response = await fetch(url, config);
      return await this.handleResponse(response);
    } catch (error) {
      // Re-throw abort errors to allow proper cancellation handling
      if (error?.name === 'AbortError') {
        throw error;
      }

      // Return error object for network failures (consistent with API errors)
      return {
        success: false,
        data: [],
        error: {
          code: ERROR_CODES.NETWORK_ERROR,
          message: error.message || DEFAULT_ERROR_MESSAGES[ERROR_TYPES.NETWORK_ERROR],
          type: ERROR_TYPES.NETWORK_ERROR,
          status: 0,
          meta: {},
          retryAfter: 0,
        },
      };
    }
  }

  /**
   * Process an intent request
   * @param {string} prompt - The user prompt
   * @param {Object} context - Optional context data
   * @param {Object} options - Optional request options (e.g., { signal: AbortSignal })
   */
  async processIntent(prompt, context = {}, options = {}) {
    return await this.request('/intent', {
      method: 'POST',
      body: JSON.stringify({ prompt, context }),
      ...options,
    });
  }

  /**
   * Search orders, products, or customers
   * @param {string} query - The search query
   * @param {string[]} types - Optional array of types to search
   * @param {Object} options - Optional request options (e.g., { signal: AbortSignal })
   */
  async search(query, types = [], options = {}) {
    const params = new URLSearchParams({ q: query });
    if (types.length > 0) {
      params.append('types', types.join(','));
    }
    return await this.request(`/search?${params.toString()}`, {
      method: 'GET',
      ...options,
    });
  }

  /**
   * Get usage statistics
   * @param {string} period - The period to get usage for
   * @param {Object} options - Optional request options (e.g., { signal: AbortSignal })
   */
  async getUsage(period = 'month', options = {}) {
    return await this.request(`/usage?period=${encodeURIComponent(period)}`, {
      method: 'GET',
      ...options,
    });
  }

  /**
   * Get settings
   * @param {Object} options - Optional request options (e.g., { signal: AbortSignal })
   */
  async getSettings(options = {}) {
    return await this.request('/settings', {
      method: 'GET',
      ...options,
    });
  }

  /**
   * Update settings
   * @param {Object} settings - Settings to update
   * @param {Object} options - Optional request options (e.g., { signal: AbortSignal })
   */
  async updateSettings(settings, options = {}) {
    return await this.request('/settings', {
      method: 'POST',
      body: JSON.stringify(settings),
      ...options,
    });
  }

  /**
   * Update API key
   * @param {string} apiKey - The API key to store
   * @param {Object} options - Optional request options (e.g., { signal: AbortSignal })
   */
  async updateApiKey(apiKey, options = {}) {
    return await this.request('/settings/api-key', {
      method: 'POST',
      body: JSON.stringify({ api_key: apiKey }),
      ...options,
    });
  }

  /**
   * Get theme preference
   * @param {Object} options - Optional request options (e.g., { signal: AbortSignal })
   */
  async getTheme(options = {}) {
    return await this.request('/theme', {
      method: 'GET',
      ...options,
    });
  }

  /**
   * Update theme preference
   * @param {string} theme - The theme preference ('light' or 'dark')
   * @param {Object} options - Optional request options (e.g., { signal: AbortSignal })
   */
  async updateTheme(theme, options = {}) {
    return await this.request('/theme', {
      method: 'POST',
      body: JSON.stringify({ theme }),
      ...options,
    });
  }

  /**
   * Get command history
   * @param {Object} options - Optional request options (e.g., { signal: AbortSignal })
   */
  async getHistory(options = {}) {
    return await this.request('/history', {
      method: 'GET',
      ...options,
    });
  }

  /**
   * Update command history
   * @param {Array} history - History entries
   * @param {Array} favorites - Favorite entries
   * @param {Object} options - Optional request options (e.g., { signal: AbortSignal })
   */
  async updateHistory(history, favorites, options = {}) {
    return await this.request('/history', {
      method: 'POST',
      body: JSON.stringify({ history, favorites }),
      ...options,
    });
  }

  /**
   * Get health status
   * @param {Object} options - Optional request options (e.g., { signal: AbortSignal })
   */
  async getHealth(options = {}) {
    return await this.request('/health', {
      method: 'GET',
      ...options,
    });
  }

  /**
   * Get analytics data
   * @param {Object} params - Query parameters
   * @param {Object} options - Optional request options (e.g., { signal: AbortSignal })
   */
  async getAnalytics(params = {}, options = {}) {
    const queryString = new URLSearchParams(params).toString();
    return await this.request(`/analytics${queryString ? `?${queryString}` : ''}`, {
      method: 'GET',
      ...options,
    });
  }
}

// Export singleton instance
export default new AgentWPClient();
