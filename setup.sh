#!/usr/bin/env bash
set -euo pipefail

if [ -z "${COMPOSE_CMD:-}" ]; then
  if command -v docker-compose >/dev/null 2>&1; then
    COMPOSE_CMD=docker-compose
  else
    COMPOSE_CMD="docker compose"
  fi
fi
ENV_FILE=${ENV_FILE:-.env}

if [ ! -f "$ENV_FILE" ]; then
  if [ -f .env.example ]; then
    cp .env.example "$ENV_FILE"
    echo "Created $ENV_FILE from .env.example. Update values as needed."
  else
    echo "Missing $ENV_FILE and .env.example." >&2
    exit 1
  fi
fi

$COMPOSE_CMD up -d db wordpress mailhog node

$COMPOSE_CMD exec -T wordpress /scripts/wp-setup.sh

echo "Setup complete."
