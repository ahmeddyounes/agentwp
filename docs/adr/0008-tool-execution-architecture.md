# ADR 0008: Tool Execution Architecture

**Date:** 2026-01-18
**Status:** Accepted

## Context

AgentWP uses OpenAI function calling (tools) to enable AI-driven workflows. The system needs a clear architecture for:

1. **Defining tools** - Schema (name, description, parameters)
2. **Registering tools** - Making tools discoverable
3. **Executing tools** - Running tool logic when the AI requests a function call

Two architectural approaches were considered:

### Approach A: Executable Tool Classes

Each tool is a self-contained class with both schema definition AND execution logic:

```php
class SearchOrdersTool implements ExecutableTool {
    public function getSchema(): array { /* JSON schema */ }
    public function execute(array $args): array { /* Business logic */ }
}
```

**Pros:**
- Single file per tool (co-located schema + logic)
- Easy to understand in isolation
- Simple dispatcher: `$tool->execute($args)`

**Cons:**
- Tools often need different services depending on context
- Harder to share execution logic across handlers
- Tool class must know about all its dependencies

### Approach B: Schema Classes + Handler Executors

Tools are split into two concerns:

1. **Schema classes** (`FunctionSchema`) - Define the tool's interface
2. **Handler methods** (`ToolExecutorInterface`) - Execute tool logic with access to handler context

```php
// Schema (what the tool looks like to the AI)
class SearchOrders extends AbstractFunction {
    public function get_name(): string { return 'search_orders'; }
    public function get_parameters(): string { /* JSON schema */ }
}

// Execution (what happens when called)
class OrderSearchHandler implements ToolExecutorInterface {
    public function execute_tool(string $name, array $args) {
        match ($name) {
            'search_orders' => $this->searchService->search(...$args),
            // ...
        };
    }
}
```

**Pros:**
- Execution has full access to handler's injected services
- Handlers can share tools but implement different execution logic
- Schema classes remain pure data (no dependencies)
- Matches OpenAI's model where tools are "offered" and handlers "implement"

**Cons:**
- Two files to maintain per tool (schema + handler logic)
- Dispatch logic in handlers (switch/match statements)

## Decision

**We adopt Approach B: Schema Classes + Handler Executors.**

This is the architecture already established in the codebase and provides the flexibility needed for AgentWP's multi-handler system.

### Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                         Tool Execution Flow                         │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  1. REGISTRATION (Boot Time)                                        │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ IntentServiceProvider                                         │  │
│  │   └── registerToolRegistry()                                  │  │
│  │         ├── new SearchOrders()     → ToolRegistry.register()  │  │
│  │         ├── new PrepareRefund()    → ToolRegistry.register()  │  │
│  │         └── new ConfirmRefund()    → ToolRegistry.register()  │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  2. TOOL SELECTION (Handler Initialization)                         │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ Handler                                                       │  │
│  │   └── getToolNames(): array                                   │  │
│  │         returns ['search_orders', 'select_orders']            │  │
│  │                                                               │  │
│  │   └── getTools(): array                                       │  │
│  │         calls ToolRegistry->getMany(getToolNames())           │  │
│  │         returns [SearchOrders, SelectOrders] schemas          │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  3. AI INTERACTION (Agentic Loop)                                   │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ AbstractAgenticHandler::runAgenticLoop()                      │  │
│  │   └── AIClient->chat(messages, tools: getTools())             │  │
│  │         ├── AI responds with tool_calls                       │  │
│  │         └── For each tool_call:                               │  │
│  │               executeToolCalls() → execute_tool($name, $args) │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  4. EXECUTION (Handler Implementation)                              │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ ConcreteHandler::execute_tool(string $name, array $args)      │  │
│  │   └── match ($name) {                                         │  │
│  │         'search_orders' => $this->service->search(...),       │  │
│  │         'select_orders' => $this->handleSelection(...),       │  │
│  │       }                                                       │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### Key Components

| Component | Location | Responsibility |
|-----------|----------|----------------|
| `FunctionSchema` | `src/Contracts/FunctionSchema.php` | Interface for tool schema definition |
| `AbstractFunction` | `src/AI/Functions/AbstractFunction.php` | Base class with OpenAI format conversion |
| `ToolRegistryInterface` | `src/Contracts/ToolRegistryInterface.php` | Contract for tool schema storage |
| `ToolRegistry` | `src/Intent/ToolRegistry.php` | In-memory hashmap of tool schemas |
| `ToolExecutorInterface` | `src/Contracts/ToolExecutorInterface.php` | Contract for tool execution |
| `AbstractAgenticHandler` | `src/Intent/Handlers/AbstractAgenticHandler.php` | Base handler with agentic loop |

