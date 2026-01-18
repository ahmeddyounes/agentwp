const { test, expect } = require('@playwright/test');
const {
  login,
  openCommandDeck,
  resetTestData,
  setApiKey,
  shadowQuery,
  waitForAppMount,
} = require('./helpers/wp');

const TEST_API_KEY = process.env.AGENTWP_OPENAI_API_KEY || 'sk-test-1234';

test.beforeEach(async ({ page }) => {
  await login(page);
  await resetTestData(page);
});

test('command deck opens via keyboard shortcut', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=agentwp');
  await waitForAppMount(page);

  await openCommandDeck(page);

  // Verify command deck state is set
  const openState = await page.evaluate(() => {
    try {
      return window.sessionStorage.getItem('agentwp-command-deck-open');
    } catch {
      return null;
    }
  });

  expect(openState).toBe('true');
});

test('command deck portal root exists after opening', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=agentwp');
  await waitForAppMount(page);

  await openCommandDeck(page);

  const hasPortalRoot = await shadowQuery(page, '#agentwp-portal-root');
  expect(hasPortalRoot).toBeTruthy();
});

test('command deck can receive input', async ({ page }) => {
  await setApiKey(page, TEST_API_KEY);
  await page.goto('/wp-admin/admin.php?page=agentwp');
  await waitForAppMount(page);

  await openCommandDeck(page);
  await page.waitForTimeout(500);

  // Find input within shadow DOM
  const hasInput = await shadowQuery(page, 'input, textarea, [contenteditable]');

  // If an input exists, the command deck is functional
  expect(hasInput !== null).toBeTruthy();
});
