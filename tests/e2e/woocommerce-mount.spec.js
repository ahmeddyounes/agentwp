const { test, expect } = require('@playwright/test');
const { login, openCommandDeck, resetTestData, seedTestData } = require('./helpers/wp');

test.beforeEach(async ({ page }) => {
  await login(page);
  await resetTestData(page);
});

test.describe('WooCommerce Screen Mount Node', () => {
  test('orders page has agentwp-root mount node', async ({ page }) => {
    await seedTestData(page, 'default');
    await page.goto('/wp-admin/edit.php?post_type=shop_order');

    await page.waitForLoadState('networkidle');

    const mountNode = page.locator('#agentwp-root');
    const mountNodeExists = await mountNode.count();
    expect(mountNodeExists).toBeGreaterThan(0);
  });

  test('products page has agentwp-root mount node', async ({ page }) => {
    await seedTestData(page, 'default');
    await page.goto('/wp-admin/edit.php?post_type=product');

    await page.waitForLoadState('networkidle');

    const mountNode = page.locator('#agentwp-root');
    const mountNodeExists = await mountNode.count();
    expect(mountNodeExists).toBeGreaterThan(0);
  });

  test('WooCommerce settings page has agentwp-root mount node', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=wc-settings');

    await page.waitForLoadState('networkidle');

    const mountNode = page.locator('#agentwp-root');
    const mountNodeExists = await mountNode.count();
    expect(mountNodeExists).toBeGreaterThan(0);
  });

  test('agentwpSettings is available on WooCommerce screens', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=wc-settings');

    await page.waitForLoadState('networkidle');

    const settings = await page.evaluate(() => window.agentwpSettings);

    expect(settings).toBeDefined();
    expect(settings.nonce).toBeDefined();
  });

  test('theme attribute is set on WooCommerce screens', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=wc-settings');

    await page.waitForLoadState('networkidle');

    // Check that theme script ran
    const hasThemeScript = await page.evaluate(() => {
      // The theme is applied to document.documentElement or via data-theme attr
      return (
        document.documentElement.classList.contains('agentwp-light') ||
        document.documentElement.classList.contains('agentwp-dark') ||
        document.documentElement.getAttribute('data-theme') !== null
      );
    });

    expect(hasThemeScript).toBeTruthy();
  });

  test('Cmd+K works on WooCommerce orders page', async ({ page }) => {
    await seedTestData(page, 'default');
    await page.goto('/wp-admin/edit.php?post_type=shop_order');

    await page.waitForLoadState('networkidle');

    // Wait for script to register keyboard handler
    await page.waitForTimeout(500);

    await openCommandDeck(page);

    // Check that command deck loading was triggered
    const openState = await page.evaluate(() => {
      try {
        return window.sessionStorage.getItem('agentwp-command-deck-open');
      } catch {
        return null;
      }
    });

    expect(openState).toBe('true');
  });
});

test.describe('WooCommerce Order Detail Integration', () => {
  test('single order edit page has mount node', async ({ page }) => {
    const seed = await seedTestData(page, 'default');

    // Navigate to the order edit page
    await page.goto(`/wp-admin/post.php?post=${seed.order_id}&action=edit`);

    await page.waitForLoadState('networkidle');

    const mountNode = page.locator('#agentwp-root');
    const mountNodeExists = await mountNode.count();
    expect(mountNodeExists).toBeGreaterThan(0);
  });

  test('single product edit page has mount node', async ({ page }) => {
    const seed = await seedTestData(page, 'default');

    // Navigate to the product edit page
    await page.goto(`/wp-admin/post.php?post=${seed.product_id}&action=edit`);

    await page.waitForLoadState('networkidle');

    const mountNode = page.locator('#agentwp-root');
    const mountNodeExists = await mountNode.count();
    expect(mountNodeExists).toBeGreaterThan(0);
  });
});
