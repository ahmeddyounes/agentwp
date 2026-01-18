#!/usr/bin/env bash
#
# Build React UI assets for WordPress enqueuing.
#
# This script:
#   1. Installs npm dependencies (if needed)
#   2. Runs the Vite production build
#   3. Outputs assets to assets/build/ with a manifest
#
# Usage:
#   ./scripts/build-assets.sh
#
# The build produces:
#   - assets/build/assets/*.js   Bundled JavaScript (hashed filenames)
#   - assets/build/assets/*.css  Bundled CSS (hashed filenames)
#   - assets/build/.vite/manifest.json  Vite manifest for WordPress asset loading
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
REACT_DIR="${PROJECT_ROOT}/react"

echo "Building AgentWP React UI assets..."

cd "${REACT_DIR}"

# Install dependencies if node_modules is missing or package.json changed
if [ ! -d "node_modules" ] || [ "package.json" -nt "node_modules/.package-lock.json" ]; then
    echo "Installing npm dependencies..."
    npm install
fi

# Run production build
echo "Running Vite build..."
npm run build

echo ""
echo "Build complete. Assets written to: ${PROJECT_ROOT}/assets/build/"
echo "Manifest: ${PROJECT_ROOT}/assets/build/.vite/manifest.json"
