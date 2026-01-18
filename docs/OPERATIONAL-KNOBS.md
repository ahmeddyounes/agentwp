# Operational Knobs

AgentWP provides configurable operational settings via WordPress filters. Operators can tune behavior without code edits by adding filter hooks to their theme's `functions.php` or a custom plugin.

All configuration values have sensible defaults defined in `AgentWP\Config\AgentWPConfig`.

## Filter Naming Convention

All filters follow the pattern:
```
agentwp_config_{key_with_underscores}
```

For example, the key `rate_limit.requests` becomes the filter `agentwp_config_rate_limit_requests`.

## Configuration Categories

### Rate Limiting

Control API request rate limiting to prevent abuse.

| Filter | Default | Description |
|--------|---------|-------------|
| `agentwp_config_rate_limit_requests` | 30 | Maximum requests per time window |
| `agentwp_config_rate_limit_window` | 60 | Time window duration in seconds |
| `agentwp_config_rate_limit_lock_timeout` | 5 | Lock timeout in seconds for atomic operations |
| `agentwp_config_rate_limit_lock_attempts` | 10 | Max attempts to acquire lock |
| `agentwp_config_rate_limit_lock_delay_us` | 10000 | Delay between lock attempts (microseconds) |

**Example:**
```php
// Allow 60 requests per 120 seconds
add_filter( 'agentwp_config_rate_limit_requests', fn() => 60 );
add_filter( 'agentwp_config_rate_limit_window', fn() => 120 );
```

### Cache Settings

Configure cache behavior including TTLs and lock settings for stampede prevention.

| Filter | Default | Description |
|--------|---------|-------------|
| `agentwp_config_cache_ttl_default` | 3600 | Default cache TTL in seconds (1 hour) |
| `agentwp_config_cache_ttl_short` | 300 | Short cache TTL in seconds (5 minutes) |
| `agentwp_config_cache_ttl_draft` | 3600 | Draft storage cache TTL in seconds |
| `agentwp_config_cache_lock_timeout` | 30 | Cache lock timeout in seconds |
| `agentwp_config_cache_lock_attempts` | 50 | Max lock acquisition attempts |
| `agentwp_config_cache_lock_delay_us` | 20000 | Delay between lock attempts (microseconds) |
| `agentwp_config_cache_ttl_minimum` | 300 | Minimum cache TTL (5 minutes) |

**Example:**
```php
// Increase cache lock timeout for slow database environments
add_filter( 'agentwp_config_cache_lock_timeout', fn() => 60 );
```

### Agentic Loop Settings

Control the AI agent interaction loop behavior.

| Filter | Default | Description |
|--------|---------|-------------|
| `agentwp_config_agentic_max_turns` | 5 | Maximum interaction turns before giving up |

**Example:**
```php
// Allow more turns for complex queries
add_filter( 'agentwp_config_agentic_max_turns', fn() => 10 );
```

### API Client Settings

Configure API timeouts and retry behavior.

| Filter | Default | Description |
|--------|---------|-------------|
| `agentwp_config_api_timeout_default` | 60 | Default API timeout in seconds |
| `agentwp_config_api_timeout_min` | 1 | Minimum API timeout in seconds |
| `agentwp_config_api_timeout_max` | 300 | Maximum API timeout in seconds |
| `agentwp_config_api_max_retries` | 10 | Maximum retry attempts |
| `agentwp_config_api_initial_delay` | 1 | Initial retry delay in seconds |
| `agentwp_config_api_max_delay` | 60 | Maximum retry delay in seconds |

### OpenAI API Settings

Configure OpenAI-specific API behavior.

| Filter | Default | Description |
|--------|---------|-------------|
| `agentwp_config_openai_api_base_url` | `https://api.openai.com/v1` | OpenAI API base URL |
| `agentwp_config_openai_default_model` | `gpt-4o-mini` | Default OpenAI model |
| `agentwp_config_openai_timeout_default` | 60 | Default timeout in seconds |
| `agentwp_config_openai_timeout_min` | 1 | Minimum timeout in seconds |
| `agentwp_config_openai_timeout_max` | 300 | Maximum timeout in seconds |
| `agentwp_config_openai_validation_timeout` | 3 | API key validation timeout |
| `agentwp_config_openai_max_retries` | 3 | Maximum retry attempts |
| `agentwp_config_openai_base_delay_ms` | 1000 | Base retry delay in milliseconds |
| `agentwp_config_openai_max_delay_ms` | 30000 | Maximum retry delay in milliseconds |
| `agentwp_config_openai_jitter_factor` | 0.25 | Retry jitter factor |
| `agentwp_config_openai_retryable_codes` | `[429,500,502,503,504,520,521,522,524]` | HTTP codes that trigger retry |

**Example:**
```php
// Use a custom OpenAI-compatible endpoint
add_filter( 'agentwp_config_openai_api_base_url', fn() => 'https://my-proxy.example.com/v1' );
```

### Stream Response Limits

Control streaming response processing limits.

| Filter | Default | Description |
|--------|---------|-------------|
| `agentwp_config_stream_max_content_length` | 1048576 | Max content length (1MB) |
| `agentwp_config_stream_max_tool_calls` | 50 | Max tool calls per stream |
| `agentwp_config_stream_max_raw_chunks` | 100 | Max raw chunks to buffer |
| `agentwp_config_stream_max_tool_arg_length` | 102400 | Max tool argument length (100KB) |

