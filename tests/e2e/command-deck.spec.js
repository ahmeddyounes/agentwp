const { test, expect } = require('@playwright/test');
const { login, resetTestData } = require('./helpers/wp');

test.beforeEach(async ({ page }) => {
  await login(page);
  await resetTestData(page);
});

test('command deck opens and submits a prompt', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=agentwp');

  const openButton = page.getByRole('button', { name: 'Open Command Deck' });
  await expect(openButton).toBeVisible();
  await openButton.click();

  const promptInput = page.locator('#agentwp-prompt');
  await expect(promptInput).toBeVisible();
  await promptInput.fill('Check order status for a customer');
  await promptInput.press('Enter');

  const response = page.locator('.agentwp-markdown');
  await expect(response).toContainText('order status');
});
