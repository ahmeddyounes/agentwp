# AgentWP REST API

Base URL (relative): `/wp-json/agentwp/v1`

All endpoints require an authenticated WordPress user with the `manage_woocommerce` capability and a valid REST nonce.

## Authentication
Include the WP REST nonce in the `X-WP-Nonce` header.

Example header:
```
X-WP-Nonce: <nonce>
```

## Response envelope
Successful responses:
```json
{
  "success": true,
  "data": {}
}
```

Error responses:
```json
{
  "success": false,
  "data": {},
  "error": {
    "code": "agentwp_invalid_request",
    "message": "Readable message",
    "type": "validation",
    "meta": {}
  }
}
```

## Endpoints

### POST /intent
Submit a natural-language prompt.
Either `prompt` or `input` is required.

Request body:
```json
{
  "prompt": "Draft a refund for order 1042",
  "context": {
    "ui_source": "command_deck",
    "session_id": "abc123"
  },
  "metadata": {
    "draft_id": ""
  }
}
```

cURL:
```bash
curl -X POST "https://example.com/wp-json/agentwp/v1/intent" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"prompt":"Show sales for last week"}'
```

JavaScript:
```js
await fetch('/wp-json/agentwp/v1/intent', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': window.agentwpSettings?.nonce,
  },
  body: JSON.stringify({ prompt: 'Show sales for last week' }),
});
```

### GET /health
Check service health.

cURL:
```bash
curl -X GET "https://example.com/wp-json/agentwp/v1/health" \
  -H "X-WP-Nonce: <nonce>"
```

JavaScript:
```js
await fetch('/wp-json/agentwp/v1/health', {
  headers: { 'X-WP-Nonce': window.agentwpSettings?.nonce },
});
```

### GET /search
Search orders, customers, or products.

Query params:
- `q` (string, required)
- `types` (optional, comma-separated or array)

cURL:
```bash
curl -X GET "https://example.com/wp-json/agentwp/v1/search?q=hoodie&types=products,orders" \
  -H "X-WP-Nonce: <nonce>"
```

JavaScript:
```js
const params = new URLSearchParams({ q: 'hoodie', types: 'products,orders' });
await fetch(`/wp-json/agentwp/v1/search?${params.toString()}`, {
  headers: { 'X-WP-Nonce': window.agentwpSettings?.nonce },
});
```

### GET /settings
Fetch settings and API key status.

cURL:
```bash
curl -X GET "https://example.com/wp-json/agentwp/v1/settings" \
  -H "X-WP-Nonce: <nonce>"
```

JavaScript:
```js
await fetch('/wp-json/agentwp/v1/settings', {
  headers: { 'X-WP-Nonce': window.agentwpSettings?.nonce },
});
```

### POST /settings
Update settings.

Request body:
```json
{
  "model": "gpt-4o-mini",
  "budget_limit": 50,
  "draft_ttl_minutes": 10,
  "hotkey": "Cmd+K / Ctrl+K",
  "theme": "light",
  "dark_mode": false
}
```

cURL:
```bash
curl -X POST "https://example.com/wp-json/agentwp/v1/settings" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"budget_limit":25,"theme":"dark"}'
```

JavaScript:
```js
await fetch('/wp-json/agentwp/v1/settings', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': window.agentwpSettings?.nonce,
  },
  body: JSON.stringify({ budget_limit: 25, theme: 'dark' }),
});
```

### POST /settings/api-key
Store or clear the OpenAI API key.

Request body:
```json
{ "api_key": "sk-..." }
```

cURL:
```bash
curl -X POST "https://example.com/wp-json/agentwp/v1/settings/api-key" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"api_key":"sk-your-key"}'
```

JavaScript:
```js
await fetch('/wp-json/agentwp/v1/settings/api-key', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': window.agentwpSettings?.nonce,
  },
  body: JSON.stringify({ api_key: 'sk-your-key' }),
});
```

### GET /usage
Fetch usage totals.

Query params:
- `period`: `day`, `week`, or `month`

cURL:
```bash
curl -X GET "https://example.com/wp-json/agentwp/v1/usage?period=month" \
  -H "X-WP-Nonce: <nonce>"
```

JavaScript:
```js
await fetch('/wp-json/agentwp/v1/usage?period=month', {
  headers: { 'X-WP-Nonce': window.agentwpSettings?.nonce },
});
```

### GET /history
Fetch command history and favorites.

cURL:
```bash
curl -X GET "https://example.com/wp-json/agentwp/v1/history" \
  -H "X-WP-Nonce: <nonce>"
```

JavaScript:
```js
await fetch('/wp-json/agentwp/v1/history', {
  headers: { 'X-WP-Nonce': window.agentwpSettings?.nonce },
});
```

### POST /history
Update command history and favorites.

Request body:
```json
{
  "history": [
    {
      "raw_input": "Show sales for last week",
      "parsed_intent": "analytics_query",
      "timestamp": "2026-01-01T12:00:00Z",
      "was_successful": true
    }
  ],
  "favorites": []
}
```

cURL:
```bash
curl -X POST "https://example.com/wp-json/agentwp/v1/history" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"history":[],"favorites":[]}'
```

JavaScript:
```js
await fetch('/wp-json/agentwp/v1/history', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': window.agentwpSettings?.nonce,
  },
  body: JSON.stringify({ history: [], favorites: [] }),
});
```

### GET /theme
Fetch the saved theme preference.

cURL:
```bash
curl -X GET "https://example.com/wp-json/agentwp/v1/theme" \
  -H "X-WP-Nonce: <nonce>"
```

JavaScript:
```js
await fetch('/wp-json/agentwp/v1/theme', {
  headers: { 'X-WP-Nonce': window.agentwpSettings?.nonce },
});
```

### POST /theme
Update the theme preference.

Request body:
```json
{ "theme": "dark" }
```

cURL:
```bash
curl -X POST "https://example.com/wp-json/agentwp/v1/theme" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"theme":"dark"}'
```

JavaScript:
```js
await fetch('/wp-json/agentwp/v1/theme', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': window.agentwpSettings?.nonce,
  },
  body: JSON.stringify({ theme: 'dark' }),
});
```
