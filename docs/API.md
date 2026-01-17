# AgentWP REST API

Base URL (relative): `/wp-json/agentwp/v1`

All endpoints require an authenticated WordPress user with the `manage_woocommerce` capability and a valid REST nonce.

For the full OpenAPI 3.0 specification, see [openapi.json](./openapi.json).

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

## Error codes

The API uses standardized error codes. Common codes include:

### Authentication/Authorization
| Code | Description |
|------|-------------|
| `agentwp_forbidden` | User lacks required capability |
| `agentwp_unauthorized` | Not authenticated |
| `agentwp_missing_nonce` | X-WP-Nonce header missing |
| `agentwp_invalid_nonce` | Nonce validation failed |

### Validation
| Code | Description |
|------|-------------|
| `agentwp_invalid_request` | Request payload failed schema validation |
| `agentwp_validation_error` | Field-level validation error |
| `agentwp_missing_prompt` | Intent request missing prompt/input |
| `agentwp_invalid_period` | Invalid usage period value |
| `agentwp_invalid_theme` | Invalid theme value |
| `agentwp_invalid_key` | API key format invalid |

### API/Network
| Code | Description |
|------|-------------|
| `agentwp_rate_limited` | Rate limit exceeded (check Retry-After header) |
| `agentwp_api_error` | Upstream API error |
| `agentwp_network_error` | Network connectivity issue |
| `agentwp_intent_failed` | Intent processing failed |
| `agentwp_openai_unreachable` | Cannot reach OpenAI API |
| `agentwp_openai_invalid` | OpenAI API key validation failed |
| `agentwp_encryption_failed` | API key encryption/storage failed |
| `agentwp_service_unavailable` | Required service unavailable |

## Endpoints

### POST /intent
Submit a natural-language prompt.
Either `prompt` or `input` is required.

**Request body schema:**
| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| `prompt` | string | minLength: 1, maxLength: 10000 | The natural-language prompt |
| `input` | string | minLength: 1, maxLength: 10000 | Alias for prompt (backwards compatibility) |
| `context` | object | optional | UI context (ui_source, session_id, etc.) |
| `metadata` | object | optional | Additional metadata (draft_id, etc.) |

**Error codes:** `agentwp_invalid_request`, `agentwp_missing_prompt`, `agentwp_intent_failed`, `agentwp_rate_limited`

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

**Success response:**
```json
{
  "success": true,
  "data": {
    "intent_id": "550e8400-e29b-41d4-a716-446655440000",
    "status": "handled",
    "intent": "order_search",
    "message": "Found 3 orders matching your query",
    "cards": [],
    "function_suggestions": []
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

**Success response:**
```json
{
  "success": true,
  "data": {
    "status": "healthy",
    "time": "2026-01-17T12:00:00Z",
    "timestamp": 1768694400,
    "version": "1.0.0"
  }
}
```

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

**Query parameters:**
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `q` | string | Yes | Search query (minLength: 1) |
| `types` | string | No | Comma-separated resource types: `products`, `orders`, `customers` |

**Success response:**
```json
{
  "success": true,
  "data": {
    "query": "hoodie",
    "results": {
      "products": [
        {
          "id": 42,
          "type": "product",
          "primary": "Blue Hoodie",
          "secondary": "$39.99",
          "query": "hoodie"
        }
      ],
      "orders": []
    }
  }
}
```

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

**Success response:**
```json
{
  "success": true,
  "data": {
    "settings": {
      "model": "gpt-4o-mini",
      "budget_limit": 50,
      "draft_ttl_minutes": 60,
      "hotkey": "Cmd+K / Ctrl+K",
      "theme": "light",
      "demo_mode": false
    },
    "api_key_last4": "ab12",
    "has_api_key": true,
    "api_key_status": "stored"
  }
}
```

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
Update settings. All fields are optional; only provided fields are updated.

**Request body schema:**
| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| `model` | string | enum: `gpt-4o`, `gpt-4o-mini` | OpenAI model to use |
| `budget_limit` | number | min: 0, max: 100000 | Monthly budget limit in USD |
| `draft_ttl_minutes` | integer | min: 0, max: 10080 (7 days) | Draft expiration time |
| `hotkey` | string | maxLength: 50 | Keyboard shortcut |
| `theme` | string | enum: `light`, `dark` | UI theme |
| `dark_mode` | boolean | — | Legacy alias for theme (true = dark) |
| `demo_mode` | boolean | — | Enable demo mode |

**Error codes:** `agentwp_invalid_request`

Request body:
```json
{
  "model": "gpt-4o-mini",
  "budget_limit": 50,
  "draft_ttl_minutes": 10,
  "hotkey": "Cmd+K / Ctrl+K",
  "theme": "light",
  "dark_mode": false,
  "demo_mode": false
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
Store or clear the OpenAI API key. Send an empty string to delete the stored key.

**Request body schema:**
| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| `api_key` | string | minLength: 20, maxLength: 256, prefix: `sk-` | OpenAI API key |

**Validation:**
- The key must start with `sk-` prefix
- Empty string deletes the stored key
- Key is validated against OpenAI API before storage

**Error codes:** `agentwp_invalid_request`, `agentwp_invalid_key`, `agentwp_openai_unreachable`, `agentwp_openai_invalid`, `agentwp_encryption_failed`

Request body:
```json
{ "api_key": "sk-..." }
```

**Success response (key stored):**
```json
{
  "success": true,
  "data": {
    "stored": true,
    "last4": "ab12"
  }
}
```

**Success response (key deleted):**
```json
{
  "success": true,
  "data": {
    "stored": false,
    "last4": ""
  }
}
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

**Query parameters:**
| Param | Type | Constraints | Default | Description |
|-------|------|-------------|---------|-------------|
| `period` | string | enum: `day`, `week`, `month` | `month` | Usage aggregation period |

**Error codes:** `agentwp_invalid_request`, `agentwp_invalid_period`

**Success response:**
```json
{
  "success": true,
  "data": {
    "period": "month",
    "total_tokens": 125000,
    "total_cost_usd": 2.50,
    "breakdown_by_intent": {
      "order_search": 45000,
      "analytics_query": 80000
    },
    "daily_trend": [
      { "date": "2026-01-15", "tokens": 12000 },
      { "date": "2026-01-16", "tokens": 8500 }
    ],
    "period_start": "2026-01-01T00:00:00Z",
    "period_end": "2026-01-31T23:59:59Z"
  }
}
```

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
