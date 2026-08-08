<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Detach fixture: boots a real session (attach() returns once the IDE resumes the
 * debuggee), then tears it down again and reports what boot() left behind. The
 * application is loaded afterwards and must run as if the debugger had never attached.
 */
declare(strict_types=1);

use ZEngine\Core;
use ZEngine\System\Compiler;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$extendedStatements = static fn(): bool => (Core::$compiler->getOptions() & Compiler::COMPILE_EXTENDED_STMT) !== 0;

ZDebug\Debugger::attach(ZDebug\Config::fromEnvironment());

$debugger = ZDebug\Debugger::instance();
echo 'ARMED=' . ($debugger !== null && $extendedStatements() ? 'yes' : 'no') . "\n";

$debugger?->detach();

echo 'COMPILER RESTORED=' . ($extendedStatements() ? 'no' : 'yes') . "\n";
echo 'INSTANCE=' . (ZDebug\Debugger::instance() === null ? 'none' : 'registered') . "\n";

// Stay alive a moment so the test can tell "detach() closed the socket" apart from
// "the process exited and the OS closed it for us"
usleep(1_000_000);

require __DIR__ . '/app.php';
