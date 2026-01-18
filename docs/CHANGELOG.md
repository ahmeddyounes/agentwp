# Changelog
All notable changes to AgentWP are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Deprecated
- **Legacy wp-element UI bundle** (`assets/agentwp-admin.js`, `assets/agentwp-admin.css`) is now deprecated. The React-based UI in `react/src/` is the supported runtime. The legacy bundle will be removed in version **1.0.0**.
  - Migration: Run `npm run build` in the `react/` directory to generate the Vite build output in `assets/build/`.
  - The `AssetManager` now logs deprecation warnings when falling back to the legacy bundle.
  - Console warnings are displayed in the browser when the legacy UI loads.

### Added
- Comprehensive documentation suite and OpenAPI reference.
- `ApiKeyStorage` service for centralized API key management (`src/Security/ApiKeyStorage.php`).
- `DemoClient` for stubbed responses in demo mode without API key (`src/Demo/DemoClient.php`).
- Architecture inventory document (`docs/architecture-inventory.md`).

### Documentation
- **Architecture Improvement Plan** (`docs/ARCHITECTURE-IMPROVEMENT-PLAN.md`): Updated to reflect completed implementation of all 8 phases.
- **Architecture Decision Records**: Six ADRs documented in `docs/adr/`:
  - ADR 0001: REST Controller Dependency Resolution
  - ADR 0002: Intent Handler Registration
  - ADR 0003: Intent Classification Strategy
  - ADR 0004: OpenAI Client Architecture
  - ADR 0005: REST Rate Limiting
  - ADR 0006: Search Index Architecture
- **Technical Architecture** (`docs/ARCHITECTURE.md`): Boot flow diagram, service layer architecture, policy layer, ServiceResult pattern, gateway abstractions, draft lifecycle.
- **Developer Guide** (`docs/DEVELOPER.md`): Extension guides for custom handlers and scorers, migration notes for extension developers, service layer conventions.
- **Search Index** (`docs/search-index.md`): Detailed search index troubleshooting and performance documentation.
- Cross-referenced documentation with related document tables and ADR indexes.
- **Policy layer** for centralized capability checks (`src/Security/Policy/WooCommercePolicy.php`). Services now inject `PolicyInterface` instead of calling `current_user_can()` directly.
- **ServiceResult DTO** (`src/DTO/ServiceResult.php`) as the standard return type for all application services. Provides uniform success/failure handling with typed factory methods and HTTP status codes.
- **DraftPayload DTO** (`src/DTO/DraftPayload.php`) for standardized draft payload structure across all draft-based flows.
- **DraftManager** (`src/Services/DraftManager.php`) implementing `DraftManagerInterface` for unified draft lifecycle management (ID generation, payload shape, TTL, claim semantics).
- **WooCommerce gateway interfaces** abstracting all WooCommerce operations:
  - `WooCommerceRefundGatewayInterface` / `WooCommerceRefundGateway`
  - `WooCommerceOrderGatewayInterface` / `WooCommerceOrderGateway`
  - `WooCommerceStockGatewayInterface` / `WooCommerceStockGateway`
  - `WooCommerceUserGatewayInterface` / `WooCommerceUserGateway`
  - `WooCommerceProductCategoryGatewayInterface` / `WooCommerceProductCategoryGateway`
  - `WooCommercePriceFormatterInterface` / `WooCommercePriceFormatter`
  - `WooCommerceConfigGatewayInterface` / `WooCommerceConfigGateway`
- Unit tests for all core use-cases (`tests/Unit/Services/`) using mock dependencies (no WooCommerce runtime required).

### Changed
- **BREAKING**: Plugin bootstrap consolidated to single-path architecture. `src/Plugin.php` no longer directly registers menus, assets, or REST endpoints. All wiring flows through `src/Plugin/*` managers and service providers.
- **BREAKING**: Option keys and defaults consolidated into `AgentWPConfig` constants. All components now reference these constants instead of hardcoded strings.
- **BREAKING**: Application services (`OrderRefundService`, `OrderStatusService`, `ProductStockService`, `EmailDraftService`) now return `ServiceResult` instead of arrays or mixed types. Controllers must map `ServiceResult` to REST responses.
- **BREAKING**: Application services no longer call `current_user_can()` directly. They now inject `PolicyInterface` for all capability checks. Custom services extending these must follow the same pattern.
- **BREAKING**: Application services no longer call WooCommerce functions (`wc_get_order()`, `wc_create_refund()`, etc.) directly. They now use gateway interfaces. Custom services must use the gateway pattern for testability.
- **BREAKING**: Draft-based services (`OrderRefundService`, `OrderStatusService`, `ProductStockService`) now use `DraftManagerInterface` instead of directly interacting with `DraftStorageInterface`. The draft payload structure has changed to include `preview` data and uses `DraftPayload` DTO.
- Order search service consolidated to pipeline architecture (`src/Services/OrderSearch/*`). The previous `OrderSearchService` class has been removed.
- Demo mode credential behavior made explicit: when demo mode is enabled with a demo key, real API calls are made; when enabled without a key, stubbed responses are returned via `DemoClient`.

### Fixed
- Removed stale `HandlerServiceProvider` import and `BulkHandler` references.
- Removed unused `$model` and `$intent_type` properties from `DemoClient`.
- Fixed PHPStan and PHPCS warnings in consolidated codebase.

### Removed
- Removed `src/Services/OrderSearchService.php` (replaced by pipeline architecture).
- Removed dead code paths from `src/Plugin.php` (menu/assets/rest formatting methods).
- Removed direct `current_user_can()` calls from all application services (moved to policy layer).
- Removed direct WooCommerce function calls from all application services (moved to gateway layer).

### Migration Guide

#### ServiceResult pattern
Services now return `ServiceResult` instead of arrays. Update controller code:

```php
// Before
$result = $this->service->prepare_refund( $order_id, $amount );
if ( isset( $result['error'] ) ) {
    return new WP_REST_Response( array( 'error' => $result['error'] ), 400 );
}

// After
$result = $this->service->prepare_refund( $order_id, $amount );
if ( $result->isFailure() ) {
    return new WP_REST_Response( $result->toArray(), $result->httpStatus );
}
```

#### Policy layer
Custom services that checked permissions must now use `PolicyInterface`:

```php
// Before
if ( ! current_user_can( 'manage_woocommerce' ) ) {
    return array( 'error' => 'Permission denied' );
}

// After
if ( ! $this->policy->canManageOrders() ) {
    return ServiceResult::permissionDenied();
}
```

#### Gateway abstractions
Custom services that called WooCommerce functions must now use gateways:

```php
// Before
$order = wc_get_order( $order_id );

// After
$order = $this->refundGateway->get_order( $order_id );
```

#### Draft lifecycle
Draft-based operations now use `DraftManagerInterface`:

```php
// Before
$draft_id = $this->storage->generate_id( 'refund' );
$this->storage->store( 'refund', $draft_id, $payload, 600 );

// After
$result = $this->draftManager->create( 'refund', $payload, $preview );
$draft_id = $result->get( 'draft_id' );
```

## [0.1.0] - 2026-01-07
### Added
- Initial AgentWP release with Command Deck UI.
- Intent routing for refunds, order status updates, stock updates, email drafts, analytics, and customer lookup.
- REST API endpoints for settings, usage, search, history, theme, and health checks.
