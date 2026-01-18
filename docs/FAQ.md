# AgentWP FAQ

## 1) What is AgentWP?
AgentWP is an AI command deck for WooCommerce that turns natural-language requests into actions and insights.

## 2) Do I need an OpenAI API key?
Yes. AgentWP uses your own OpenAI API key (BYOK) for all AI features.

## 3) Where is my API key stored?
The key is encrypted and stored in `wp_options` using WordPress salts for encryption.

## 4) Is my store data sent to OpenAI?
Only the data needed to fulfill a request is sent, and only when you run a command. No data is sent without a prompt.

## 5) Which WordPress and WooCommerce versions are supported?
Minimum requirements are WordPress 6.4+, WooCommerce 8.0+, and PHP 8.0+.

## 6) Why do I see “missing nonce” errors?
Requests to the REST API require the `X-WP-Nonce` header. Refresh the admin screen to get a new nonce.

## 7) I see a 429 rate limit response. What now?
Wait for the retry window to pass and try again. AgentWP enforces per-user rate limits to protect your store.

## 8) Why is the API key rejected?
Make sure the key starts with `sk-` and has not been revoked. Try generating a new key in OpenAI.

## 9) Can I limit usage costs?
Yes. Set a budget limit in Settings to cap spend.

## 10) How do I change the model?
Open Settings and choose between `gpt-4o` and `gpt-4o-mini`.

## 11) Can I use AgentWP on multisite?
Yes. Network-activate the plugin and enable it on sites with WooCommerce installed. Activation and upgrades run per site, so visit each site once (or run a network upgrade) to initialize tables and defaults. See `docs/MULTISITE.md` for lifecycle details.

## 12) The AgentWP menu is missing. Why?
Only users with the `manage_woocommerce` capability can access the menu. Check your role permissions.

## 13) How do I clear command history?
Use the History panel in the Command Deck to delete entries or clear favorites.

## 14) Can I disable the dark theme?
Yes. Set the theme preference to `light` in Settings.

## 15) Does AgentWP work without WooCommerce?
No. AgentWP is built specifically for WooCommerce workflows.

## 16) Search results look empty. What should I do?
Search indexing runs automatically on product/order changes. Trigger a resave or wait for the backfill to complete.

## 17) How do I handle caching issues with REST responses?
Exclude `/wp-json/agentwp/v1/*` from any page or REST caching plugins.

## 18) Are refunds executed immediately?
Refunds are drafted first and require confirmation before execution.

## 19) Can I extend AgentWP with custom intents?
Yes. Use the extension guide in `docs/DEVELOPER.md` to add a handler and register it.

## 20) Where can I find the API reference?
See `docs/API.md` and `docs/openapi.json`.
