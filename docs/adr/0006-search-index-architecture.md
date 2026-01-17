# ADR 0006: Search Index Architecture

**Date:** 2026-01-17
**Status:** Accepted

## Context

`src/Search/Index.php` manages a custom MySQL table (`agentwp_search_index`) for fulltext search of WooCommerce products, orders, and customers. The current implementation is entirely static:

- All methods are `public static` (e.g., `Index::init()`, `Index::search()`, `Index::activate()`)
- Hook registration happens via `add_action()` with static method callbacks
- Version tracking uses WordPress options (`agentwp_search_index_version`)
- State tracking for incremental backfill uses options (`agentwp_search_index_state`)
- Direct database access via `global $wpdb`

The class is called from two places:
1. **Plugin activation**: `Plugin::activate()` calls `Search\Index::activate()`
2. **Plugin bootstrap**: `Plugin::__construct()` calls `Search\Index::init()`

The search API is consumed by `SearchController::get_results()` via `Index::search($query, $types, $limit)`.

### Current Issues

1. **Testability**: Static methods accessing `$wpdb` directly cannot be mocked or replaced in unit tests
2. **Container bypass**: The class does not participate in the DI container, unlike services governed by ADR 0001
3. **Global state**: Version and backfill state depend on WordPress options, making tests stateful
4. **Schema coupling**: Table creation logic is interleaved with business logic

### Alternatives Considered

**Option A: Convert to Container-Managed Service**
- Create `SearchIndexInterface` and register instance in container
- Inject `$wpdb` wrapper, clock, and cache dependencies
- Controllers resolve the service following ADR 0001 patterns

**Option B: Keep Static with Targeted Improvements**
- Retain static design for activation/lifecycle hooks (WordPress convention)
- Add interface extraction only for the `search()` method if needed
- Improve testability via function mocking or integration tests only

## Decision

**Keep `Index` as a static class with no container registration.**

### Rationale

| Factor | Static Design | Container-Managed |
|--------|---------------|-------------------|
| **WordPress conventions** | ✅ Matches WP patterns for activation hooks | ❌ Requires adapter for activation context |
| **Lifecycle timing** | ✅ Works before container boots | ❌ Requires container availability |
| **Migration complexity** | ✅ None | ❌ Multi-phase migration, risk of regressions |
| **Test strategy** | Integration tests with test DB | Unit tests with mocks |
| **Current call sites** | 3 static calls | Would require 3+ refactors |
| **Real-world benefit** | Activation/backfill work correctly | Marginal testability gain |

The key considerations:

1. **Activation timing**: `Plugin::activate()` runs during plugin activation hook, before the container is reliably available. Static methods guarantee execution.

2. **WordPress hook compatibility**: WordPress actions like `save_post_product` expect callable arrays with static methods or closures. Static design aligns naturally.

3. **Limited consumer surface**: Only `SearchController` consumes `Index::search()`. If needed, a thin wrapper interface can be added without refactoring the core class.

4. **Test strategy alignment**: Search index functionality is best tested via integration tests that exercise actual MySQL fulltext queries. Mocking `$wpdb` would not validate real behavior.

5. **Cost-benefit**: The migration effort and risk outweigh the testability benefits for this specific component.

### Schema Migration Strategy

The current version-based migration pattern is retained and formalized:

```php
const VERSION        = '1.0';
const VERSION_OPTION = 'agentwp_search_index_version';
```

**Migration Protocol:**

1. **Version check on every load**: `ensure_table()` compares stored version against `self::VERSION`
2. **Idempotent schema updates**: `dbDelta()` handles additive changes (new columns, indexes)
3. **Version bump after migration**: `update_option(VERSION_OPTION, self::VERSION)` after successful `dbDelta()`
4. **Destructive changes require explicit migration**: Dropping columns/indexes must be handled in versioned migration methods

**Future Version Migrations:**

When schema changes are needed, follow this pattern:

