const { test, expect } = require('@playwright/test');
const { apiPost, login, resetTestData, seedTestData } = require('./helpers/wp');

test.beforeEach(async ({ page }) => {
  await login(page);
  await resetTestData(page);
});

test('refund flow prepares and confirms with restock', async ({ page }) => {
  const seed = await seedTestData(page, 'default');

  const { response: prepareResponse, json: prepareJson } = await apiPost(
    page,
    '/wp-json/agentwp-test/v1/refund',
    {
      order_id: seed.order_id,
      restock_items: true,
    }
  );

  expect(prepareResponse.ok()).toBeTruthy();
  expect(prepareJson?.success).toBeTruthy();
  expect(prepareJson?.data?.draft_id).toBeTruthy();

  const { response: confirmResponse, json: confirmJson } = await apiPost(
    page,
    '/wp-json/agentwp-test/v1/refund',
    {
      draft_id: prepareJson.data.draft_id,
    }
  );

  expect(confirmResponse.ok()).toBeTruthy();
  expect(confirmJson?.success).toBeTruthy();
  expect(confirmJson?.data?.confirmed).toBeTruthy();
  expect(confirmJson?.data?.restocked_items?.length).toBeGreaterThan(0);
});

test('status update flow prepares and confirms', async ({ page }) => {
  const seed = await seedTestData(page, 'default');

  const { response: prepareResponse, json: prepareJson } = await apiPost(
    page,
    '/wp-json/agentwp-test/v1/status',
    {
      order_id: seed.order_id,
      new_status: 'completed',
      notify_customer: false,
    }
  );

  expect(prepareResponse.ok()).toBeTruthy();
  expect(prepareJson?.success).toBeTruthy();
  expect(prepareJson?.data?.draft_id).toBeTruthy();

  const { response: confirmResponse, json: confirmJson } = await apiPost(
    page,
    '/wp-json/agentwp-test/v1/status',
    {
      draft_id: prepareJson.data.draft_id,
    }
  );

  expect(confirmResponse.ok()).toBeTruthy();
  expect(confirmJson?.success).toBeTruthy();
  expect(confirmJson?.data?.new_status).toBe('completed');
});

test('stock update flow prepares and confirms', async ({ page }) => {
  const seed = await seedTestData(page, 'default');

  const { response: prepareResponse, json: prepareJson } = await apiPost(
    page,
    '/wp-json/agentwp-test/v1/stock',
    {
      product_id: seed.product_id,
      operation: 'decrease',
      quantity: 1,
    }
  );

  expect(prepareResponse.ok()).toBeTruthy();
  expect(prepareJson?.success).toBeTruthy();
  expect(prepareJson?.data?.draft_id).toBeTruthy();

  const { response: confirmResponse, json: confirmJson } = await apiPost(
    page,
    '/wp-json/agentwp-test/v1/stock',
    {
      draft_id: prepareJson.data.draft_id,
    }
  );

  expect(confirmResponse.ok()).toBeTruthy();
  expect(confirmJson?.success).toBeTruthy();
  expect(confirmJson?.data?.new_stock).toBe(seed.stock_quantity - 1);
});

test('stock update rejects missing operation', async ({ page }) => {
  const seed = await seedTestData(page, 'default');

  const { response, json } = await apiPost(page, '/wp-json/agentwp-test/v1/stock', {
    product_id: seed.product_id,
    quantity: 2,
  });

  expect(response.ok()).toBeFalsy();
  expect(json?.success).toBeFalsy();
});

test('refund handles huge order totals', async ({ page }) => {
  const seed = await seedTestData(page, 'huge');

  const { response: prepareResponse, json: prepareJson } = await apiPost(
    page,
    '/wp-json/agentwp-test/v1/refund',
    {
      order_id: seed.order_id,
    }
  );

  expect(prepareResponse.ok()).toBeTruthy();
  expect(prepareJson?.success).toBeTruthy();
  expect(prepareJson?.data?.draft_id).toBeTruthy();
});