### Search Index Settings

Configure the search index backfill behavior.

| Filter | Default | Description |
|--------|---------|-------------|
| `agentwp_config_search_default_limit` | 5 | Default search result limit |
| `agentwp_config_search_backfill_limit` | 200 | Batch size for backfill operations |
| `agentwp_config_search_backfill_window` | 0.35 | Time window in seconds for backfill |

**Example:**
```php
// Increase backfill batch size for faster indexing
add_filter( 'agentwp_config_search_backfill_limit', fn() => 500 );
```

### Usage Tracking Settings

Configure usage data retention and query limits.

| Filter | Default | Description |
|--------|---------|-------------|
| `agentwp_config_usage_retention_days` | 90 | Days to retain usage data |
| `agentwp_config_usage_query_max_rows` | 50000 | Max rows for usage queries |

**Example:**
```php
// Retain usage data for 180 days
add_filter( 'agentwp_config_usage_retention_days', fn() => 180 );
```

**Usage purge scheduling:**

- Runs daily via WP-Cron (`agentwp_usage_purge`).
- AgentWP auto-reschedules if the purge has not run in 48+ hours (per-site).
- On multisite, purge times are jittered per site to avoid simultaneous load spikes.
- If `DISABLE_WP_CRON` is set, configure a real system cron to call `wp-cron.php`
  (or use WP-CLI `wp cron event run --due-now`) so purges run.

### Order Search Settings

Configure order search behavior.

| Filter | Default | Description |
|--------|---------|-------------|
| `agentwp_config_order_search_default_limit` | 10 | Default order search limit |
| `agentwp_config_order_search_max_limit` | 50 | Maximum order search limit |
| `agentwp_config_order_status_max_bulk` | 50 | Max bulk order status updates |

### Customer Service Settings

Configure customer-related operations.

| Filter | Default | Description |
|--------|---------|-------------|
| `agentwp_config_customer_recent_limit` | 5 | Recent customers limit |
| `agentwp_config_customer_top_limit` | 5 | Top customers limit |
| `agentwp_config_customer_order_batch` | 200 | Batch size for customer orders |
| `agentwp_config_customer_max_order_ids` | 2000 | Max order IDs for customer queries |

### Health Status Thresholds

Configure customer health status thresholds.

| Filter | Default | Description |
|--------|---------|-------------|
| `agentwp_config_health_active_days` | 60 | Days threshold for "active" status |
| `agentwp_config_health_at_risk_days` | 180 | Days threshold for "at risk" status |

### History and Logging Limits

Configure history and logging limits.

| Filter | Default | Description |
|--------|---------|-------------|
| `agentwp_config_history_limit` | 50 | Max command history entries |
| `agentwp_config_favorites_limit` | 50 | Max favorites entries |
| `agentwp_config_rest_log_limit` | 50 | Max REST log entries |

### Intent Classification Settings

Configure intent classification weights and thresholds.

| Filter | Default | Description |
|--------|---------|-------------|
| `agentwp_config_intent_weight_order_search` | 1.0 | Order search intent weight |
| `agentwp_config_intent_weight_order_refund` | 1.0 | Order refund intent weight |
| `agentwp_config_intent_weight_order_status` | 1.0 | Order status intent weight |
| `agentwp_config_intent_weight_product_stock` | 1.0 | Product stock intent weight |
| `agentwp_config_intent_weight_email_draft` | 1.0 | Email draft intent weight |
| `agentwp_config_intent_weight_analytics_query` | 1.0 | Analytics query intent weight |
| `agentwp_config_intent_weight_customer_lookup` | 1.0 | Customer lookup intent weight |
| `agentwp_config_confidence_threshold_high` | 0.85 | High confidence threshold |
| `agentwp_config_confidence_threshold_medium` | 0.70 | Medium confidence threshold |
| `agentwp_config_confidence_threshold_low` | 0.55 | Low confidence threshold |
| `agentwp_config_intent_similarity_threshold` | 0.6 | Intent similarity threshold |
| `agentwp_config_intent_minimum_threshold` | 0.0 | Minimum intent score threshold |

### Customer Health Weights

Configure customer health score calculation weights.

| Filter | Default | Description |
|--------|---------|-------------|
| `agentwp_config_health_weight_recency` | 0.5 | Order recency weight |
| `agentwp_config_health_weight_frequency` | 0.3 | Order frequency weight |
| `agentwp_config_health_weight_value` | 0.2 | Order value weight |

## Best Practices

1. **Test in staging first**: Always test configuration changes in a staging environment before applying to production.

2. **Monitor performance**: After changing timeouts or limits, monitor your site's performance and error logs.

3. **Use a custom plugin**: For production sites, create a custom plugin for your filter hooks rather than modifying `functions.php`.

4. **Document changes**: Keep a record of what filters you've modified and why.

**Example custom plugin:**
```php
<?php
/**
 * Plugin Name: AgentWP Custom Config
 * Description: Custom configuration for AgentWP
 */

// Increase rate limits for high-traffic sites
add_filter( 'agentwp_config_rate_limit_requests', fn() => 100 );
add_filter( 'agentwp_config_rate_limit_window', fn() => 60 );

// Increase cache lock timeout for slow databases
add_filter( 'agentwp_config_cache_lock_timeout', fn() => 60 );

// Retain usage data longer
add_filter( 'agentwp_config_usage_retention_days', fn() => 180 );
```
