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

namespace ZDebug\Runtime;

use ZDebug\Config;
use ZEngine\EngineExtension\AbstractModule;
use ZEngine\EngineExtension\ModuleDependency;
use ZEngine\EngineExtension\ModuleInfoInterface;

/**
 * A real engine module that makes zdebug a first-class, introspectable extension
 *
 * Registering this at runtime is the same trick APCu used to stand in for APC: the
 * debugger is written in pure PHP, but it presents itself to the engine as a genuine
 * module, so `php -m`, `get_loaded_extensions()` and `phpinfo()` all report `zdebug`
 * with its status — version, mode, and where it expects the IDE — exactly as they would
 * for a compiled `zend_extension`.
 */
final class ZDebugModule extends AbstractModule implements ModuleInfoInterface
{
    public const string VERSION = '0.1.0';

    private ?Config $config = null;

    private bool $connected = false;

    public static function targetDebug(): bool
    {
        return ZEND_DEBUG_BUILD;
    }

    public static function targetPersistent(): bool
    {
        // Informational only: no cross-request state to anchor, so a temporary module is
        // enough (it deactivates at request end on worker SAPIs, re-registering next boot)
        return false;
    }

    public static function targetThreadSafe(): bool
    {
        return ZEND_THREAD_SAFE;
    }

    public static function globalType(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     *
     * @return list<ModuleDependency>
     */
    public function getModuleDependencies(): array
    {
        // zdebug drives the engine through the FFI bridge
        return [ModuleDependency::required('ffi')];
    }

    /**
     * Records the live debugger state shown in phpinfo() / the module info table
     */
    public function describe(Config $config, bool $connected): void
    {
        $this->config    = $config;
        $this->connected = $connected;
    }

    /**
     * @inheritDoc
     *
     * @return array<string, scalar>
     */
    public function getDisplayInfo(): array
    {
        $rows = [
            'zdebug support' => 'enabled',
            'Version'        => self::VERSION,
            'Protocol'       => 'DBGp (Xdebug-compatible)',
            'IDE debugger'   => 'no C extension (z-engine FFI)',
        ];

        if ($this->config !== null) {
            $rows['Mode']          = $this->config->mode;
            $rows['Client host']   = $this->config->clientHost;
            $rows['Client port']   = (string) $this->config->clientPort;
            $rows['IDE key']       = $this->config->ideKey;
            $rows['Debug session'] = $this->connected ? 'active' : 'not connected';
        }

        return $rows;
    }

    /**
     * Renders the info rows the way phpinfo()/php --ri would, for CLI diagnostics
     */
    public function printInfo(): void
    {
        $this->printInfoTable($this->getDisplayInfo());
    }
}
