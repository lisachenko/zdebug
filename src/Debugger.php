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
use ZDebug\Instrumentation\ThrowHook;
use ZDebug\Protocol\DbgpConnection;
use ZDebug\Protocol\FileUri;
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Runtime\ZDebugModule;
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

    private ?ZDebugModule $module = null;

    private bool $attached = false;

    private function __construct(
        private readonly Config $config,
        private readonly Log $log,
        private readonly BreakpointRegistry $breakpoints,
        private readonly StepController $stepper,
        private readonly StackCollector $stackCollector,
        private readonly StatementHook $statementHook,
        private readonly ThrowHook $throwHook,
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
            is_array($config)         => (new Config\ConfigResolver())->resolve(self::mapArrayKeys($config)),
            default                   => Config::fromEnvironment(),
        };

        $log         = new Log($config->logFile);
        $filter      = new FileFilter($config->pathFilter);
        $gate        = new OpArrayGate($filter);
        $breakpoints = new BreakpointRegistry();
        $stepper     = new StepController();
        $collector   = new StackCollector($gate);
        $hook        = new StatementHook($gate, $breakpoints, $stepper, $log);
        $throwHook   = new ThrowHook($breakpoints, $log);

        $debugger       = new self($config, $log, $breakpoints, $stepper, $collector, $hook, $throwHook);
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
     * Returns the registered runtime module, or null when the debugger is not armed
     */
    public function module(): ?ZDebugModule
    {
        return $this->module;
    }

    /**
     * Tears down the hooks and session (primarily for tests)
     */
    public function detach(): void
    {
        // LIFO, mirroring the installation order in boot()
        $this->throwHook->uninstall();
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

        // Present zdebug to the engine as a real module, so `php -m`, phpinfo() and
        // get_loaded_extensions() report it like a compiled extension (APCu-for-APC style)
        $this->registerModule();

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
        // Exception breakpoints ride the THROW opcode: the only window where a PHP callback
        // may look at an exception, since ext-ffi aborts once EG(exception) is set
        $this->throwHook->install(fn(): ?DebugSession => $this->session);
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

        $this->module?->describe($this->config, true);
        $this->openSession($connection);
    }

    /**
     * Registers the runtime module (best-effort: a failure must never break the app)
     */
    private function registerModule(): void
    {
        try {
            $module = new ZDebugModule('zdebug');
            if (!$module->isModuleRegistered()) {
                $module->register();
                $module->startup();
            }
            $module->describe($this->config, false);
            $this->module = $module;
        } catch (\Throwable $error) {
            $this->log->exception($error);
        }
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
     * Normalizes an explicit config array into the Settings key space
     *
     * Accepts the documented public keys (client_host, client_port, idekey, path_filter,
     * connect_timeout_ms, mode, log) and passes them through unchanged; unknown keys are
     * dropped so a typo cannot silently create a phantom setting.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function mapArrayKeys(array $config): array
    {
        $allowed = [
            Config\Settings::CLIENT_HOST,
            Config\Settings::CLIENT_PORT,
            Config\Settings::IDE_KEY,
            Config\Settings::PATH_FILTER,
            Config\Settings::CONNECT_TIMEOUT_MS,
            Config\Settings::MODE,
            Config\Settings::LOG,
        ];

        return array_intersect_key($config, array_flip($allowed));
    }
}
