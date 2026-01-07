# Compatibility

## Minimum requirements
- WordPress 6.4+
- WooCommerce 8.0+
- PHP 8.0+

## Tested matrix
Automated compatibility tests target:
- PHP 8.0, 8.1, 8.2, 8.3
- WooCommerce 8.0, 8.5, 9.0
- WordPress 6.4, 6.5, 6.6

## Plugin compatibility
Automated conflict checks install and activate:
- Yoast SEO (`wordpress-seo`)
- Elementor (`elementor`)
- WP Super Cache (`wp-super-cache`)
- W3 Total Cache (`w3-total-cache`)

Premium plugins require local zips or private download URLs:
- WooCommerce Subscriptions (`AGENTWP_WC_SUBSCRIPTIONS_ZIP`)
- WPML (`AGENTWP_WPML_ZIP`)
- WP Rocket (`AGENTWP_WP_ROCKET_ZIP`)

## Known conflicts and workarounds
- Aggressive page caching can cache REST GET responses. If REST calls appear stale, exclude `/wp-json/agentwp/v1/*` from caching in WP Super Cache or W3 Total Cache.
- WP Rocket can cache REST endpoints if "Cache REST API" is enabled. Disable that setting or add a rule to bypass `/wp-json/agentwp/v1/*`.
- WPML translation filters can alter REST payloads. If responses look translated, exclude `agentwp/v1` from WPML translation.

## Multisite notes
- AgentWP supports multisite when WooCommerce is active on the target site.
- Network activate AgentWP when you want it available across sites.

## Local compatibility runs
Matrix smoke tests:
```
WP_VERSION=6.6 WC_VERSION=9.0 PHP_VERSION=8.3 bash scripts/compatibility/run-matrix.sh
```

Extended checks (conflict plugins, caching headers, multisite):
```
AGENTWP_WC_SUBSCRIPTIONS_ZIP=/path/to/woocommerce-subscriptions.zip \
AGENTWP_WPML_ZIP=/path/to/wpml.zip \
AGENTWP_WP_ROCKET_ZIP=/path/to/wp-rocket.zip \
WP_VERSION=6.6 WC_VERSION=9.0 PHP_VERSION=8.3 \
bash scripts/compatibility/run-extended.sh
```

Keep wp-env running for Playwright checks:
```
AGENTWP_KEEP_ENV=1 WP_VERSION=6.6 WC_VERSION=9.0 PHP_VERSION=8.3 \
bash scripts/compatibility/run-extended.sh
```