```php
const VERSION = '1.1';

public static function ensure_table() {
    $installed = get_option(self::VERSION_OPTION, '');

    if ($installed === self::VERSION && self::table_exists($table)) {
        return;
    }

    // Run dbDelta for additive changes
    dbDelta($sql);

    // Run explicit migrations for destructive changes
    if (version_compare($installed, '1.1', '<')) {
        self::migrate_to_1_1();
    }

    update_option(self::VERSION_OPTION, self::VERSION, false);
}

private static function migrate_to_1_1() {
    global $wpdb;
    // Explicit migration SQL for breaking changes
}
```

### Performance Guardrails

The following constraints are codified to prevent performance regressions:

#### Backfill Limits

| Constant | Value | Purpose |
|----------|-------|---------|
| `BACKFILL_LIMIT` | 200 | Max records per backfill batch |
| `BACKFILL_WINDOW` | 0.35s | Max time per backfill cycle |
| `DEFAULT_LIMIT` | 5 | Default search result limit |

**Backfill behavior:**
- Runs only in admin context (`is_admin()`)
- Processes at most 200 records per type per page load
- Stops after 350ms regardless of progress
- Tracks cursor position in options for resumption

#### Query Constraints

| Constraint | Implementation |
|------------|----------------|
| Result limit cap | `min(100, max(1, absint($limit)))` |
| Fulltext minimum | 3 characters required for fulltext mode |
| Fallback to LIKE | Short queries use `LIKE %term%` |

#### Index Requirements

The following indexes must exist for acceptable performance:

```sql
PRIMARY KEY (id)
UNIQUE KEY type_object (type, object_id)
KEY type_idx (type)
KEY object_idx (object_id)
FULLTEXT KEY search_fulltext (search_text, primary_text, secondary_text)
```

**Monitoring:** If fulltext index is unavailable (detected via `supports_fulltext()`), queries fall back to LIKE-based search with potentially slower performance on large datasets.

### Testing Strategy

Given the static design, testing follows this approach:

1. **Integration tests**: Test actual search behavior against a WordPress test database
2. **Hook verification**: Assert that expected hooks are registered via `has_action()`/`has_filter()`
3. **Schema validation**: Verify table structure matches expected schema after activation
4. **Backfill verification**: Test incremental backfill with controlled record counts

Example integration test structure:

```php
class IndexIntegrationTest extends WP_UnitTestCase {
    public function test_search_returns_indexed_products(): void {
        // Create test product
        $product_id = $this->factory->post->create([
            'post_type' => 'product',
            'post_title' => 'Test Widget',
        ]);

        // Trigger indexing
        Index::index_product($product_id);

        // Verify search
        $results = Index::search('widget', ['products'], 10);

        $this->assertCount(1, $results['products']);
        $this->assertEquals($product_id, $results['products'][0]['id']);
    }
}
```

### Future Considerations

If testability requirements change significantly, a **thin interface extraction** can be added without full container migration:

```php
interface SearchInterface {
    public function search(string $query, array $types, int $limit): array;
}

class StaticSearchAdapter implements SearchInterface {
    public function search(string $query, array $types, int $limit): array {
        return Index::search($query, $types, $limit);
    }
}
```

This adapter could then be registered in the container for controllers that need injection, while preserving the static implementation for activation/lifecycle scenarios.

## Consequences

### Positive

- **Zero migration risk**: No changes to working production code
- **WordPress alignment**: Static methods match WP plugin conventions
- **Activation reliability**: Works before container initialization
- **Clear versioning**: Formalized schema migration protocol
- **Performance bounds**: Documented guardrails prevent regressions

### Negative

- **Limited unit testability**: Integration tests required for search behavior
- **ADR 0001 exception**: This class does not follow container resolution patterns
- **Global state**: Version and backfill state remain in WordPress options

### Neutral

- **Performance**: No change from current implementation
- **API surface**: `Index::search()` signature unchanged
- **Consumer impact**: `SearchController` continues to call static methods

## References

- ADR 0001: REST Controller Dependency Resolution
- `src/Search/Index.php` - Current implementation
- `src/Rest/SearchController.php` - Primary consumer
- `src/Plugin.php` - Activation and bootstrap integration
