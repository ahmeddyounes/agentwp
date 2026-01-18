# Search Index

AgentWP includes a custom search index for fast fulltext search of WooCommerce products, orders, and customers. This document explains how the index works, how to troubleshoot issues, and performance considerations.

## Overview

The search index is a MySQL table (`agentwp_search_index`) that stores normalized, searchable text for:

- **Products**: Name, SKU, and ID
- **Orders**: Order number, customer name, email, and status
- **Customers**: Display name, email, and ID

The index enables fast typeahead search via the `/agentwp/v1/search` REST endpoint.

## How It Works

### Index Table

The index table has the following structure:

| Column | Type | Purpose |
|--------|------|---------|
| `id` | bigint | Auto-increment primary key |
| `type` | varchar(32) | Entity type: products, orders, customers |
| `object_id` | bigint | WooCommerce/WordPress ID |
| `primary_text` | varchar(255) | Display label (product name, order #, customer name) |
| `secondary_text` | varchar(255) | Secondary info (SKU, status, email) |
| `search_text` | longtext | Normalized searchable content |
| `updated_at` | datetime | Last update timestamp |

Key indexes:
- `UNIQUE KEY (type, object_id)` - Ensures one entry per entity
- `FULLTEXT KEY (search_text, primary_text, secondary_text)` - Enables MySQL fulltext search

### Real-Time Updates

The index is updated automatically via WordPress hooks:

| Event | Hook | Action |
|-------|------|--------|
| Product saved | `save_post_product` | Re-index product |
| Order created | `woocommerce_new_order` | Index new order |
| Order updated | `woocommerce_update_order` | Re-index order |
| Product deleted | `before_delete_post` | Remove from index |
| Order deleted | `woocommerce_before_delete_order` | Remove from index |
| User registered | `user_register` | Index if customer role |
| Profile updated | `profile_update` | Re-index customer |

### Incremental Backfill

When the plugin is installed on a site with existing data, a background backfill process indexes existing records:

1. **Activation**: Creates the table and initializes backfill state
2. **Background processing**: A WP-Cron job (`agentwp_search_backfill`) runs every minute and indexes a batch of records
3. **Completion**: Marked when all types have been processed

Backfill parameters:
- **Batch size**: 200 records per type per cycle
- **Time window**: 350ms maximum per cron run
- **Execution context**: WP-Cron only (no frontend overhead)

### Search Query Flow

1. User types in search field
2. Frontend calls `/agentwp/v1/search?q=<query>&types=products,orders`
3. Query is normalized (lowercase, stripped tags, collapsed whitespace)
4. If query is 3+ characters, use MySQL fulltext search
5. If shorter or fulltext unavailable, fall back to LIKE search
6. Results are formatted with primary/secondary display text

## Schema Versioning

The index uses version-based schema migrations:

- **Current version**: `1.0` (stored in `agentwp_search_index_version` option)
- **Version check**: On every `init` hook, compares stored vs current version
- **Upgrade path**: Uses `dbDelta()` for additive changes

Future schema changes follow this pattern:

```php
// Bump version constant
const VERSION = '1.1';

// In ensure_table():
if (version_compare($installed, '1.1', '<')) {
    // Run migration SQL
}
```

## Backfill State

Backfill progress is tracked in the `agentwp_search_index_state` option:

```php
[
    'products'  => 150,  // Cursor at product ID 150
    'orders'    => -1,   // Complete
    'customers' => 0,    // Not started
]
```

State values:
- `0` = Not started
- `N` (positive) = Cursor position (last processed ID)
- `-1` = Complete

Backfill heartbeat is tracked in `agentwp_search_index_backfill_heartbeat`:

```php
[
    'last_run' => 1700000000, // Unix timestamp of most recent run
    'state'    => [ 'products' => 150, 'orders' => 0, 'customers' => -1 ],
]
```

## Troubleshooting

### Search Returns No Results

1. **Check backfill status**:
   ```php
   $state = get_option('agentwp_search_index_state');
   var_dump($state);
   ```
   If any type shows `0`, backfill hasn't started. Ensure WP-Cron is running to trigger it.

2. **Check table exists**:
   ```sql
   SHOW TABLES LIKE '%agentwp_search_index%';
   ```

3. **Check fulltext index**:
   ```sql
   SHOW INDEX FROM wp_agentwp_search_index WHERE Index_type = 'FULLTEXT';
   ```
   If missing, the table may have been created with an incompatible storage engine.

4. **Verify data in index**:
   ```sql
   SELECT COUNT(*) FROM wp_agentwp_search_index GROUP BY type;
   ```

### Backfill Stuck

If backfill state shows a positive number but doesn't progress:

1. **Check for PHP errors**: Look in error logs for issues with `wc_get_product()` or `wc_get_order()` calls

2. **Verify heartbeat**:
   ```php
   $heartbeat = get_option('agentwp_search_index_backfill_heartbeat');
   var_dump($heartbeat);
   ```
   If `last_run` is older than 15 minutes, WP-Cron may not be executing. Ensure a real cron is calling `wp-cron.php` when `DISABLE_WP_CRON` is set.

3. **Force reset**:
   ```php
   delete_option('agentwp_search_index_state');
   // Next cron run will restart from ID 0
   ```

4. **Manual re-index**: For a specific product:
   ```php
   \AgentWP\Search\Index::index_product($product_id);
   ```

### Slow Search Performance

1. **Verify fulltext index exists** (see above)

2. **Check query length**: Queries under 3 characters use slower LIKE search

3. **Check table size**:
   ```sql
   SELECT COUNT(*) FROM wp_agentwp_search_index;
   ```
   Very large tables may need additional optimization

4. **Analyze slow queries**: Enable MySQL slow query log

### Index Out of Sync

If search results don't match actual data:

1. **Check hooks are registered**:
   ```php
   has_action('save_post_product', [\AgentWP\Search\Index::class, 'handle_product_save']);
   ```

2. **Manual re-index all products**:
   ```php
   // Reset products cursor
   $state = get_option('agentwp_search_index_state', []);
   $state['products'] = 0;
   update_option('agentwp_search_index_state', $state, false);
   // Next cron runs will re-index
   ```

3. **Clear and rebuild**:
   ```sql
   -- Only if necessary!
   TRUNCATE TABLE wp_agentwp_search_index;
   ```
   Then delete the state option to restart backfill.

## Performance Considerations

### Backfill Throttling

The backfill system includes several safeguards:

| Limit | Value | Purpose |
|-------|-------|---------|
| Batch size | 200 records | Prevents memory issues |
| Time window | 350ms | Limits cron run impact |
| Lock TTL | 120s | Prevents concurrent backfill runs |
| Stuck recovery | 15 min | Reschedules if no progress detected |
| WP-Cron only | Yes | No frontend overhead |
| Single run | Per request | Prevents race conditions |

### Index Size Estimates

Approximate storage per record:
- Products: ~500 bytes
- Orders: ~600 bytes
- Customers: ~400 bytes

For a store with 10,000 products, 50,000 orders, 20,000 customers:
- Index size: ~45 MB
- Backfill time: depends on cron frequency; at one run per minute, 10,000 products alone take ~50 minutes (plus orders/customers).

### Query Performance

| Query Type | Condition | Expected Performance |
|------------|-----------|---------------------|
| Fulltext | 3+ character query | < 50ms for 100k records |
| LIKE fallback | < 3 characters | 100-500ms for 100k records |
| Empty query | No input | Immediate return (no DB query) |

## REST API

### Search Endpoint

```
GET /wp-json/agentwp/v1/search
```

**Parameters**:
- `q` (required): Search query string
- `types` (optional): Comma-separated types (products, orders, customers)

**Response**:
```json
{
  "products": [
    {
      "id": 123,
      "type": "products",
      "primary": "Blue Widget",
      "secondary": "SKU-123",
      "query": "product:123 sku:\"SKU-123\""
    }
  ],
  "orders": [],
  "customers": []
}
```

## HPOS Compatibility

The search index is compatible with WooCommerce High-Performance Order Storage (HPOS):

- Uses `woocommerce_new_order` and `woocommerce_update_order` hooks
- These hooks work with both legacy post storage and HPOS custom tables
- Order data is fetched via `wc_get_order()` which abstracts storage

## Testing

Unit tests are located in `tests/Unit/Search/`:

- `IndexTest.php` - Core search logic, text normalization, result formatting
- `BackfillTest.php` - Throttling, state management, cursor tracking
- `SchemaVersionTest.php` - Version comparison, table verification

Run tests:
```bash
composer test -- --filter=Search
```

For integration testing with actual MySQL fulltext queries, use `wp-env`:
```bash
npm run test:integration
```

## Related

- [ADR 0006: Search Index Architecture](adr/0006-search-index-architecture.md)
- [API Documentation](API.md)
- [Architecture Overview](ARCHITECTURE.md)
