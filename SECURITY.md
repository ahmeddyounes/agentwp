# Security Policy

## Reporting a vulnerability

Please report suspected security issues privately to `security@agentwp.example`. Include
steps to reproduce and any relevant logs. We will acknowledge receipt within 72 hours
and provide a remediation plan or timeline.

## Security architecture

AgentWP is a WordPress plugin that exposes privileged REST endpoints for WooCommerce
operators. The security model relies on WordPress authentication, strict capability
checks, and CSRF protection via REST nonces for state-changing requests.

Key controls:
- Authentication: WordPress cookie auth for admin users.
- Authorization: `current_user_can( 'manage_woocommerce' )` on all AgentWP endpoints.
- CSRF protection: REST nonce required for POST/PUT/PATCH/DELETE requests.
- Rate limiting: per-user throttling via transients.
- Input validation: JSON schema validation plus explicit sanitization (e.g., `sanitize_text_field`,
  `rest_sanitize_boolean`, `absint`).
- Output escaping: admin UI output uses WordPress escaping helpers (`esc_html__`, JSON encoding).
- SQL safety: all dynamic queries use `$wpdb->prepare()` and safe placeholders.
- Secrets at rest: OpenAI API keys are encrypted before storage using WordPress salts.
- Logging: REST logs store request metadata and body/query keys only (no PII or secrets).

## Data handling

Sensitive data handled by AgentWP:
- OpenAI API key: stored encrypted in the WordPress options table. The last 4 digits are
  stored separately to display key presence without exposing the full value.
- Usage telemetry: token counts and cost metadata; no prompts, PII, or API keys are stored.

AgentWP does not log API keys and avoids storing PII in error metadata or REST logs.

## Dependency audit

Before release:
- `composer audit` (PHP dependencies)
- `npm audit` (JS dependencies)

Address any reported vulnerabilities prior to shipping.

## Manual security review checklist

- [x] All REST endpoints enforce capability checks.
- [x] Nonce checks applied to state-changing REST requests.
- [x] Inputs validated and sanitized before use.
- [x] Outputs escaped for admin-rendered HTML.
- [x] SQL queries use `$wpdb->prepare()` with safe placeholders.
- [x] API keys are encrypted at rest and never logged.
- [x] REST logs exclude request bodies and PII.

## Release checklist

- [ ] Dependency audit complete (`composer audit`, `npm audit`).
