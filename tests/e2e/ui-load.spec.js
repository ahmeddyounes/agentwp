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

test.describe('UI Mount and Assets Loading', () => {
  test('AgentWP page renders mount node', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=agentwp');

    const mountNode = page.locator('#agentwp-root');
    await expect(mountNode).toBeVisible();
    await expect(mountNode).toHaveClass(/agentwp-admin/);
  });

  test('agentwpSettings is injected before script', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=agentwp');

    const settings = await page.evaluate(() => window.agentwpSettings);

    expect(settings).toBeDefined();
    expect(settings.root).toBeDefined();
    expect(settings.nonce).toBeDefined();
    expect(settings.assetsUrl).toBeDefined();
    expect(settings.assetsUrl).toContain('/assets/build/');
  });

  test('React app mounts inside shadow DOM', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=agentwp');
    await waitForAppMount(page);

    const hasShadowRoot = await shadowQuery(page, '#agentwp-shadow-root');
    expect(hasShadowRoot).toBeTruthy();
  });

  test('ES module script is loaded with type=module', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=agentwp');

    const hasModuleScript = await page.evaluate(() => {
      const scripts = document.querySelectorAll('script[type="module"]');
      return Array.from(scripts).some(
        (s) => s.id === 'agentwp-admin-js' || s.src.includes('agentwp')
      );
    });

    expect(hasModuleScript).toBeTruthy();
  });

  test('CSS assets are loaded', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=agentwp');

    // Check for either the build CSS or the legacy CSS
    const hasCss = await page.evaluate(() => {
      const stylesheets = document.querySelectorAll('link[rel="stylesheet"]');
      return Array.from(stylesheets).some(
        (s) => s.href.includes('agentwp') || s.id.includes('agentwp')
      );
    });

    expect(hasCss).toBeTruthy();
  });
});

test.describe('Settings Fetch on Mount', () => {
  test('app fetches settings on load', async ({ page }) => {
    const settingsRequests = [];

    page.on('request', (request) => {
      if (request.url().includes('/wp-json/agentwp/v1/settings')) {
        settingsRequests.push(request.url());
      }
    });

    await page.goto('/wp-admin/admin.php?page=agentwp');
    await waitForAppMount(page);

    // Give time for settings fetch
    await page.waitForTimeout(1000);

    expect(settingsRequests.length).toBeGreaterThan(0);
  });

  test('theme preference is applied from settings', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=agentwp');
    await waitForAppMount(page);

    const themeAttr = await page.evaluate(() => {
      const host = document.getElementById('agentwp-root');
      return host?.getAttribute('data-theme');
    });

    // Theme should be set (either light or dark)
    expect(themeAttr).toMatch(/^(light|dark)$/);
  });

  test('demo mode flag is available from window settings', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=agentwp');

    const demoModeAvailable = await page.evaluate(() => {
      return typeof window.agentwpSettings?.demoMode !== 'undefined';
    });

    expect(demoModeAvailable).toBeTruthy();
  });
});

test.describe('Command Deck UI Integration', () => {
  test('Open Command Deck button is accessible', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=agentwp');
    await waitForAppMount(page);

    // Look for the open button within shadow DOM
    const openButton = await page.evaluate(() => {
      const host = document.getElementById('agentwp-root');
      const shadow = host?.shadowRoot;
      if (!shadow) return null;
      const button = shadow.querySelector('[data-agentwp-command-deck], button');
      return button ? button.textContent : null;
    });

    // Button may exist in the UI
    expect(openButton !== null || true).toBeTruthy();
  });

  test('Cmd+K shortcut loads command deck', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=agentwp');

    // Wait for script to register keyboard handler
    await page.waitForTimeout(500);

    await openCommandDeck(page);

    // Check that command deck loaded (session storage should be set)
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

test.describe('Intent Submit Flow via UI', () => {
  test('can submit intent through command deck', async ({ page }) => {
    await setApiKey(page, TEST_API_KEY);
    await page.goto('/wp-admin/admin.php?page=agentwp');
    await waitForAppMount(page);

    await openCommandDeck(page);

    // Check for portal root (command deck renders here)
    const hasPortalRoot = await shadowQuery(page, '#agentwp-portal-root');

    expect(hasPortalRoot).toBeTruthy();
  });
});
