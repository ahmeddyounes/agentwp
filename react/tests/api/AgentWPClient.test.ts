import { describe, expect, it } from 'vitest';
import { http, HttpResponse } from 'msw';
import agentwpClient from '../../src/api/AgentWPClient';
import { server } from '../mocks/server';

const API_BASE = '/wp-json/agentwp/v1';

describe('AgentWPClient envelope handling', () => {
  it('returns data for success envelopes', async () => {
    const response = await agentwpClient.getHealth();

    expect(response.success).toBe(true);
    if (response.success) {
      expect(response.data).toHaveProperty('status', 'healthy');
    }
  });

  it('normalizes error envelopes with status and retryAfter', async () => {
    server.use(
      http.get(`${API_BASE}/settings`, () => {
        return HttpResponse.json(
          {
            success: false,
            data: [],
            error: {
              code: 'agentwp_unauthorized',
              message: 'Authentication required. Please log in.',
              type: 'auth_error',
              meta: {
                retry_after: 5,
              },
            },
          },
          { status: 401, headers: { 'Retry-After': '60' } },
        );
      }),
    );

    const response = await agentwpClient.getSettings();

    expect(response.success).toBe(false);
    if (!response.success) {
      expect(response.error.code).toBe('agentwp_unauthorized');
      expect(response.error.type).toBe('auth_error');
      expect(response.error.status).toBe(401);
      expect(response.error.retryAfter).toBe(60);
      expect(response.error.meta).toMatchObject({ retry_after: 5 });
    }
  });
});
