# ADR 0007: Request DTO Validation

**Date:** 2026-01-18
**Status:** Accepted

## Context

AgentWP REST controllers historically used inconsistent validation approaches:

1. **Ad-hoc schema validation** via `RestController::validate_request()` followed by manual payload re-extraction
2. **Route-level validation** via WordPress `validate_callback` in route registration
3. **Request DTOs** with schema validation in dedicated classes

This inconsistency made it difficult for developers to add new endpoints correctly, led to redundant validation checks, and increased the risk of validation gaps.

## Decision

All REST controllers MUST use Request DTOs (extending `AgentWP\DTO\RequestDTO`) for request validation. The single validation recipe for new endpoints is:

### 1. Create a Request DTO

```php
<?php
namespace AgentWP\DTO;

final class MyRequestDTO extends RequestDTO {

    // Override for query params (default is 'json' for body)
    protected function getSource(): string {
        return 'json'; // or 'query'
    }

    protected function getSchema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'field_name' => array(
                    'type' => 'string',
                    'enum' => array( 'value1', 'value2' ),
                ),
            ),
            'required'             => array( 'field_name' ),
            'additionalProperties' => false,
        );
    }

    // Typed accessors for validated data
    public function getFieldName(): string {
        return sanitize_text_field( $this->getString( 'field_name' ) );
    }
}
```

### 2. Use the DTO in the Controller

```php
public function my_endpoint( $request ) {
    $dto = new MyRequestDTO( $request );

    if ( ! $dto->isValid() ) {
        $error = $dto->getError();
        return $this->response_error(
            AgentWPConfig::ERROR_CODE_INVALID_REQUEST,
            $error ? $error->get_error_message() : __( 'Invalid request.', 'agentwp' ),
            400
        );
    }

    $field_value = $dto->getFieldName();
    // ... business logic
}
```

### Available Base Methods in RequestDTO

- `getString(key, default)` - Type-safe string extraction
- `getInt(key, default)` - Type-safe integer extraction
- `getFloat(key, default)` - Type-safe float extraction
- `getBool(key, default)` - Type-safe boolean extraction
- `getArray(key, default)` - Type-safe array extraction
- `has(key)` - Check key existence
- `isValid()` - Check if validation passed
- `getError()` - Get WP_Error if validation failed
- `getRawData()` - Get full validated payload

### DO NOT

- Use `RestController::validate_request()` directly in controllers (it remains for internal use)
- Add `validate_callback` or `args` validation in `register_rest_route()` calls
- Re-extract payload after validation with `$request->get_json_params()`
- Add redundant manual validation checks after schema validation

## Consequences

- Single, consistent validation pattern across all endpoints
- Schema and business logic encapsulated in dedicated DTO classes
- Type-safe accessors reduce boilerplate in controllers
- Controllers focus on business logic, not validation details
- New endpoints follow a predictable recipe

## Reference

Existing Request DTOs:
- `IntentRequestDTO` - Intent creation requests
- `SettingsUpdateDTO` - Settings update requests
- `ApiKeyRequestDTO` - API key update requests
- `UsageQueryDTO` - Usage query requests
- `ThemeRequestDTO` - Theme preference requests
- `HistoryRequestDTO` - History update requests
- `SearchQueryDTO` - Search query requests
- `AnalyticsQueryDTO` - Analytics query requests
