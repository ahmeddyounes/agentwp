#!/usr/bin/env bash
set -euo pipefail

WP_PATH=${WP_PATH:-/var/www/html}

if [ ! -f "$WP_PATH/wp-load.php" ]; then
  echo "WordPress not found at $WP_PATH" >&2
  exit 1
fi

wp agentwp demo reset --path="$WP_PATH" --allow-root
