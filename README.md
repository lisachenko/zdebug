<div align="center">

# 🐛 zdebug

### Step-debug PHP with PHP.

**zdebug** is an Xdebug-compatible step debugger with **no C extension**. Your IDE attaches over DBGp — Xdebug's own protocol — while pure PHP code drives the Zend VM through FFI, courtesy of [z-engine](https://github.com/lisachenko/z-engine). Set breakpoints, step through code, and inspect the stack and variables in PhpStorm or VS Code, with nothing compiled and nothing installed but Composer packages.

[![CI](https://img.shields.io/github/actions/workflow/status/lisachenko/zdebug/ci.yml?branch=main&label=CI)](https://github.com/lisachenko/zdebug/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/php-8.4%20%7C%208.5-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org/)
[![Status](https://img.shields.io/badge/status-experimental%20PoC-orange.svg)](#status--roadmap)

</div>

---

> **⚠️ Experimental proof of concept.** zdebug pokes live engine memory through z-engine. It is a research vehicle, not a production tool. Pin your PHP version, keep the JIT off, and expect rough edges.

## Why this exists

Xdebug is a compiled `zend_extension`. That is the right design — and also a barrier: you need the matching binary for your exact PHP build, a working extension toolchain, and the willingness to load native code into your interpreter. zdebug asks a different question: **how much of a step debugger can you build in pure PHP?** The answer, it turns out, is *most of it* — because z-engine already hands you the engine's internals as ordinary PHP objects. zdebug is the debugger that question produces.

## How it works

```
                          your app (compiled WITH extended-statement info)
                                     │  each statement emits an EXT_STMT opline
                                     ▼
   ┌──────────────────────────────────────────────────────────────────────┐
   │  Zend VM  ──▶  EXT_STMT opcode  ──▶  zdebug's user-opcode handler      │
   └──────────────────────────────────────────────────────────────────────┘
                                     │  (file, line) matches a breakpoint?
                                     ▼
                     the handler BLOCKS, reading DBGp commands
                     from the IDE socket — the process is suspended
                     inside the VM, exactly like a debugger should be
                                     │  run / step / stack_get / context_get
                                     ▼
                     resume → return ZEND_USER_OPCODE_DISPATCH
```

The engine is debugging itself. z-engine compiles your code with `COMPILE_EXTENDED_STMT` so every statement carries an `EXT_STMT` opline, installs a userland handler for that opcode, and hands zdebug a rich view of each suspended frame (`ExecutionData`) — stack, arguments, named locals, `$this`. zdebug turns that into a DBGp session your IDE already knows how to talk to. See z-engine's [`docs/self-debugging.md`](https://github.com/lisachenko/z-engine/blob/master/docs/self-debugging.md) for the full feasibility study this package implements.

## Quick start

```bash
composer require --dev lisachenko/zdebug:dev-main
```

Point PHP at the bootstrap so the debugger starts **before** your code compiles, and tell it where your IDE is listening:

```bash
ZDEBUG_CLIENT_PORT=9003 \
php -d ffi.enable=1 -d opcache.jit=off \
    -d auto_prepend_file=vendor/lisachenko/zdebug/bootstrap/zdebug.php \
    app.php
```

In **PhpStorm**: *Run → Start Listening for PHP Debug Connections*, set a breakpoint, run the command above. In **VS Code**: install the *PHP Debug* extension, add a "Listen for Xdebug" configuration, start listening.

Prefer wiring it up in code? Drop the `auto_prepend_file` and call it yourself, first thing:

```php
require __DIR__ . '/vendor/autoload.php';

ZDebug\Debugger::attach([
    'client_host' => '127.0.0.1',
    'client_port' => 9003,
    'path_filter' => [__DIR__ . '/src'], // only instrument your code
]);

require __DIR__ . '/app.php';            // compiled after attach → debuggable
```

## Drop-in Xdebug compatibility

zdebug reads **Xdebug's own configuration** — so if you already have an Xdebug setup, it just works. Both the `xdebug.*` ini directives and the `XDEBUG_*` environment are honored, with Xdebug 3 semantics:

```bash
# An existing Xdebug 3 configuration drives zdebug unchanged:
php -d ffi.enable=1 -d opcache.jit=off \
    -d xdebug.mode=debug -d xdebug.client_host=127.0.0.1 -d xdebug.client_port=9003 \
    -d auto_prepend_file=vendor/lisachenko/zdebug/bootstrap/zdebug.php \
    app.php

# start_with_request=trigger → only debug when the trigger is present, like Xdebug:
XDEBUG_TRIGGER=1 php ... app.php
```

Recognized Xdebug settings: `xdebug.mode` / `XDEBUG_MODE` (step debugging needs `debug`), `xdebug.client_host`, `xdebug.client_port`, `xdebug.idekey`, `xdebug.start_with_request` (`yes` / `no` / `trigger` / `default`), `xdebug.trigger_value`, `xdebug.log`, and the `XDEBUG_CONFIG`, `XDEBUG_SESSION`, `XDEBUG_TRIGGER` environment variables.

## It shows up like a real extension

zdebug registers itself as a genuine engine module at runtime — the same technique APCu used to stand in for APC. Even though there's no compiled extension, the standard tooling reports it:

```php
extension_loaded('zdebug');          // true
in_array('zdebug', get_loaded_extensions(), true); // true
```

```
zdebug support  => enabled
Version         => 0.1.0
Protocol        => DBGp (Xdebug-compatible)
IDE debugger    => no C extension (z-engine FFI)
Mode            => debug
Client host     => 127.0.0.1
Client port     => 9003
Debug session   => active
```

## Configuration

zdebug's **native** settings (these take precedence over any Xdebug settings above):

| Variable | Default | Meaning |
|---|---|---|
| `ZDEBUG_MODE` | `debug` | `off` makes the bootstrap a no-op |
| `ZDEBUG_CLIENT_HOST` | `127.0.0.1` | Host your IDE listens on |
| `ZDEBUG_CLIENT_PORT` | `9003` | Port your IDE listens on |
| `ZDEBUG_IDEKEY` | `zdebug` | Session key shown to the IDE |
| `ZDEBUG_PATH_FILTER` | *(all)* | `:`-separated path prefixes to instrument — scope this to your code for speed |
| `ZDEBUG_CONNECT_TIMEOUT_MS` | `200` | If the IDE is not listening, the app runs undebugged |
| `ZDEBUG_READ_TIMEOUT_MS` | `300000` | How long a suspended script waits for the next IDE command before deciding the IDE is gone and running on undebugged. `0` waits forever, as Xdebug does |
| `ZDEBUG_LOG` | *(none)* | Path to an optional diagnostics log |

Precedence, lowest to highest: built-in defaults → Xdebug ini/env → `ZDEBUG_*` → an explicit array passed to `Debugger::attach([...])`.

## What works vs. Xdebug

| Feature | zdebug | Notes |
|---|---|---|
| Line breakpoints | ✅ | On any code compiled after attach |
| Conditional breakpoints | ✅ | Condition evaluated in the frame; hit counts via `-h` / `-o` (`>=`, `==`, `%`) |
| Step over / into / out | 🚧 | Depth machine in place; wired up in M2 |
| Stack traces | ✅ | Full `getPrevious()` walk, with call sites |
| Locals, args, `$this` | ✅ | Named, from CV slots — no symbol table needed |
| Superglobals | ✅ | From the engine global symbol table |
| Variable inspection (`property_get`) | ✅ | Expand any node by its `fullname` (`$e->previous->message`, `$rows[3]['id']`), `-p` paging, `-m` / `property_value` for untruncated data |
| `eval` in frame | ✅ | Read-only: evaluated against the locals of the frame selected by `-d` |
| Exception breakpoints | 🚧 | First-chance via the `THROW` opcode — M3 |
| Attach to already-running code | ❌ | Only code compiled after attach is steppable |
| Opcache-cached scripts | ❌ | Invisible unless the cache is cold |
| Profiling / tracing / coverage | ❌ | The engine's observer API can't be enabled from userland ([z-engine #106](https://github.com/lisachenko/z-engine/pull/106)) |

**Performance:** every statement in an instrumented file crosses an FFI trampoline. Scope `ZDEBUG_PATH_FILTER` to the code you actually want to step through and leave the rest at full speed.

## Requirements

- PHP **8.4 or 8.5** (supported in parallel), **NTS**, **linux-x64 or macOS x64/arm64** (platforms z-engine ships definitions for); Composer resolves the matching z-engine line per minor
- `ffi.enable=1` and **`opcache.jit=off`** (the JIT rewrites the executor internals the hook plugs into)
- Your app's code must load **after** zdebug attaches — `auto_prepend_file` guarantees this

## Status & roadmap

zdebug is at **M1**: a working vertical slice — your IDE attaches, hits a line breakpoint, and inspects the stack and locals, proven by an end-to-end test that plays the IDE against a real child process.

- **M2** — step over/into/out, deeper variable inspection with paging, `source`, `detach`
- **M3** — first-chance exception breakpoints, async "pause", per-file instrumentation gating for near-zero overhead outside the filter (conditional breakpoints, hit counts and `eval` have landed)

## Credits

- [Derick Rethans](https://derickrethans.nl/) and the Xdebug project for the **DBGp** protocol every PHP IDE speaks.
- [z-engine](https://github.com/lisachenko/z-engine) — the FFI bridge to the Zend Engine that makes all of this possible — and its [`docs/self-debugging.md`](https://github.com/lisachenko/z-engine/blob/master/docs/self-debugging.md) research.

## License

MIT — see [LICENSE](LICENSE).
