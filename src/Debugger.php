<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */
declare(strict_types=1);

namespace ZDebug;

use ZDebug\Breakpoint\BreakpointRegistry;
use ZDebug\Context\ContextProvider;
use ZDebug\Context\StackCollector;
use ZDebug\Instrumentation\FileFilter;
use ZDebug\Instrumentation\OpArrayGate;
use ZDebug\Instrumentation\StatementHook;
use ZDebug\Protocol\DbgpConnection;
use ZDebug\Protocol\FileUri;
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Session\DebugSession;
use ZDebug\Session\Features;
use ZDebug\Stepping\StepController;
use ZEngine\Core;
use ZEngine\System\Compiler;

/**
 * The debugger facade and lifecycle owner
 *
 * Engine hooks are process-global, so there is one Debugger per process. attach() wires
 * the statement hook, enables extended-statement compilation, and (if the IDE is
 * reachable) opens the DBGp session and blocks in the "starting" state until the first
 * run - so breakpoints set before the debuggee compiles take effect. If the IDE is not
 * listening, hooks stay in place but no session is created and the app runs at the
 * fast-path cost.
 */
final class Debugger
{
    private static ?self $instance = null;

    private ?DebugSession $session = null;

    private bool $attached = false;

    private function __construct(
        private readonly Config $config,
        private readonly Log $log,
        private readonly BreakpointRegistry $breakpoints,
        private readonly StepController $stepper,
        private readonly StackCollector $stackCollector,
        private readonly StatementHook $statementHook,
    ) {}

    /**
     * Attaches the debugger (idempotent). Safe to call from an auto_prepend bootstrap.
     *
     * @param Config|array<string, mixed>|null $config
     */
    public static function attach(Config|array|null $config = null): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = match (true) {
            $config instanceof Config => $config,
            is_array($config)         => self::configFromArray($config),
            default                   => Config::fromEnvironment(),
        };

        $log         = new Log($config->logFile);
        $filter      = new FileFilter($config->pathFilter);
        $gate        = new OpArrayGate($filter);
        $breakpoints = new BreakpointRegistry();
        $stepper     = new StepController();
        $collector   = new StackCollector($gate);
        $hook        = new StatementHook($gate, $breakpoints, $stepper, $log);

        $debugger       = new self($config, $log, $breakpoints, $stepper, $collector, $hook);
        self::$instance = $debugger;

        if ($config->isEnabled()) {
            $debugger->boot();
        }

        return $debugger;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    /**
     * Tears down the hooks and session (primarily for tests)
     */
    public function detach(): void
    {
        $this->statementHook->uninstall();
        $this->session  = null;
        $this->attached = false;
        self::$instance = null;
    }

    private function boot(): void
    {
        if ($this->attached) {
            return;
        }
        // isset() is false for the uninitialized typed static before Core::init()
        if (!isset(Core::$compiler)) {
            Core::init();
        }

        // Compile EVERY zdebug class BEFORE enabling extended-statement compilation, so
        // none of the debugger's own op_arrays carry EXT_STMT oplines. This is mandatory,
        // not an optimization: an instrumented op_array keeps dispatching through the user
        // opcode-handler table for its whole life, and the debug path runs during request
        // shutdown - after the engine has already torn the handler table down - where a
        // stale EXT_STMT dispatch would jump through a NULL handler and crash. (Mirrors
        // z-engine's own preloadFrameworkClasses.)
        $this->preloadPackageClasses();

        $compiler = Core::$compiler;
        $compiler->setOptions($compiler->getOptions() | Compiler::COMPILE_EXTENDED_STMT);

        $this->statementHook->install(fn(): ?DebugSession => $this->session);
        $this->attached = true;

        $connection = DbgpConnection::connect(
            $this->config->clientHost,
            $this->config->clientPort,
            $this->config->connectTimeoutMs,
        );
        if ($connection === null) {
            $this->log->debug(sprintf(
                'IDE not listening at %s:%d; running undebugged',
                $this->config->clientHost,
                $this->config->clientPort,
            ));

            return;
        }

        $this->openSession($connection);
    }

    private function openSession(DbgpConnection $connection): void
    {
        $languageVersion = PHP_VERSION;
        $this->session   = new DebugSession(
            $connection,
            new ResponseBuilder(),
            new Features($languageVersion),
            $this->breakpoints,
            new ContextProvider(),
            $this->stackCollector,
            $this->stepper,
            $this->log,
        );

        register_shutdown_function(function (): void {
            $this->session?->onScriptEnd();
        });

        $fileUri = FileUri::fromPath($this->entryScript());
        $this->session->start($fileUri, $this->config->ideKey, $languageVersion);
    }

    private function entryScript(): string
    {
        $main = $_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
        $real = is_string($main) && $main !== '' ? realpath($main) : false;

        return $real !== false ? $real : (is_string($main) ? $main : 'php://stdin');
    }

    /**
     * Requires every PHP file under the package src/ tree so all zdebug op_arrays are
     * compiled before extended-statement compilation is switched on
     */
    private function preloadPackageClasses(): void
    {
        $directory = new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS);
        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator($directory);
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                require_once $file->getPathname();
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function configFromArray(array $config): Config
    {
        $base       = Config::fromEnvironment();
        $pathFilter = $base->pathFilter;
        if (isset($config['path_filter']) && is_array($config['path_filter'])) {
            $pathFilter = [];
            foreach ($config['path_filter'] as $prefix) {
                if (is_string($prefix)) {
                    $pathFilter[] = $prefix;
                }
            }
        }

        return new Config(
            clientHost: self::stringOption($config, 'client_host', $base->clientHost),
            clientPort: self::intOption($config, 'client_port', $base->clientPort),
            ideKey: self::stringOption($config, 'idekey', $base->ideKey),
            pathFilter: $pathFilter,
            connectTimeoutMs: self::intOption($config, 'connect_timeout_ms', $base->connectTimeoutMs),
            mode: self::stringOption($config, 'mode', $base->mode),
            logFile: isset($config['log']) && is_string($config['log']) ? $config['log'] : $base->logFile,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function stringOption(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function intOption(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
