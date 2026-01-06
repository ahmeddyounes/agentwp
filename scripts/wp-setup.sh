#!/usr/bin/env bash
set -euo pipefail

WP_PATH=${WP_PATH:-/var/www/html}

WORDPRESS_SITE_URL=${WORDPRESS_SITE_URL:-http://localhost:8080}
WORDPRESS_SITE_TITLE=${WORDPRESS_SITE_TITLE:-AgentWP Dev Store}
WORDPRESS_ADMIN_USER=${WORDPRESS_ADMIN_USER:-admin}
WORDPRESS_ADMIN_PASSWORD=${WORDPRESS_ADMIN_PASSWORD:-admin123}
WORDPRESS_ADMIN_EMAIL=${WORDPRESS_ADMIN_EMAIL:-admin@example.com}

WC_STORE_ADDRESS=${WC_STORE_ADDRESS:-100 Market St}
WC_STORE_CITY=${WC_STORE_CITY:-San Francisco}
WC_STORE_STATE=${WC_STORE_STATE:-CA}
WC_STORE_POSTCODE=${WC_STORE_POSTCODE:-94105}
WC_DEFAULT_COUNTRY=${WC_DEFAULT_COUNTRY:-US:CA}
WC_CURRENCY=${WC_CURRENCY:-USD}
WOOCOMMERCE_VERSION=${WOOCOMMERCE_VERSION:-8.4.0}

SEED_PRODUCTS=${SEED_PRODUCTS:-60}
SEED_ORDERS=${SEED_ORDERS:-120}

wait_for_wp() {
  echo "Waiting for WordPress files..."
  until [ -f "$WP_PATH/wp-load.php" ]; do
    sleep 2
  done

  echo "Waiting for WordPress config..."
  until [ -f "$WP_PATH/wp-config.php" ]; do
    sleep 2
  done

  echo "Waiting for database connection..."
  until wp db check --path="$WP_PATH" --allow-root >/dev/null 2>&1; do
    sleep 2
  done
}

wait_for_wp

if ! wp core is-installed --path="$WP_PATH" --allow-root >/dev/null 2>&1; then
  wp core install \
    --path="$WP_PATH" \
    --url="$WORDPRESS_SITE_URL" \
    --title="$WORDPRESS_SITE_TITLE" \
    --admin_user="$WORDPRESS_ADMIN_USER" \
    --admin_password="$WORDPRESS_ADMIN_PASSWORD" \
    --admin_email="$WORDPRESS_ADMIN_EMAIL" \
    --skip-email \
    --allow-root
fi

if ! wp plugin is-installed woocommerce --path="$WP_PATH" --allow-root >/dev/null 2>&1; then
  wp plugin install woocommerce --version="$WOOCOMMERCE_VERSION" --activate --path="$WP_PATH" --allow-root
else
  wp plugin activate woocommerce --path="$WP_PATH" --allow-root
fi

wp wc tool run install_pages --path="$WP_PATH" --allow-root || true

wp option update woocommerce_store_address "$WC_STORE_ADDRESS" --path="$WP_PATH" --allow-root
wp option update woocommerce_store_city "$WC_STORE_CITY" --path="$WP_PATH" --allow-root
wp option update woocommerce_store_state "$WC_STORE_STATE" --path="$WP_PATH" --allow-root
wp option update woocommerce_store_postcode "$WC_STORE_POSTCODE" --path="$WP_PATH" --allow-root
wp option update woocommerce_default_country "$WC_DEFAULT_COUNTRY" --path="$WP_PATH" --allow-root
wp option update woocommerce_currency "$WC_CURRENCY" --path="$WP_PATH" --allow-root

SEED_PRODUCTS="$SEED_PRODUCTS" SEED_ORDERS="$SEED_ORDERS" \
  wp eval-file /scripts/seed-woocommerce.php --path="$WP_PATH" --allow-root
