/**
 * Error handling utilities and error message constants.
 */

// Error message constants
export const DEFAULT_ERROR_MESSAGE = 'AgentWP ran into a problem. Please try again.';
export const NETWORK_ERROR_MESSAGE =
  'Network connection lost. Check your connection and try again.';
export const RATE_LIMIT_MESSAGE = 'Too many requests right now. Please wait and retry.';
export const AUTH_ERROR_MESSAGE = 'Authorization failed. Check your API key and permissions.';
export const VALIDATION_ERROR_MESSAGE = 'Please check your request and try again.';
export const OFFLINE_BANNER_TEXT = 'Agent Offline';

export const OPENAI_ERROR_CODE_MESSAGES: Record<string, string> = {
  invalid_api_key: 'The OpenAI API key is invalid. Update it in settings.',
  insufficient_quota: 'OpenAI billing quota has been exhausted. Check your plan or usage.',
  rate_limit_exceeded: 'OpenAI rate limit reached. Please wait and retry.',
  context_length_exceeded: 'This request is too long for the model. Try shortening it.',
  model_not_found: 'The selected model is unavailable. Try again later.',
};

export const OPENAI_ERROR_TYPE_MESSAGES: Record<string, string> = {
  authentication_error: 'OpenAI authentication failed. Check your API key.',
  rate_limit_error: 'OpenAI rate limit reached. Please wait and retry.',
  invalid_request_error: 'This request was rejected by OpenAI. Please try again.',
  server_error: 'OpenAI is having trouble right now. Please retry.',
  overloaded_error: 'OpenAI is overloaded right now. Please retry.',
};

export const AGENTWP_ERROR_MESSAGES: Record<string, string> = {
  // Rate limiting
  agentwp_rate_limited: 'Too many requests. Please wait and retry.',
  // Authentication/Authorization
  agentwp_forbidden: 'You do not have permission to use AgentWP.',
  agentwp_unauthorized: 'Authentication required. Please log in.',
  agentwp_invalid_key: 'The OpenAI API key is invalid. Update it in settings.',
  agentwp_openai_invalid: 'OpenAI rejected the API key. Please check your credentials.',
  agentwp_missing_nonce: 'Security nonce is missing. Please refresh the page.',
  agentwp_invalid_nonce: 'Invalid security nonce. Please refresh the page.',
  // Validation
  agentwp_invalid_request: 'Please check your request and try again.',
  agentwp_invalid_period: 'Invalid time period selected.',
  agentwp_invalid_theme: 'Theme must be light or dark.',
  agentwp_missing_prompt: 'Please enter a prompt to continue.',
  // Network/API
  agentwp_network_error: 'Network error. Please check your connection.',
  agentwp_openai_unreachable: 'Cannot reach OpenAI servers. Please check your connection.',
  agentwp_api_error: 'API error occurred. Please try again.',
  agentwp_encryption_failed: 'Failed to encrypt API key. Please try again.',
  // Intent processing
  agentwp_intent_failed: 'Failed to process your request. Please try again.',
};

export type ErrorType =
  | 'network_error'
  | 'rate_limit'
  | 'auth_error'
  | 'validation_error'
  | 'api_error'
  | 'unknown';

interface ErrorInput {
  code?: string;
  type?: string;
  status?: number | string;
  message?: string;
  meta?: {
    error_code?: string;
    error_type?: string;
    [key: string]: unknown;
  };
  retryAfter?: number | string;
}

export interface ErrorState {
  message: string;
  code: string;
  type: ErrorType;
  status: number;
  meta: Record<string, unknown>;
  retryAfter: number;
  retryable: boolean;
}

const isStackTraceMessage = (value: unknown): boolean => {
  if (typeof value !== 'string') {
    return false;
  }
  return /stack|stacktrace|traceback|at\s+\w+/i.test(value);
};

const sanitizeErrorMessage = (value: unknown): string => {
  const message = typeof value === 'string' ? value.trim() : '';
  if (!message || isStackTraceMessage(message)) {
    return '';
  }
  return message;
};

