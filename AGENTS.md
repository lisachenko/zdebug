# Contributor notes for ZDebug

ZDebug is a pure-PHP step debugger: your IDE attaches over DBGp (Xdebug's protocol)
while PHP code drives the Zend VM through [z-engine](https://github.com/lisachenko/z-engine)
FFI hooks. There is no C extension. Read z-engine's `docs/self-debugging.md` first — it
is the design ground truth this package implements.

The project is *ZDebug*; the extension identifier, the Composer package, the `ZDEBUG_*`
environment and the `<engine>` name on the wire are all lowercase `zdebug`. Keep that
split: prose says ZDebug, code says `zdebug`.

## Branch model

`main` supports PHP 8.4 and 8.5 in parallel. Engine memory layouts change between PHP
minors, so each minor rides its own z-engine line (`8.4.x-dev` on PHP 8.4, `8.5.x-dev`
on PHP 8.5) and Composer resolves the matching one for the running PHP.

## Environment requirements

- PHP `^8.4` (8.4 and 8.5 are tested in CI), **NTS, linux-x64 or darwin-x64/arm64** (the platforms z-engine ships definitions for).
- `ffi.enable=1` and **JIT off** (`opcache.jit=off`) — the JIT rewrites the executor
  internals the statement hook plugs into. Both must come from `php.ini`/`-d`.
- The debuggee's code must be compiled **after** the debugger initializes; the
  `bootstrap/zdebug.php` entry (via `auto_prepend_file` or an early `require`) guarantees
  this. Opcache-cached scripts compiled before the debugger are invisible.

## Where things live

- `Instrumentation/` — the three engine hooks, all extending `EngineHook`, which owns the
  latch/never-throw envelope below: `StatementHook` (`EXT_STMT`, breakpoints and
  stepping), `ThrowHook` (`THROW`, first-chance exception breakpoints), `ReturnHook`
  (`RETURN`, return breakpoints and return values). `OpArrayGate` memoizes the
  "is this op_array observed" decision per op_array address — the steady-state fast path.
- `Session/` — `DebugSession` owns the suspend loop; every DBGp command is one object in
  `Session/Handler/`. Adding a command touches three places: the `DbgpCommand` enum, a new
  handler class, and one line in `CommandDispatcherFactory::create()`. Forgetting the last
  is impossible to ship — `CommandDispatcher` throws for an enum case no handler covers.
- `Protocol/` — the wire: framing, command parsing, XML responses, and `EngineIdentity`,
  the single source for what the engine calls itself in `<init>` and in `feature_get`.
- `Context/` — stack and variable inspection, property paths, serialization, writes.
- `Config/` — layered resolution: defaults → Xdebug ini/env → `ZDEBUG_*` → explicit array.

## The one hard rule: never throw inside an engine callback

The EXT_STMT / THROW / RETURN handlers run inside FFI callbacks. A `\Throwable` that
escapes one is a **fatal engine abort** ("Throwing from FFI callbacks is not allowed"),
not a catchable error. Every handler entry point therefore:

1. checks the reentrancy latch first and bails if it is held — `HookLatch::tryEnter()`,
   released in a `finally` (the debugger's own PHP re-enters its own hooks otherwise —
   z-engine only auto-excludes `ZEngine\*` classes, not `ZDebug\*`). The latch is a
   single process-wide flag **shared by every hook**: a per-hook latch would let the
   handlers re-enter through each other (a `throw` inside the suspended statement hook
   would reach the THROW handler), and
2. wraps its whole body in `try { ... } catch (\Throwable) { ...log... }`.

Frame inspection uses only the closure-safe API: `getFunctionEntry()` (not
`getFunction()`, which throws for closures), `getLocalVariables()`, `getPrevious()`.

## Quality gates (all enforced in CI)

```bash
composer test             # unit + integration
composer test:integration # end-to-end DBGp session against a spawned child (fail-on-skip)
composer phpstan          # level max
composer cs:check         # @PER-CS2.0 (composer cs:fix to apply)
```

Protocol behavior is proven end-to-end, not mocked: `tests/Integration/` drives a real
child process with a `FakeIde` on the other end of the socket. A new command or breakpoint
type is not done until an integration test plays it against a running debuggee.

## Commit conventions

Conventional commits with scopes: `protocol`, `session`, `hook`, `context`,
`breakpoint`, `stepping`, `config`, `ci`, `docs`.
