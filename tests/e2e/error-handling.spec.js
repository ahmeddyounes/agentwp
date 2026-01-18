const { test, expect } = require('@playwright/test');
const { apiGet, apiPost, login, resetTestData, seedTestData, setApiKey } = require('./helpers/wp');

test.beforeEach(async ({ page }) => {
  await login(page);
  await resetTestData(page);
});

test.describe('REST API Error Responses', () => {
  test('intent endpoint returns error for empty prompt', async ({ page }) => {
    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/intent', {
      prompt: '',
    });

    expect(response.status()).toBe(400);
    expect(json?.success).toBeFalsy();
    expect(json?.error).toBeDefined();
    expect(json?.error?.code).toBe('agentwp_missing_prompt');
    expect(json?.error?.message).toBeDefined();
  });

  test('settings API key endpoint rejects invalid key format', async ({ page }) => {
    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/settings/api-key', {
      api_key: 'not-a-valid-key',
    });

    expect(response.status()).toBe(400);
    expect(json?.success).toBeFalsy();
    expect(json?.error?.code).toBeDefined();
  });

  test('search endpoint handles empty query gracefully', async ({ page }) => {
    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/search?q=');

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
  });

  test('usage endpoint rejects invalid period', async ({ page }) => {
    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/usage?period=invalid');

    expect(response.status()).toBe(400);
    expect(json?.success).toBeFalsy();
    expect(json?.error?.code).toBeDefined();
  });
});

test.describe('WooCommerce Operation Errors', () => {
  test('refund rejects invalid order ID', async ({ page }) => {
    const { response, json } = await apiPost(page, '/wp-json/agentwp-test/v1/refund', {
      order_id: 999999,
    });

    expect(response.ok()).toBeFalsy();
    expect(json?.success).toBeFalsy();
  });

  test('status update rejects invalid order ID', async ({ page }) => {
    const { response, json } = await apiPost(page, '/wp-json/agentwp-test/v1/status', {
      order_id: 999999,
      new_status: 'completed',
    });

    expect(response.ok()).toBeFalsy();
    expect(json?.success).toBeFalsy();
  });

  test('stock update rejects missing operation', async ({ page }) => {
    const seed = await seedTestData(page, 'default');

    const { response, json } = await apiPost(page, '/wp-json/agentwp-test/v1/stock', {
      product_id: seed.product_id,
      quantity: 5,
    });

    expect(response.status()).toBe(400);
    expect(json?.success).toBeFalsy();
  });

  test('stock update rejects invalid product ID', async ({ page }) => {
    const { response, json } = await apiPost(page, '/wp-json/agentwp-test/v1/stock', {
      product_id: 999999,
      quantity: 5,
      operation: 'increase',
    });

    expect(response.ok()).toBeFalsy();
    expect(json?.success).toBeFalsy();
  });

  test('intent flow rejects missing prompt', async ({ page }) => {
    const seed = await seedTestData(page, 'default');

    const { response, json } = await apiPost(page, '/wp-json/agentwp-test/v1/intent-flow', {
      order_id: seed.order_id,
      tone: 'friendly',
    });

    expect(response.status()).toBe(400);
    expect(json?.success).toBeFalsy();
  });

  test('intent flow rejects missing order ID', async ({ page }) => {
    const { response, json } = await apiPost(page, '/wp-json/agentwp-test/v1/intent-flow', {
      prompt: 'Draft an email',
      tone: 'friendly',
    });

    expect(response.status()).toBe(400);
    expect(json?.success).toBeFalsy();
  });
});

test.describe('Authentication and Authorization', () => {
  test('settings endpoint requires authentication', async ({ page }) => {
    await page.context().clearCookies();

    const response = await page.request.get('/wp-json/agentwp/v1/settings');

    expect(response.status()).toBe(401);
  });

  test('intent endpoint requires authentication', async ({ page }) => {
    await page.context().clearCookies();

    const response = await page.request.post('/wp-json/agentwp/v1/intent', {
      data: { prompt: 'test' },
    });

    expect(response.status()).toBe(401);
  });

  test('test endpoints require proper permissions', async ({ page }) => {
    await page.context().clearCookies();

    const response = await page.request.post('/wp-json/agentwp-test/v1/reset', {
      data: {},
    });

    expect(response.status()).toBe(401);
  });
});

test.describe('Error Response Format', () => {
  test('error responses have consistent structure', async ({ page }) => {
    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/intent', {
      prompt: '',
    });

    expect(response.status()).toBe(400);
    expect(json).toMatchObject({
      success: false,
      data: expect.any(Object),
      error: expect.objectContaining({
        code: expect.any(String),
        message: expect.any(String),
      }),
    });
  });

  test('success responses have consistent structure', async ({ page }) => {
    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/settings');

    expect(response.ok()).toBeTruthy();
    expect(json).toMatchObject({
      success: true,
      data: expect.any(Object),
    });
  });
});

test.describe('Health Endpoint', () => {
  test('health endpoint is publicly accessible', async ({ page }) => {
    await page.context().clearCookies();

    const response = await page.request.get('/wp-json/agentwp/v1/health');

    expect(response.ok()).toBeTruthy();
  });

  test('health endpoint returns no-cache headers', async ({ page }) => {
    const response = await page.request.get('/wp-json/agentwp/v1/health');
    const headers = response.headers();

    expect(response.ok()).toBeTruthy();
    expect(headers['cache-control']).toContain('no-store');
  });
});
