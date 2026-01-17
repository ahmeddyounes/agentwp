# Demo Environment

This guide explains how to boot a demo-ready AgentWP site with seeded data and daily resets.

## Demo Mode Credential Rules

Demo mode has explicit credential behavior to ensure production API keys are never accidentally used:

### Credential Priority (when demo mode is enabled)

1. **Demo API Key** - If configured, real API calls are made using this key
2. **Stubbed Responses** - If no demo API key is available, deterministic stubbed responses are returned

### Key Behaviors

| Mode | Demo Key Available | Behavior |
|------|-------------------|----------|
| Demo ON | Yes | Uses demo API key for real OpenAI calls |
| Demo ON | No | Returns stubbed responses (no API calls) |
| Demo OFF | N/A | Uses real API key normally |

### Security Guarantee

**CRITICAL**: When demo mode is enabled, the real API key is NEVER used, even if configured.
This prevents accidental cost incursion or data leakage in demo environments.

### Configuration Sources for Demo API Key

The demo API key is resolved in this order:
1. `AGENTWP_DEMO_API_KEY` PHP constant (highest priority)
2. `AGENTWP_DEMO_API_KEY` environment variable
3. Stored demo API key in database (set via WP-CLI or admin UI)

### Stubbed Response Behavior

When no demo API key is available, the `DemoClient` returns:
- Deterministic, context-aware responses
- Simulated token usage metrics
- Clear indication that demo mode is active

## API Key Validation in Demo Mode

The `DemoAwareKeyValidator` wraps the real OpenAI key validator with demo-mode awareness.
This ensures that API key validation behavior is deterministic and cannot leak real-key behavior.

### Validation Behavior Matrix

| Mode | Demo Key Available | Validation Behavior |
|------|-------------------|---------------------|
| Demo OFF | N/A | Validates provided key via OpenAI API |
| Demo ON | Yes | Validates demo key only (ignores provided key) |
| Demo ON | No | Always returns valid (no API call made) |

### Security Guarantees

1. **Demo stubbed mode**: API key validation always succeeds without making any API calls
2. **Demo key mode**: Only the demo API key is validated, never the user-provided key
3. **Normal mode**: Standard validation via OpenAI's `/models` endpoint

This design ensures:
- Real API keys are never sent to OpenAI when demo mode is enabled
- Demo environments behave consistently regardless of which keys are configured
- No accidental cost incursion from validating real keys in demo mode

### Implementation Details

The demo-aware validation is implemented in these classes:
- `AgentWP\Demo\DemoAwareKeyValidator` - Wraps the real validator with demo logic
- `AgentWP\Demo\DemoCredentials` - Manages credential type detection
- `AgentWP\Infrastructure\OpenAIKeyValidator` - Real validator for OpenAI API

All consumers of `OpenAIKeyValidatorInterface` automatically get demo-aware behavior
through the service container wiring in `InfrastructureServiceProvider`.

## Docker demo setup
1. From the repo root, run:
```
docker compose -f docker-compose.demo.yml up -d --build
```
2. Wait for `wp_setup` to finish seeding.
3. Visit `http://localhost:8080/wp-admin` and log in with the admin credentials.

Optional environment variables:
- `AGENTWP_DEMO_API_KEY` (rate-limited demo key for real API calls)
- `DEMO_PRODUCTS`, `DEMO_CATEGORIES`, `DEMO_CUSTOMERS`, `DEMO_ORDERS`

If `AGENTWP_DEMO_API_KEY` is not set, demo mode will use stubbed responses instead.

## WP-CLI demo setup
Run inside a WordPress container or host with WP-CLI:
```
WP_PATH=/var/www/html ./scripts/demo-setup.sh
```
The script expects the plugin to be present at `wp-content/plugins/agentwp`.

## Daily reset
Demo mode schedules a daily WP-Cron event (`agentwp_demo_daily_reset`). If WP-Cron is disabled,
you can use a system cron:
```
0 3 * * * WP_PATH=/var/www/html /path/to/repo/scripts/demo-reset.sh
```
