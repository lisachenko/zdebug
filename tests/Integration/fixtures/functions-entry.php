<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Entry point for the call/return breakpoint debuggee: boot the debugger, THEN load
 * functions-app.php so its op_arrays are compiled with EXT_STMT. Mirrors entry.php.
 */
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

ZDebug\Debugger::attach(ZDebug\Config::fromEnvironment());

require __DIR__ . '/functions-app.php';
