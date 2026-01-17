# ADR 0001: REST Controller Dependency Resolution

**Date:** 2026-01-17  
**Status:** Accepted

## Context

AgentWP uses a DI container to register and configure core services (e.g., the intent engine and service interfaces).

Historically, some REST controllers instantiated domain services directly (e.g., `new Engine()`), bypassing the container and the service provider wiring. This led to production behavior diverging from intended architecture (handlers and dependencies configured in providers were not used).

## Decision

All REST controllers resolve runtime dependencies via the plugin container using shared helpers on the REST controller base:

- `container(): ?ContainerInterface`
- `resolve(string $id): mixed|null`

Controllers should prefer resolving interfaces where available and may fall back to local instantiation only when the container is unavailable (e.g., defensive fallback for non-standard bootstrap paths).

## Consequences

- Controller construction becomes consistent across endpoints.
- Service wiring defined in providers is respected in runtime requests.
- Tests and e2e harnesses can reliably resolve services from the container.

