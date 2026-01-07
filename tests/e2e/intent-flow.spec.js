const { test, expect } = require('@playwright/test');
const { apiPost, login, resetTestData, seedTestData, setApiKey } = require('./helpers/wp');

const TEST_API_KEY = process.env.AGENTWP_OPENAI_API_KEY || 'sk-test-1234';

test.beforeEach(async ({ page }) => {
  await login(page);
  await resetTestData(page);
});

test('intent flow runs from prompt to draft', async ({ page }) => {
  const seed = await seedTestData(page, 'default');
  await setApiKey(page, TEST_API_KEY);

  const { response, json } = await apiPost(page, '/wp-json/agentwp-test/v1/intent-flow', {
    prompt: 'Please draft a shipping update email.',
    order_id: seed.order_id,
    tone: 'friendly',
    email_intent: 'shipping_update',
  });

  expect(response.ok()).toBeTruthy();
  expect(json?.success).toBeTruthy();
  expect(json?.data?.engine?.intent).toBe('EMAIL_DRAFT');
  expect(json?.data?.draft?.email_body).toContain('order is on the way');
});
