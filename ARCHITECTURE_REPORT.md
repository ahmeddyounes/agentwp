# Architecture Analysis Report: AgentWP

**Analysis Type:** Deep Architecture Review
**Persona:** Solution Architect
**Depth:** Comprehensive
**Generated:** 2026-01-17

---

## Executive Summary

AgentWP is a **well-architected WordPress plugin** with a React frontend, demonstrating solid separation of concerns and modern patterns. The codebase follows **Layered + Domain-Driven architecture** on the backend with **Feature-Based organization** on the frontend.

| Metric | Assessment |
|--------|------------|
| Overall Architecture Score | **B+** (Good with notable issues) |
| Backend Design | **A-** (Strong DI, interfaces, providers) |
| Frontend Design | **A** (Clean stores, hooks, features) |
| Integration Layer | **B-** (DI bypass fixed; standardized resolution) |
| Extensibility | **A** (WordPress hooks, filter system) |

---

## Status Update (Post-Fix)

As of **2026-01-17**, the following items from this report have been implemented in the codebase:

- IntentController no longer instantiates `new Engine()` and instead resolves via the container (`src/Rest/IntentController.php`).
- A shared controller helper now exposes `container()`/`resolve()` (`src/API/RestController.php`).
- AnalyticsController resolves services via interfaces (`src/Rest/AnalyticsController.php`).
- Frontend API client has been migrated to TypeScript (`react/src/api/AgentWPClient.ts`).
- Engine now accepts the interface types for classifier/context/memory (`src/Intent/Engine.php`).

Remaining suggestions (e.g., deprecating legacy handler discovery methods and adding ADRs) are still applicable.

---

## Architectural Overview

### Project Structure
```
agentwp/                          # Monorepo
├── src/                          # PHP Backend (156 files)
│   ├── Plugin.php               # Bootstrap orchestrator
│   ├── Container/               # Custom DI container
│   ├── Contracts/               # 25 interfaces (ISP compliant)
│   ├── Providers/               # 5 service providers
│   ├── Intent/                  # Core domain (Engine, Handlers)
│   ├── Services/                # 8 domain services
│   ├── Infrastructure/          # Platform adapters
│   ├── AI/                      # OpenAI integration
│   └── Rest/                    # 5 REST controllers
│
├── react/                        # Frontend (52 TS/JSX files)
│   └── src/
│       ├── features/            # 6 feature modules
│       ├── stores/              # 8 Zustand stores
│       ├── hooks/               # 11 custom hooks
│       ├── api/                 # Centralized API client
│       └── utils/               # Shared utilities
```

---

## Findings by Severity

### CRITICAL (P0)

#### 1. DI Container Bypass in IntentController (Resolved)
**Location:** `src/Rest/IntentController.php:69`

```php
// CURRENT (fixed)
$engine = $this->resolve( Engine::class );
if ( ! $engine instanceof Engine ) {
    $engine = new Engine();
}
```

**Status:** Fixed (2026-01-17)

**Historical impact (before fix):**
- All 7 handlers registered via `IntentServiceProvider` are **never used**
- Services injected via interfaces (OrderSearchServiceInterface, etc.) are bypassed
- Engine uses fallback default instantiation for all dependencies

**Root Cause:** The IntentServiceProvider correctly registers the Engine as a singleton with all dependencies, but the controller instantiates directly.

**Resolution:** Controller now resolves Engine via the shared REST controller `resolve()` helper and only falls back to instantiation when unavailable.

---

### HIGH (P1)

#### 2. Inconsistent DI Patterns Across Controllers

| Controller | DI Pattern | Assessment |
|------------|------------|------------|
| IntentController | `$this->resolve(Engine::class)` with fallback | Correct |
| AnalyticsController | `$this->resolve(AnalyticsServiceInterface::class)` | Correct |
| SearchController | `Index::search()` static | Acceptable for stateless |
| SettingsController | Local logic + local instantiation (`new Encryption()`) | Acceptable (could be DI’d later) |
| HealthController | No dependencies | N/A |

**Recommendation:** Establish standard controller pattern:
```php
abstract class RestController {
    protected function resolve(string $class) {
        $container = Plugin::container();
        return $container?->has($class)
            ? $container->get($class)
            : null;
    }
}
```

