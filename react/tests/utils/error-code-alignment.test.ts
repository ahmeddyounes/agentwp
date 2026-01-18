/**
 * Error Code Alignment Test
 *
 * Ensures that the frontend error code mapping (AGENTWP_ERROR_MESSAGES)
 * covers all backend AgentWPConfig error codes used by the API.
 *
 * If this test fails, it means a new backend error code was added
 * without a corresponding frontend mapping. Update AGENTWP_ERROR_MESSAGES
 * in src/utils/error.ts with a user-friendly message for the new code.
 */
import { describe, it, expect } from 'vitest';
import { AGENTWP_ERROR_MESSAGES } from '../../src/utils/error';

/**
 * Backend error codes from AgentWPConfig.php.
 * These must stay in sync with src/Config/AgentWPConfig.php.
 *
 * When adding new error codes to AgentWPConfig.php:
 * 1. Add the code to this array
 * 2. Add a user-friendly message to AGENTWP_ERROR_MESSAGES in src/utils/error.ts
 * 3. Run tests to verify alignment
 */
const BACKEND_ERROR_CODES = [
  // Authentication/Authorization
  'agentwp_forbidden',
  'agentwp_unauthorized',
  'agentwp_missing_nonce',
  'agentwp_invalid_nonce',
  'agentwp_invalid_key',
  // Validation
  'agentwp_invalid_request',
  'agentwp_validation_error',
  'agentwp_missing_prompt',
  'agentwp_invalid_period',
  'agentwp_invalid_theme',
  // API/Network
  'agentwp_rate_limited',
  'agentwp_api_error',
  'agentwp_network_error',
  'agentwp_intent_failed',
  'agentwp_openai_unreachable',
  'agentwp_openai_invalid',
  'agentwp_encryption_failed',
  'agentwp_service_unavailable',
] as const;

describe('Error Code Alignment', () => {
  describe('frontend covers all backend error codes', () => {
    it.each(BACKEND_ERROR_CODES)('AGENTWP_ERROR_MESSAGES includes mapping for "%s"', (code) => {
      expect(AGENTWP_ERROR_MESSAGES).toHaveProperty(code);
      const message = AGENTWP_ERROR_MESSAGES[code];
      expect(typeof message).toBe('string');
      expect(message?.length).toBeGreaterThan(0);
    });
  });

  it('frontend does not have orphaned error codes', () => {
    const frontendCodes = Object.keys(AGENTWP_ERROR_MESSAGES);
    const backendCodesSet = new Set<string>(BACKEND_ERROR_CODES);

    const orphanedCodes = frontendCodes.filter((code) => !backendCodesSet.has(code));

    expect(orphanedCodes).toEqual([]);
  });

  it('all error messages are non-empty strings', () => {
    for (const [, message] of Object.entries(AGENTWP_ERROR_MESSAGES)) {
      expect(typeof message).toBe('string');
      expect(message.trim().length).toBeGreaterThan(0);
    }
  });
});
