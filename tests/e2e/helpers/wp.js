const { expect } = require('@playwright/test');

const WP_USERNAME = process.env.WP_USERNAME || 'admin';
const WP_PASSWORD = process.env.WP_PASSWORD || 'password';

async function login(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', WP_USERNAME);
  await page.fill('#user_pass', WP_PASSWORD);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}

async function apiGet(page, path) {
  const response = await page.request.get(path);
  const json = await response.json().catch(() => null);
  return { response, json };
}

async function apiPost(page, path, data) {
  const response = await page.request.post(path, { data });
  const json = await response.json().catch(() => null);
  return { response, json };
}

async function resetTestData(page) {
  const { response, json } = await apiPost(page, '/wp-json/agentwp-test/v1/reset', {});
  expect(response.ok()).toBeTruthy();
  expect(json?.success).toBeTruthy();
}

async function seedTestData(page, scenario = 'default') {
  const { response, json } = await apiPost(page, '/wp-json/agentwp-test/v1/seed', { scenario });
  expect(response.ok()).toBeTruthy();
  expect(json?.success).toBeTruthy();
  return json.data;
}

async function setApiKey(page, apiKey) {
  const { response, json } = await apiPost(page, '/wp-json/agentwp/v1/settings/api-key', {
    api_key: apiKey,
  });
  return { response, json };
}

/**
 * Wait for the AgentWP React app to mount in shadow DOM.
 * @param {import('@playwright/test').Page} page
 * @param {number} timeout
 * @returns {Promise<void>}
 */
async function waitForAppMount(page, timeout = 10000) {
  await page.waitForFunction(
    () => {
      const host = document.getElementById('agentwp-root');
      return host && host.shadowRoot && host.shadowRoot.querySelector('#agentwp-shadow-root');
    },
    { timeout }
  );
}

/**
 * Query an element within the AgentWP shadow DOM.
 * @param {import('@playwright/test').Page} page
 * @param {string} selector
 * @returns {Promise<boolean>}
 */
async function shadowQuery(page, selector) {
  return page.evaluate(
    (sel) => {
      const host = document.getElementById('agentwp-root');
      const shadow = host?.shadowRoot;
      return Boolean(shadow?.querySelector(sel));
    },
    selector
  );
}

/**
 * Open the command deck via keyboard shortcut.
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<void>}
 */
async function openCommandDeck(page) {
  const isMac = await page.evaluate(() => /Mac|iPod|iPhone|iPad/.test(navigator.platform));
  await page.keyboard.press(isMac ? 'Meta+k' : 'Control+k');
  // Wait for command deck to load
  await page.waitForTimeout(1500);
}

module.exports = {
  apiGet,
  apiPost,
  login,
  openCommandDeck,
  resetTestData,
  seedTestData,
  setApiKey,
  shadowQuery,
  waitForAppMount,
};