#### 3. Engine Constructor Complexity
**Location:** `src/Intent/Engine.php:74-81`

The Engine accepts 6 optional dependencies with fallback instantiation:
```php
public function __construct(
    array $handlers = array(),
    ?FunctionRegistry $function_registry = null,
    ?ContextBuilderInterface $context_builder = null,
    ?IntentClassifierInterface $classifier = null,
    ?MemoryStoreInterface $memory = null,
    ?HandlerRegistry $handler_registry = null
)
```

**Issues:**
- 6 dependencies is at the upper bound of good practice
- Fallback instantiation defeats DI purpose

**Recommendation:**
- Use factory method or builder pattern
- Keep dependencies passed via DI in production (interfaces are now used for classifier/context/memory)
- Require dependencies via DI, don't provide fallbacks in production code

---

### MEDIUM (P2)

#### 4. Frontend API Client Not TypeScript (Resolved)
**Location:** `react/src/api/AgentWPClient.ts`

**Status:** Fixed (2026-01-17)

**Recommendation:** Convert to `AgentWPClient.ts` with proper typing:
```typescript
interface ApiResponse<T> {
  success: boolean;
  data: T;
  error?: ApiError;
}
```

#### 5. Interface vs Concrete Class Registration
**Location:** `src/Providers/InfrastructureServiceProvider.php:173-180`

```php
// Registers both concrete and interface
$this->container->singleton(AIClientFactory::class, ...);
$this->container->singleton(AIClientFactoryInterface::class,
    fn($c) => $c->get(AIClientFactory::class)
);
```

Historical issue (before fix): AnalyticsController checked for concrete classes instead of interfaces.

**Status:** Fixed (2026-01-17) — controllers now resolve via interfaces.

#### 6. Multiple Handler Discovery Mechanisms
**Location:** `src/Intent/Engine.php:137-168`

Three ways to discover handler intents:
1. `HandlesIntent` PHP attribute
2. `getSupportedIntents()` method
3. `getIntent()` method (legacy)

Plus fallback O(n) `canHandle()` iteration.

**Recommendation:** Standardize on attribute-based registration, deprecate others.

---

### LOW (P3)

#### 7. Services Without Dependencies
Several services are instantiated without DI:
```php
fn() => new OrderSearchService()
fn() => new CustomerService()
fn() => new AnalyticsService()
```

If these never need dependencies, consider:
- Making them static utility classes, or
- Document as intentionally stateless

#### 8. Frontend Store Proliferation
8 Zustand stores may indicate over-fragmentation:
- useModalStore, useThemeStore, useCommandStore, useVoiceStore
- useDraftStore, useSearchStore, useAnalyticsStore, useUsageStore

**Assessment:** Currently acceptable, but monitor for cross-store dependencies.

---

## Architectural Strengths

### Backend Excellence

1. **Custom DI Container** (`src/Container/Container.php`)
   - Circular dependency detection
   - Singleton and transient bindings
   - Service tagging for group retrieval
   - Auto-wiring with reflection
   - Union type support

2. **Interface Segregation** (25 contracts)
   - AI layer: `OpenAIClientInterface`, `AIClientFactoryInterface`
   - Data: `OrderRepositoryInterface`, `CacheInterface`
   - Domain: `IntentClassifierInterface`, `ContextBuilderInterface`
   - Infrastructure: `HttpClientInterface`, `ClockInterface`, `SleeperInterface`

3. **Service Provider Pattern**
   - `CoreServiceProvider` - Plugin managers
   - `InfrastructureServiceProvider` - Platform adapters
   - `ServicesServiceProvider` - Domain services
   - `IntentServiceProvider` - Intent handlers
   - `RestServiceProvider` - API registration

4. **Extension Points**
   ```php
   do_action('agentwp_register_providers', $this->container);
   do_action('agentwp_boot_providers', $this->container);
   apply_filters('agentwp_intent_handlers', $handlers);
   apply_filters('agentwp_default_function_mapping', $mapping);
   ```

### Frontend Excellence

1. **Feature-Based Organization**
   - Self-contained feature modules
   - Clean barrel exports
   - Minimal cross-feature coupling

