const { test, expect } = require('@playwright/test');
const { apiGet, apiPost, login, resetTestData } = require('./helpers/wp');

test.beforeEach(async ({ page }) => {
  await login(page);
  await resetTestData(page);
});

test('settings endpoint returns defaults', async ({ page }) => {
  const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/settings');

  expect(response.ok()).toBeTruthy();
  expect(json?.success).toBeTruthy();
  expect(json?.data?.settings).toBeTruthy();
});

test('health endpoint sends no-cache headers', async ({ page }) => {
  const response = await page.request.get('/wp-json/agentwp/v1/health');
  const headers = response.headers();

  expect(response.ok()).toBeTruthy();
  expect(headers['cache-control']).toContain('no-store');
});

test('intent rejects empty prompt', async ({ page }) => {
  const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/intent', { prompt: '' });

  expect(response.status()).toBe(400);
  expect(json?.success).toBeFalsy();
  expect(json?.error?.code).toBe('agentwp_missing_prompt');
});

test('search returns empty results on empty store', async ({ page }) => {
  const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/search?q=hoodie');

  expect(response.ok()).toBeTruthy();
  expect(json?.success).toBeTruthy();
  const results = json?.data?.results || {};
  expect(Array.isArray(results.products)).toBeTruthy();
  expect(Array.isArray(results.orders)).toBeTruthy();
  expect(Array.isArray(results.customers)).toBeTruthy();
  expect(results.products).toHaveLength(0);
  expect(results.orders).toHaveLength(0);
  expect(results.customers).toHaveLength(0);
});
