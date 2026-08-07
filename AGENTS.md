# Contributor notes for zdebug

zdebug is a pure-PHP step debugger: your IDE attaches over DBGp (Xdebug's protocol)
while PHP code drives the Zend VM through [z-engine](https://github.com/lisachenko/z-engine)
FFI hooks. There is no C extension. Read z-engine's `docs/self-debugging.md` first — it
is the design ground truth this package implements.

## Branch model

`8.4-dev` targets PHP 8.4 (unstable dev line). Engine memory layouts change between PHP
minors, so each PHP minor gets its own branch, mirroring z-engine.

## Environment requirements

- PHP `^8.4` (8.4 and 8.5 are tested in CI), **NTS, linux-x64** (the only platform z-engine ships definitions for).
- `ffi.enable=1` and **JIT off** (`opcache.jit=off`) — the JIT rewrites the executor
  internals the statement hook plugs into. Both must come from `php.ini`/`-d`.
- The debuggee's code must be compiled **after** the debugger initializes; the
  `bootstrap/zdebug.php` entry (via `auto_prepend_file` or an early `require`) guarantees
  this. Opcache-cached scripts compiled before the debugger are invisible.

## The one hard rule: never throw inside an engine callback

The EXT_STMT / THROW / interrupt handlers run inside FFI callbacks. A `\Throwable` that
escapes one is a **fatal engine abort** ("Throwing from FFI callbacks is not allowed"),
not a catchable error. Every handler entry point therefore:

1. checks the `static bool $inDebugger` reentrancy latch first and bails if set (the
   debugger's own PHP re-enters its own hook otherwise — z-engine only auto-excludes
   `ZEngine\*` classes, not `ZDebug\*`), and
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

## Commit conventions

Conventional commits with scopes: `protocol`, `session`, `hook`, `context`,
`breakpoint`, `stepping`, `ci`, `docs`.
