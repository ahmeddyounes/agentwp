const { test, expect } = require('@playwright/test');
const { apiGet, apiPost, login, resetTestData, setApiKey } = require('./helpers/wp');

test.beforeEach(async ({ page }) => {
  await login(page);
  await resetTestData(page);
});

test.describe('Settings Save Flow', () => {
  test('can retrieve default settings', async ({ page }) => {
    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/settings');

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
    expect(json?.data?.settings).toBeDefined();
    expect(json?.data?.api_key_status).toBe('missing');
    expect(json?.data?.has_api_key).toBe(false);
  });

  test('can update settings', async ({ page }) => {
    const newSettings = {
      draft_ttl_minutes: 30,
      enable_demo_mode: true,
    };

    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/settings', newSettings);

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
    expect(json?.data?.updated).toBe(true);
    expect(json?.data?.settings).toBeDefined();
  });

  test('settings persist after update', async ({ page }) => {
    const newSettings = {
      draft_ttl_minutes: 45,
    };

    await apiPost(page, '/wp-json/agentwp/v1/settings', newSettings);

    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/settings');

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
    expect(json?.data?.settings?.draft_ttl_minutes).toBe(45);
  });

  test('can store and retrieve API key last4', async ({ page }) => {
    const testKey = 'sk-test-abcdefghij1234567890';
    const { response: setResponse, json: setJson } = await setApiKey(page, testKey);

    expect(setResponse.ok()).toBeTruthy();
    expect(setJson?.success).toBeTruthy();
    expect(setJson?.data?.stored).toBe(true);
    expect(setJson?.data?.last4).toBe('7890');

    const { response: getResponse, json: getJson } = await apiGet(
      page,
      '/wp-json/agentwp/v1/settings'
    );

    expect(getResponse.ok()).toBeTruthy();
    expect(getJson?.data?.has_api_key).toBe(true);
    expect(getJson?.data?.api_key_status).toBe('stored');
    expect(getJson?.data?.api_key_last4).toBe('7890');
  });

  test('can delete API key by sending empty value', async ({ page }) => {
    const testKey = 'sk-test-abcdefghij1234567890';
    await setApiKey(page, testKey);

    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/settings/api-key', {
      api_key: '',
    });

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
    expect(json?.data?.stored).toBe(false);

    const { json: getJson } = await apiGet(page, '/wp-json/agentwp/v1/settings');
    expect(getJson?.data?.has_api_key).toBe(false);
  });

  test('rejects invalid API key format', async ({ page }) => {
    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/settings/api-key', {
      api_key: 'invalid-key-format',
    });

    expect(response.status()).toBe(400);
    expect(json?.success).toBeFalsy();
  });
});

test.describe('Usage Statistics', () => {
  test('can retrieve usage stats for default period', async ({ page }) => {
    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/usage');

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
    expect(json?.data?.period).toBeDefined();
    expect(typeof json?.data?.total_tokens).toBe('number');
  });

  test('can retrieve usage stats for specific period', async ({ page }) => {
    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/usage?period=30d');

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
    expect(json?.data?.period).toBe('30d');
  });

  test('rejects invalid period', async ({ page }) => {
    const { response, json } = await apiGet(page, '/wp-json/agentwp/v1/usage?period=invalid');

    expect(response.status()).toBe(400);
    expect(json?.success).toBeFalsy();
  });
});
