# Demo Environment

This guide explains how to boot a demo-ready AgentWP site with seeded data and daily resets.

## Docker demo setup
1. From the repo root, run:
```
docker compose -f docker-compose.demo.yml up -d --build
```
2. Wait for `wp_setup` to finish seeding.
3. Visit `http://localhost:8080/wp-admin` and log in with the admin credentials.

Optional environment variables:
- `AGENTWP_DEMO_API_KEY` (rate-limited demo key)
- `DEMO_PRODUCTS`, `DEMO_CATEGORIES`, `DEMO_CUSTOMERS`, `DEMO_ORDERS`

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