2. **State Management**
   - Zustand for lightweight reactive state
   - Persistence middleware for command history
   - System theme preference sync

3. **Centralized API Client**
   - Comprehensive error categorization (7 types)
   - Abort signal support
   - Consistent response format

4. **Shadow DOM Isolation**
   - Prevents CSS conflicts with WordPress admin
   - Portal pattern for modals

---

## Dependency Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                        REST API Layer                           │
│  ┌──────────────────┐ ┌──────────────────┐ ┌────────────────┐  │
│  │IntentController  │ │AnalyticsController│ │SearchController│  │
│  │ ✅ uses container│ │ ✅ uses container │ │ Index::static  │  │
│  └────────┬─────────┘ └────────┬─────────┘ └────────────────┘  │
│           │                    │                                │
└───────────┼────────────────────┼────────────────────────────────┘
            │                    │
            ▼                    ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Domain Layer                               │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                    Intent Engine                         │   │
│  │  ┌──────────────┐ ┌───────────────┐ ┌────────────────┐  │   │
│  │  │IntentClassifier│ │ContextBuilder │ │ MemoryStore   │  │   │
│  │  └──────────────┘ └───────────────┘ └────────────────┘  │   │
│  │  ┌─────────────────────────────────────────────────────┐│   │
│  │  │              Handler Registry                        ││   │
│  │  │  OrderSearch | OrderRefund | OrderStatus | ...       ││   │
│  │  └─────────────────────────────────────────────────────┘│   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐               │
│  │AnalyticsSvc │ │ CustomerSvc │ │ EmailDraftSvc│ ...          │
│  └─────────────┘ └─────────────┘ └─────────────┘               │
└─────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                   Infrastructure Layer                          │
│  ┌──────────────┐ ┌───────────────┐ ┌─────────────────────┐    │
│  │WP HttpClient │ │ WP ObjectCache│ │ WooCommerce OrderRepo│    │
│  └──────────────┘ └───────────────┘ └─────────────────────┘    │
│  ┌──────────────┐ ┌───────────────┐ ┌─────────────────────┐    │
│  │ SystemClock  │ │ RealSleeper   │ │ TransientDraftStorage│   │
│  └──────────────┘ └───────────────┘ └─────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
```

---

## Recommended Improvements

### Immediate Actions (Sprint 1)

1. **Fix IntentController DI bypass** (DONE 2026-01-17)
	   ```php
	   // In IntentController::create_intent()
	   $engine = $this->resolve( Engine::class );
	   if ( ! $engine instanceof Engine ) {
	       $engine = new Engine();
	   }
	   ```

2. **Add container method to RestController base** (DONE 2026-01-17)
   ```php
   protected function container(): ?ContainerInterface {
       return Plugin::container();
   }
   ```

### Short-term (Sprint 2-3)

3. **Standardize controller DI pattern** (DONE 2026-01-17)
4. **Migrate AgentWPClient.js to TypeScript** (DONE 2026-01-17)
5. **Complete MemoryStore interface migration** (DONE 2026-01-17)

### Medium-term (Quarter)

6. **Deprecate legacy handler discovery methods**
7. **Consider Request Handler pattern for controllers**
8. **Add architecture decision records (ADRs)** (DONE 2026-01-17)

---

## Metrics Summary

| Category | Count | Assessment |
|----------|-------|------------|
| PHP Files | 156 | Large but organized |
| TypeScript/JSX Files | 52 | Clean structure |
| Interfaces | 25 | Excellent ISP adherence |
| Service Providers | 5 | Good separation |
| REST Controllers | 5 | Adequate |
| Zustand Stores | 8 | Within bounds |
| Custom Hooks | 11 | Well-organized |
| Intent Handlers | 7+ | Extensible |
| AI Functions | 16 | Comprehensive |

---

## Conclusion

AgentWP demonstrates **mature architectural thinking** with strong fundamentals in dependency injection, interface segregation, and feature-based organization. The previously critical issue (the **DI bypass in IntentController**) has been fixed, restoring the intended dependency flow through service providers.

The frontend architecture is exemplary, with clean separation between stores, hooks, and features. The API client migration to TypeScript is complete, improving end-to-end type safety.

---

*Report generated by Claude Code Architecture Analysis*
