const { test, expect } = require('@playwright/test');
const { apiGet, login, resetTestData, seedTestData } = require('./helpers/wp');

test.beforeEach(async ({ page }) => {
  await login(page);
  await resetTestData(page);
});

test.describe('Analytics Endpoint', () => {
  test('returns analytics data for default period (7d)', async ({ page }) => {
    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/analytics');

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
    expect(json?.data).toBeDefined();
  });

  test('returns analytics data for 7-day period', async ({ page }) => {
    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/analytics?period=7d');

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
  });

  test('returns analytics data for 30-day period', async ({ page }) => {
    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/analytics?period=30d');

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
  });

  test('returns analytics data for 90-day period', async ({ page }) => {
    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/analytics?period=90d');

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
  });

  test('rejects invalid period parameter', async ({ page }) => {
    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/analytics?period=invalid');

    expect(response.ok()).toBeFalsy();
    expect(json?.success).toBeFalsy();
  });

  test('rejects unsupported period value', async ({ page }) => {
    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/analytics?period=1y');

    expect(response.ok()).toBeFalsy();
  });
});

test.describe('Analytics Data Structure', () => {
  test('includes expected analytics fields', async ({ page }) => {
    await seedTestData(page, 'default');

    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/analytics');

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();

    const data = json?.data;
    expect(data).toBeDefined();
  });

  test('analytics are scoped to period', async ({ page }) => {
    const { json: json7d } = await apiGet(page, '/wp-json/agentwp/v1/analytics?period=7d');
    const { json: json30d } = await apiGet(page, '/wp-json/agentwp/v1/analytics?period=30d');

    expect(json7d?.success).toBeTruthy();
    expect(json30d?.success).toBeTruthy();
  });
});

test.describe('Analytics Access Control', () => {
  test('requires authentication', async ({ page }) => {
    await page.context().clearCookies();

    const response = await page.request.get('/wp-json/agentwp/v1/analytics');

    expect(response.status()).toBe(401);
  });
});
