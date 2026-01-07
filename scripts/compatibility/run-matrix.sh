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

wp-env run cli wp eval-file /var/www/html/wp-content/plugins/agentwp/scripts/compatibility/check-health.php
wp-env run cli wp eval-file /var/www/html/wp-content/plugins/agentwp/scripts/compatibility/check-rest-cache.php

wp-env stop
