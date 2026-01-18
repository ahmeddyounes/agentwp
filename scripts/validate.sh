#!/usr/bin/env bash
#
# validate.sh — Run all CI checks locally
#
# This script runs the same PHP and React checks that CI runs, allowing
# contributors to reproduce CI locally with one command.
#
# Usage:
#   ./scripts/validate.sh          # Run all checks
#   ./scripts/validate.sh --php    # Run only PHP checks
#   ./scripts/validate.sh --node   # Run only Node/React checks
#   ./scripts/validate.sh --help   # Show help
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Track failures
FAILURES=()

print_header() {
    echo ""
    echo -e "${BLUE}══════════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}══════════════════════════════════════════════════════════════${NC}"
}

print_step() {
    echo ""
    echo -e "${YELLOW}▶ $1${NC}"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_skip() {
    echo -e "${YELLOW}⊘ $1${NC}"
}

run_check() {
    local name="$1"
    shift

    if "$@"; then
        print_success "$name passed"
        return 0
    else
        print_error "$name failed"
        FAILURES+=("$name")
        return 1
    fi
}

show_help() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Run all CI checks locally."
    echo ""
    echo "Options:"
    echo "  --php      Run only PHP checks (PHPCS, PHPUnit, PHPStan, OpenAPI)"
    echo "  --node     Run only Node/React checks (lint, typecheck, test, build)"
    echo "  --help     Show this help message"
    echo ""
    echo "If no options are provided, all checks are run."
    echo ""
    echo "Examples:"
    echo "  $0              # Run all checks"
    echo "  $0 --php        # Run only PHP checks"
    echo "  $0 --node       # Run only Node checks"
}

run_php_checks() {
    print_header "PHP Checks"

    cd "$ROOT_DIR"

    # Install dependencies if needed
    if [ -f composer.json ]; then
        print_step "Installing PHP dependencies..."
        composer install --no-interaction --no-progress --prefer-dist
    fi

    # PHPCS
    print_step "Running PHPCS (WordPress coding standards)..."
    if [ -x vendor/bin/phpcs ]; then
        if find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' -not -path './react/node_modules/*' | grep -q .; then
            run_check "PHPCS" vendor/bin/phpcs || true
        else
            print_skip "No PHP files found; skipping PHPCS."
        fi
    else
        print_skip "PHPCS not installed; skipping."
    fi

    # PHPUnit
    print_step "Running PHPUnit tests..."
    if [ -x vendor/bin/phpunit ]; then
        if [ -f phpunit.xml ] || [ -f phpunit.xml.dist ] || [ -d tests ]; then
            run_check "PHPUnit" vendor/bin/phpunit || true
        else
            print_skip "No PHPUnit config/tests found; skipping."
        fi
    else
        print_skip "PHPUnit not installed; skipping."
    fi

    # PHPStan
    print_step "Running PHPStan (static analysis)..."
    if [ -x vendor/bin/phpstan ]; then
        if [ -f phpstan.neon ] || [ -f phpstan.neon.dist ]; then
            run_check "PHPStan" composer run phpstan || true
        else
            print_skip "No PHPStan config found; skipping."
        fi
    else
        print_skip "PHPStan not installed; skipping."
    fi

    # OpenAPI validation
    print_step "Validating OpenAPI spec..."
    if [ -f scripts/openapi-validate.php ]; then
        run_check "OpenAPI validation" php scripts/openapi-validate.php || true
    else
        print_skip "OpenAPI validation script not found; skipping."
    fi
}

run_node_checks() {
    print_header "Node/React Checks"

    cd "$ROOT_DIR/react"

    if [ ! -f package.json ]; then
        print_skip "No package.json found in react/; skipping Node checks."
        return 0
    fi

    # Install dependencies if needed
    print_step "Installing Node dependencies..."
    if [ -f package-lock.json ]; then
        npm ci
    else
        npm install
    fi

    # Check OpenAPI types are up to date
    print_step "Checking OpenAPI types are up to date..."
    npm run generate:types
    if git diff --exit-code src/types/api.ts > /dev/null 2>&1; then
        print_success "OpenAPI types are up to date"
    else
        print_error "OpenAPI types are out of date. Run 'npm run generate:types' and commit."
        FAILURES+=("OpenAPI types check")
    fi

    # TypeScript check
    print_step "Running TypeScript check..."
    run_check "TypeScript" npm run typecheck || true

    # ESLint
    print_step "Running ESLint..."
    run_check "ESLint" npm run lint || true

    # Prettier
    print_step "Running Prettier check..."
    run_check "Prettier" npm run format:check || true

    # Vitest
    print_step "Running Vitest tests..."
    run_check "Vitest" npm test || true

    # Build
    print_step "Building frontend..."
    run_check "Build" npm run build || true
}

print_summary() {
    print_header "Summary"

    if [ ${#FAILURES[@]} -eq 0 ]; then
        echo ""
        print_success "All checks passed!"
        echo ""
        return 0
    else
        echo ""
        print_error "The following checks failed:"
        for failure in "${FAILURES[@]}"; do
            echo "  - $failure"
        done
        echo ""
        return 1
    fi
}

main() {
    local run_php=false
    local run_node=false

    # Parse arguments
    if [ $# -eq 0 ]; then
        run_php=true
        run_node=true
    else
        while [[ $# -gt 0 ]]; do
            case $1 in
                --php)
                    run_php=true
                    shift
                    ;;
                --node)
                    run_node=true
                    shift
                    ;;
                --help|-h)
                    show_help
                    exit 0
                    ;;
                *)
                    echo "Unknown option: $1"
                    show_help
                    exit 1
                    ;;
            esac
        done
    fi

    print_header "AgentWP Local Validation"
    echo "Replicating CI checks locally..."

    if $run_php; then
        run_php_checks
    fi

    if $run_node; then
        run_node_checks
    fi

    print_summary
}

main "$@"