export const resolveErrorType = ({ code, type, status, message, meta }: ErrorInput): ErrorType => {
  const normalizedStatus = Number.isFinite(status as number)
    ? (status as number)
    : Number.parseInt(String(status ?? 0), 10);
  if (type) {
    return type as ErrorType;
  }
  const codeValue = `${code ?? ''}`.toLowerCase();
  const metaCode = `${meta?.error_code ?? ''}`.toLowerCase();
  const metaType = `${meta?.error_type ?? ''}`.toLowerCase();
  const combined = [codeValue, metaCode, metaType].filter(Boolean).join(' ');
  const messageValue = `${message ?? ''}`.toLowerCase();

  if (
    normalizedStatus === 0 ||
    messageValue.includes('failed to fetch') ||
    messageValue.includes('network') ||
    messageValue.includes('timeout')
  ) {
    return 'network_error';
  }
  if (normalizedStatus === 429 || combined.includes('rate')) {
    return 'rate_limit';
  }
  if (
    normalizedStatus === 401 ||
    normalizedStatus === 403 ||
    combined.includes('auth') ||
    combined.includes('invalid_api_key') ||
    combined.includes('authentication')
  ) {
    return 'auth_error';
  }
  if (
    normalizedStatus === 400 ||
    normalizedStatus === 422 ||
    combined.includes('invalid') ||
    combined.includes('missing') ||
    combined.includes('validation')
  ) {
    return 'validation_error';
  }
  if (normalizedStatus >= 500) {
    return 'api_error';
  }
  return 'unknown';
};

export const resolveFriendlyMessage = ({
  message,
  code,
  type,
  status,
  meta,
}: ErrorInput): string => {
  const openAiCode = typeof meta?.error_code === 'string' ? meta.error_code.toLowerCase() : '';
  const openAiType = typeof meta?.error_type === 'string' ? meta.error_type.toLowerCase() : '';

  if (openAiCode && OPENAI_ERROR_CODE_MESSAGES[openAiCode]) {
    return OPENAI_ERROR_CODE_MESSAGES[openAiCode];
  }
  if (openAiType && OPENAI_ERROR_TYPE_MESSAGES[openAiType]) {
    return OPENAI_ERROR_TYPE_MESSAGES[openAiType];
  }
  const normalizedCode = typeof code === 'string' ? code.toLowerCase() : '';
  if (normalizedCode && AGENTWP_ERROR_MESSAGES[normalizedCode]) {
    return AGENTWP_ERROR_MESSAGES[normalizedCode];
  }
  if (status === 429) {
    return RATE_LIMIT_MESSAGE;
  }
  if (status === 401 || status === 403) {
    return AUTH_ERROR_MESSAGE;
  }
  if (type === 'network_error') {
    return NETWORK_ERROR_MESSAGE;
  }
  if (type === 'rate_limit') {
    return RATE_LIMIT_MESSAGE;
  }
  if (type === 'auth_error') {
    return AUTH_ERROR_MESSAGE;
  }
  if (type === 'validation_error') {
    return sanitizeErrorMessage(message) || VALIDATION_ERROR_MESSAGE;
  }
  return sanitizeErrorMessage(message) || DEFAULT_ERROR_MESSAGE;
};

export const buildErrorState = ({
  message,
  code,
  type,
  status,
  meta,
  retryAfter,
}: ErrorInput): ErrorState => {
  const resolvedType = resolveErrorType({ code, type, status, message, meta });
  const resolvedMessage = resolveFriendlyMessage({
    message,
    code,
    type: resolvedType,
    status,
    meta,
  });

  return {
    message: resolvedMessage,
    code: code || '',
    type: resolvedType,
    status: Number.isFinite(status as number)
      ? (status as number)
      : Number.parseInt(String(status ?? 0), 10) || 0,
    meta: meta && typeof meta === 'object' ? meta : {},
    retryAfter: Number.isFinite(retryAfter as number)
      ? (retryAfter as number)
      : Number.parseInt(String(retryAfter ?? 0), 10) || 0,
    retryable: ['network_error', 'rate_limit', 'api_error'].includes(resolvedType),
  };
};
