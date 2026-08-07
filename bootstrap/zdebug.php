<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * ---------------------------------------------------------------------------
 * zdebug bootstrap. Point PHP at this file to start debugging before any
 * application code compiles (so breakpoints in that code take effect):
 *
 *     php -d ffi.enable=1 -d opcache.jit=off \
 *         -d auto_prepend_file=vendor/lisachenko/zdebug/bootstrap/zdebug.php \
 *         app.php
 *
 * Configuration is read from Xdebug's own ini/env (xdebug.mode, xdebug.client_*,
 * XDEBUG_CONFIG, XDEBUG_TRIGGER, ...) AND from the native ZDEBUG_* variables, which
 * take precedence. So an existing Xdebug setup drives zdebug unchanged; set
 * ZDEBUG_MODE=off (or xdebug.mode without `debug`) to make this a no-op.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);

(static function (): void {
    // Hard opt-out that avoids loading anything at all
    if (getenv('ZDEBUG_MODE') === 'off') {
        return;
    }

    // Locate the Composer autoloader whether zdebug is the root package or a dependency
    $candidates = [
        __DIR__ . '/../vendor/autoload.php',       // running from the zdebug checkout
        __DIR__ . '/../../../autoload.php',         // installed under someone's vendor/
    ];
    foreach ($candidates as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            break;
        }
    }

    if (!class_exists(\ZDebug\Debugger::class)) {
        return;
    }

    \ZDebug\Debugger::attach(\ZDebug\Config::fromEnvironment());
})();
