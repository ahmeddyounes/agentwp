#!/usr/bin/env bash
set -euo pipefail

WP_VERSION="${WP_VERSION:-6.6}"
WC_VERSION="${WC_VERSION:-9.0}"
PHP_VERSION="${PHP_VERSION:-8.3}"

export WP_ENV_CORE="WordPress/WordPress#${WP_VERSION}"
export WP_ENV_PHP_VERSION="${PHP_VERSION}"

npm run wp-env:start
npm run wp-env:reset

wp-env run cli wp core update --version="${WP_VERSION}" --force
wp-env run cli wp plugin install woocommerce --version="${WC_VERSION}" --activate --force
wp-env run cli wp plugin activate agentwp

wp-env run cli wp plugin install wordpress-seo --activate
wp-env run cli wp plugin install elementor --activate

if [ -n "${AGENTWP_WC_SUBSCRIPTIONS_ZIP:-}" ]; then
  wp-env run cli wp plugin install "${AGENTWP_WC_SUBSCRIPTIONS_ZIP}" --activate
else
  echo "WooCommerce Subscriptions zip not provided; skipping."
fi

if [ -n "${AGENTWP_WPML_ZIP:-}" ]; then
  wp-env run cli wp plugin install "${AGENTWP_WPML_ZIP}" --activate
else
  echo "WPML zip not provided; skipping."
fi

if [ -n "${AGENTWP_WP_ROCKET_ZIP:-}" ]; then
  wp-env run cli wp plugin install "${AGENTWP_WP_ROCKET_ZIP}" --activate
else
  echo "WP Rocket zip not provided; skipping."
fi

wp-env run cli wp plugin install wp-super-cache --activate
wp-env run cli wp plugin install w3-total-cache --activate

wp-env run cli wp eval-file /var/www/html/wp-content/plugins/agentwp/scripts/compatibility/check-rest-cache.php

if wp-env run cli wp core is-installed --network >/dev/null 2>&1; then
  echo "Multisite already enabled."
else
  wp-env run cli wp core multisite-convert --title="AgentWP" --base="/" --skip-email
fi

wp-env run cli wp plugin activate agentwp --network
wp-env run cli wp eval-file /var/www/html/wp-content/plugins/agentwp/scripts/compatibility/check-multisite.php

if [ "${AGENTWP_KEEP_ENV:-}" != "1" ]; then
  wp-env stop
fi
