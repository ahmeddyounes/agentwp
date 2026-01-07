# AgentWP

AgentWP is an AI command deck for WooCommerce. It turns plain-language requests into store actions and insights while keeping data inside WordPress.

## Quick start (10 minutes)
1. Install and activate the plugin.
2. Go to **WooCommerce > AgentWP**.
3. Open **Settings** and save your OpenAI API key.
4. Return to the Command Deck and run your first command.

Example command:
```
Show me today's sales and top products.
```

## Requirements
- WordPress 6.4+
- WooCommerce 8.0+
- PHP 8.0+
- OpenAI API key (BYOK)

## Installation
1. Upload the plugin folder to `wp-content/plugins/agentwp` or install the ZIP in WordPress.
2. Activate **AgentWP**.
3. Visit **WooCommerce > AgentWP** and connect your OpenAI API key.

For development builds, see `docs/DEVELOPER.md` for build steps and local setup.

## First command tutorial
1. Open **WooCommerce > AgentWP**.
2. Make sure the API key shows as stored in Settings.
3. Type a prompt like:
   - "Draft a refund for order 1001"
   - "Show me sales for last week"
4. Review the response card and confirm any action drafts.

## Documentation
- User Guide: `docs/USER-GUIDE.md`
- API Reference: `docs/API.md`
- Developer Guide: `docs/DEVELOPER.md`
- Demo Guide: `docs/DEMO.md`
- FAQ: `docs/FAQ.md`
- Changelog: `docs/CHANGELOG.md`
- OpenAPI spec: `docs/openapi.json`
