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
use ZDebug\Context\SourceReader;
use ZDebug\Context\StackCollector;
use ZDebug\Instrumentation\FileFilter;
use ZDebug\Instrumentation\OpArrayGate;
use ZDebug\Instrumentation\ReturnHook;
use ZDebug\Instrumentation\StatementHook;
use ZDebug\Instrumentation\ThrowHook;
use ZDebug\Protocol\DbgpConnection;
use ZDebug\Protocol\FileUri;
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Runtime\ZDebugModule;
use ZDebug\Session\CommandDispatcherFactory;
use ZDebug\Session\ConditionEvaluator;
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
 *
 * Every step of that is best-effort. attach() runs from auto_prepend_file, ahead of the
 * host application, and a debugger that cannot boot must cost the app its debugger and
 * nothing else - so a failed boot rolls itself back, logs, and leaves no instance behind.
 */
final class Debugger
{
    private static ?self $instance = null;

    private ?DebugSession $session = null;

    private ?ZDebugModule $module = null;

    private bool $attached = false;

    /** Compiler options as boot() found them, kept so detach() can put them back */
    private ?int $savedCompilerOptions = null;

    private function __construct(
        private readonly Config $config,
        private readonly Log $log,
        private readonly BreakpointRegistry $breakpoints,
        private readonly StepController $stepper,
        private readonly StackCollector $stackCollector,
        private readonly StatementHook $statementHook,
        private readonly ContextProvider $context,
        private readonly ThrowHook $throwHook,
        private readonly ReturnHook $returnHook,
        private readonly SourceReader $sourceReader,
    ) {}

    /**
     * Attaches the debugger (idempotent). Safe to call from an auto_prepend bootstrap.
     *
     * Always returns a Debugger, but only a successfully booted one becomes the process
     * instance(): if arming the engine failed, the returned object is inert and the host
     * application simply runs undebugged.
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
        $context     = new ContextProvider();
        $hook        = new StatementHook($gate, $breakpoints, $stepper, $log, $context, new ConditionEvaluator());
        $throwHook   = new ThrowHook($breakpoints, $log);
        $returnHook  = new ReturnHook($gate, $breakpoints, $log);

        $debugger = new self(
            $config,
            $log,
            $breakpoints,
            $stepper,
            $collector,
            $hook,
            $context,
            $throwHook,
            $returnHook,
            new SourceReader($filter),
        );

        if ($config->isEnabled() && !$debugger->boot()) {
            // Booting failed and rolled itself back: publish nothing, stay out of the way
            return $debugger;
        }
        self::$instance = $debugger;

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
     * Undoes boot(), in reverse: uninstalls both engine hooks, puts the compiler options
     * back the way boot() found them, and closes the IDE connection
     *
     * Idempotent, and used both by tests and to roll back a boot that threw halfway. Code
     * already compiled with EXT_STMT oplines keeps them - restoring the options only stops
     * further op_arrays from being instrumented - but with the hooks gone those oplines
     * dispatch straight through the default handler.
     */
    public function detach(): void
    {
        // LIFO, mirroring the installation order in boot()
        $this->returnHook->uninstall();
        $this->throwHook->uninstall();
        $this->statementHook->uninstall();
        $this->restoreCompilerOptions();
        $this->session?->close();
        $this->session  = null;
        $this->attached = false;
        self::$instance = null;
    }

    /**
     * Arms the engine and opens the session, reporting whether it fully succeeded
     *
     * Best-effort in the same way registerModule() is, and for a stronger reason: this
     * runs before the host application's first statement, so a throwable escaping here
     * would take down the very app the debugger exists to observe. Anything the failed
     * attempt installed is rolled back before returning false.
     */
    private function boot(): bool
    {
        if ($this->attached) {
            return true;
        }
        try {
            $this->arm();

            return true;
        } catch (\Throwable $error) {
            $this->log->exception($error);
        }
        try {
            $this->detach();
        } catch (\Throwable $error) {
            $this->log->exception($error);
        }

        return false;
    }

    /**
     * The boot body proper; every throw here is caught by boot()
     */
    private function arm(): void
    {
        if (!Core::isInitialized()) {
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

        $compiler                   = Core::$compiler;
        $this->savedCompilerOptions = $compiler->getOptions();
        $compiler->setOptions($this->savedCompilerOptions | Compiler::COMPILE_EXTENDED_STMT);

        $this->statementHook->install(fn(): ?DebugSession => $this->session);
        // Exception breakpoints ride the THROW opcode: the only window where a PHP callback
        // may look at an exception, since ext-ffi aborts once EG(exception) is set
        $this->throwHook->install(fn(): ?DebugSession => $this->session);
        // Return breakpoints ride the RETURN opcode, the last instruction of the frame that
        // is leaving - its locals and its line are still inspectable there
        $this->returnHook->install(fn(): ?DebugSession => $this->session);
        $this->attached = true;

        $connection = DbgpConnection::connect(
            $this->config->clientHost,
            $this->config->clientPort,
            $this->config->connectTimeoutMs,
            $this->config->readTimeoutMs,
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
     * Puts the compiler options back as boot() found them, if it got that far
     */
    private function restoreCompilerOptions(): void
    {
        if ($this->savedCompilerOptions === null || !Core::isInitialized()) {
            return;
        }
        Core::$compiler->setOptions($this->savedCompilerOptions);
        $this->savedCompilerOptions = null;
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
        $xml             = new ResponseBuilder();
        $features        = new Features($languageVersion);
        $this->session   = new DebugSession(
            $connection,
            $xml,
            $features,
            $this->breakpoints,
            $this->context,
            $this->stackCollector,
            $this->stepper,
            $this->log,
            // The `source` command reads through the very filter the instrumentation uses,
            // so the debugger never serves a file it would not have stepped through
            new CommandDispatcherFactory(
                $features,
                $this->breakpoints,
                $this->context,
                $xml,
                new ConditionEvaluator(),
                $this->sourceReader,
            ),
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
        $allowed = array_column(Config\Setting::cases(), 'value');

        return array_intersect_key($config, array_flip($allowed));
    }
}
