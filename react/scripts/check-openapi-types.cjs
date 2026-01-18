#!/usr/bin/env node

const { execSync } = require('child_process');

try {
  execSync('git diff --exit-code src/types/api.ts', { stdio: 'inherit' });
  console.log('OK: OpenAPI types are up to date');
} catch (error) {
  console.error("ERROR: OpenAPI types are out of date. Run 'npm run generate:types' and commit.");
  process.exit(1);
}
