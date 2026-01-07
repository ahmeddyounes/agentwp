const { test, expect } = require('@playwright/test');
const { apiPost, login, resetTestData, seedTestData, setApiKey } = require('./helpers/wp');

const TEST_API_KEY = process.env.AGENTWP_OPENAI_API_KEY || 'sk-test-1234';

test.beforeEach(async ({ page }) => {
  await login(page);
  await resetTestData(page);
});

test('stores API key using OpenAI validation', async ({ page }) => {
  const { response, json } = await setApiKey(page, TEST_API_KEY);

  expect(response.ok()).toBeTruthy();
  expect(json?.success).toBeTruthy();
  expect(json?.data?.stored).toBeTruthy();
  expect(json?.data?.last4).toBe(TEST_API_KEY.slice(-4));
});

test('drafts email using recorded OpenAI response', async ({ page }) => {
  const seed = await seedTestData(page, 'default');
  const { response: keyResponse } = await setApiKey(page, TEST_API_KEY);

  expect(keyResponse.ok()).toBeTruthy();

  const { response, json } = await apiPost(page, '/wp-json/agentwp-test/v1/email-draft', {
    order_id: seed.order_id,
    intent: 'shipping_update',
    tone: 'friendly',
  });

  expect(response.ok()).toBeTruthy();
  expect(json?.success).toBeTruthy();
  expect(json?.data?.email_body).toContain('order is on the way');
  expect(json?.data?.subject_line).toBeTruthy();
});
