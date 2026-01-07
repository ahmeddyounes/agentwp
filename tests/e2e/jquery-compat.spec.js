const { test, expect } = require('@playwright/test');
const { login, resetTestData } = require('./helpers/wp');

test.beforeEach(async ({ page }) => {
  await login(page);
  await resetTestData(page);
});

test('jQuery is available on AgentWP admin screen', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=agentwp');

  const hasJquery = await page.evaluate(() => {
    return (
      typeof window.jQuery !== 'undefined' &&
      typeof window.jQuery.fn === 'object' &&
      typeof window.jQuery.fn.jquery === 'string'
    );
  });

  expect(hasJquery).toBeTruthy();
});