### Recipe: Adding a New Tool

**Step 1: Create the Schema Class**

```php
// src/AI/Functions/MyNewTool.php
namespace AgentWP\AI\Functions;

class MyNewTool extends AbstractFunction {
    public function get_name(): string {
        return 'my_new_tool';
    }

    public function get_description(): string {
        return 'Does something useful with the provided data.';
    }

    public function get_parameters(): string {
        return json_encode([
            'type' => 'object',
            'properties' => [
                'input' => [
                    'type' => 'string',
                    'description' => 'The input to process',
                ],
            ],
            'required' => ['input'],
            'additionalProperties' => false,
        ]);
    }
}
```

**Step 2: Register in IntentServiceProvider**

```php
// src/Providers/IntentServiceProvider.php
private function registerToolRegistry(): void {
    // ... existing registrations ...
    $registry->register(new MyNewTool());
}
```

**Step 3: Declare Tool Usage in Handler**

```php
// In your handler class
protected function getToolNames(): array {
    return ['my_new_tool', /* other tools */];
}
```

**Step 4: Implement Execution Logic**

```php
// In your handler class
public function execute_tool(string $name, array $arguments) {
    return match ($name) {
        'my_new_tool' => $this->executeMyNewTool($arguments),
        // ... other tools
    };
}

private function executeMyNewTool(array $args): array {
    $input = (string) ($args['input'] ?? '');

    // Business logic here, using injected services
    $result = $this->someService->process($input);

    return [
        'success' => true,
        'data' => $result,
    ];
}
```

### Design Principles

1. **Schemas are Pure Data**: `FunctionSchema` classes contain no business logic or dependencies. They only describe the tool interface.

2. **Handlers Own Execution**: The handler that offers a tool is responsible for its execution. This enables context-aware execution with full access to injected services.

3. **Tools are Handler-Scoped**: A handler declares which tools it uses via `getToolNames()`. Tools can be shared across handlers, but execution logic is handler-specific.

4. **Central Registry for Discovery**: `ToolRegistry` provides O(1) lookup and ensures tool names are unique. Handlers retrieve schemas from the registry.

5. **Type-Safe Arguments**: Execution methods should cast arguments to expected types since OpenAI returns all values as primitives.

## Consequences

### Positive

- **Clear separation of concerns**: Schemas define interface, handlers implement logic
- **Flexible execution**: Same tool schema can have different implementations per handler
- **Testable**: Schemas are pure, handlers can be tested with mock services
- **Consistent with intent architecture**: Follows the same patterns as ADR 0002

### Negative

- **Dispatch boilerplate**: Each handler needs a switch/match in `execute_tool()`
- **Two-file maintenance**: Schema changes require updating both schema and handler
- **No compile-time enforcement**: Handler must manually ensure it handles all declared tools

### Neutral

- **Performance**: O(1) registry lookup, match statement dispatch is negligible
- **Learning curve**: Contributors must understand the split architecture

## Alternatives Considered

### A. Executable Tool Classes with DI

```php
class SearchOrdersTool implements ExecutableTool {
    public function __construct(
        private OrderSearchServiceInterface $service
    ) {}

    public function execute(array $args): array {
        return $this->service->search(...);
    }
}
```

**Rejected because:**
- Creates tight coupling between tool and specific service implementation
- Harder to test tools in isolation from their dependencies
- Doesn't fit the agentic loop model where handlers manage context

### B. Central Dispatcher with Tool Registration

```php
ToolDispatcher::register('search_orders', fn($args) => $service->search(...));
```

**Rejected because:**
- Loses handler context (session, memory, AI client)
- Closures make testing and debugging harder
- Doesn't integrate with the existing handler-based architecture

## References

- `src/Contracts/FunctionSchema.php` - Tool schema interface
- `src/Contracts/ToolExecutorInterface.php` - Execution interface
- `src/Contracts/ToolRegistryInterface.php` - Registry interface
- `src/AI/Functions/AbstractFunction.php` - Base schema class
- `src/Intent/ToolRegistry.php` - Registry implementation
- `src/Intent/Handlers/AbstractAgenticHandler.php` - Agentic loop base
- `src/Providers/IntentServiceProvider.php` - Tool registration
- ADR 0002: Intent Handler Registration - Handler architecture patterns
- ADR 0004: OpenAI Client Architecture - AI client integration
