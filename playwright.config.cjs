const { defineConfig } = require('@playwright/test');

const baseURL = process.env.AGENTWP_BASE_URL || 'http://localhost:8888';

module.exports = defineConfig({
  testDir: 'tests/e2e',
  timeout: 90_000,
  expect: {
    timeout: 10_000,
  },
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  use: {
    baseURL,
    headless: true,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
});
