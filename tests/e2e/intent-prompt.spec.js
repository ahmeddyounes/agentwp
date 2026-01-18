const { test, expect } = require('@playwright/test');
const { apiPost, login, resetTestData, seedTestData, setApiKey } = require('./helpers/wp');

const TEST_API_KEY = process.env.AGENTWP_OPENAI_API_KEY || 'sk-test-1234';

test.beforeEach(async ({ page }) => {
  await login(page);
  await resetTestData(page);
});

test.describe('Intent Prompt Validation', () => {
  test('rejects empty prompt', async ({ page }) => {
    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/intent', {
      prompt: '',
    });

    expect(response.status()).toBe(400);
    expect(json?.success).toBeFalsy();
    expect(json?.error?.code).toBe('agentwp_missing_prompt');
  });

  test('rejects missing prompt field', async ({ page }) => {
    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/intent', {});

    expect(response.status()).toBe(400);
    expect(json?.success).toBeFalsy();
  });

  test('accepts prompt with context', async ({ page }) => {
    await setApiKey(page, TEST_API_KEY);
    const seed = await seedTestData(page, 'default');

    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/intent', {
      prompt: 'Check the status of this order',
      context: {
        order_id: seed.order_id,
      },
    });

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
    expect(json?.data?.intent_id).toBeDefined();
    expect(json?.data?.status).toBe('handled');
  });

  test('accepts prompt with metadata', async ({ page }) => {
    await setApiKey(page, TEST_API_KEY);

    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/intent', {
      prompt: 'Show me analytics for the week',
      metadata: {
        source: 'command_deck',
        session_id: 'test-session-123',
      },
    });

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
  });
});

test.describe('Intent Classification', () => {
  test.beforeEach(async ({ page }) => {
    await setApiKey(page, TEST_API_KEY);
  });

  test('classifies order status query', async ({ page }) => {
    const seed = await seedTestData(page, 'default');

    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/intent', {
      prompt: 'What is the status of order ' + seed.order_id + '?',
      context: { order_id: seed.order_id },
    });

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
    expect(json?.data?.intent).toBeDefined();
  });

  test('classifies refund request', async ({ page }) => {
    const seed = await seedTestData(page, 'default');

    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/intent', {
      prompt: 'Process a refund for order ' + seed.order_id,
      context: { order_id: seed.order_id },
    });

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
  });

  test('classifies stock update request', async ({ page }) => {
    const seed = await seedTestData(page, 'default');

    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/intent', {
      prompt: 'Update stock for product ' + seed.product_id + ' to 50 units',
      context: { product_id: seed.product_id },
    });

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
  });

  test('classifies email draft request', async ({ page }) => {
    const seed = await seedTestData(page, 'default');

    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/intent', {
      prompt: 'Draft a shipping notification email for order ' + seed.order_id,
      context: { order_id: seed.order_id },
    });

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
  });

  test('classifies analytics query', async ({ page }) => {
    const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/intent', {
      prompt: 'Show me sales analytics for the last 7 days',
    });

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
  });
});

test.describe('Intent Flow Integration', () => {
  test('full email draft flow', async ({ page }) => {
    await setApiKey(page, TEST_API_KEY);
    const seed = await seedTestData(page, 'default');

    const { response, json } = await apiPost(page, '/wp-json/agentwp-test/v1/intent-flow', {
      prompt: 'Please draft a shipping update email.',
      order_id: seed.order_id,
      tone: 'friendly',
      email_intent: 'shipping_update',
    });

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
    expect(json?.data?.engine?.intent).toBe('EMAIL_DRAFT');
    expect(json?.data?.draft?.email_body).toBeDefined();
  });

  test('full email draft with professional tone', async ({ page }) => {
    await setApiKey(page, TEST_API_KEY);
    const seed = await seedTestData(page, 'default');

    const { response, json } = await apiPost(page, '/wp-json/agentwp-test/v1/intent-flow', {
      prompt: 'Please draft a formal order confirmation email.',
      order_id: seed.order_id,
      tone: 'professional',
      email_intent: 'order_confirmation',
    });

    expect(response.ok()).toBeTruthy();
    expect(json?.success).toBeTruthy();
  });
});
