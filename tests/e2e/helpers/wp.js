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

module.exports = {
  apiGet,
  apiPost,
  login,
  resetTestData,
  seedTestData,
  setApiKey,
};
