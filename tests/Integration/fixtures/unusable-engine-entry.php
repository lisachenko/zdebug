<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Boot-resilience fixture: identical to entry.php, but meant to be run with FFI
 * disabled so arming the engine cannot possibly succeed. The debugger must swallow
 * that, register no instance, and let the application run to completion untouched.
 */
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

ZDebug\Debugger::attach(ZDebug\Config::fromEnvironment());

// Reaching this line at all is the point: attach() returned instead of throwing
echo "ATTACH RETURNED\n";
echo 'INSTANCE=' . (ZDebug\Debugger::instance() === null ? 'none' : 'registered') . "\n";

require __DIR__ . '/app.php';
