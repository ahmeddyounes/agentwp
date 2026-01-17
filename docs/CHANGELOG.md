# Changelog
All notable changes to AgentWP are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Added
- Comprehensive documentation suite and OpenAPI reference.
- `ApiKeyStorage` service for centralized API key management (`src/Security/ApiKeyStorage.php`).
- `DemoClient` for stubbed responses in demo mode without API key (`src/Demo/DemoClient.php`).
- Architecture inventory document (`docs/architecture-inventory.md`).

### Changed
- **BREAKING**: Plugin bootstrap consolidated to single-path architecture. `src/Plugin.php` no longer directly registers menus, assets, or REST endpoints. All wiring flows through `src/Plugin/*` managers and service providers.
- **BREAKING**: Option keys and defaults consolidated into `AgentWPConfig` constants. All components now reference these constants instead of hardcoded strings.
- Order search service consolidated to pipeline architecture (`src/Services/OrderSearch/*`). The previous `OrderSearchService` class has been removed.
- Demo mode credential behavior made explicit: when demo mode is enabled with a demo key, real API calls are made; when enabled without a key, stubbed responses are returned via `DemoClient`.
- `ServiceResult` DTO and `EmailContextBuilder` deferred to future integration (kept for planned enhancements).

### Fixed
- Removed stale `HandlerServiceProvider` import and `BulkHandler` references.
- Removed unused `$model` and `$intent_type` properties from `DemoClient`.
- Fixed PHPStan and PHPCS warnings in consolidated codebase.

### Removed
- Removed `src/Services/OrderSearchService.php` (replaced by pipeline architecture).
- Removed dead code paths from `src/Plugin.php` (menu/assets/rest formatting methods).

## [0.1.0] - 2026-01-07
### Added
- Initial AgentWP release with Command Deck UI.
- Intent routing for refunds, order status updates, stock updates, email drafts, analytics, and customer lookup.
- REST API endpoints for settings, usage, search, history, theme, and health checks.
