<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Read-timeout fixture: boots like entry.php but with a deliberately tiny read
 * timeout, so a test IDE that accepts the connection and then says nothing is
 * detected as gone within a second or two instead of hanging the debuggee.
 * Everything else (host, port, idekey) still comes from the ZDEBUG_* environment.
 */
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

ZDebug\Debugger::attach([ZDebug\Config\Setting::ReadTimeoutMs->value => 1000]);

require __DIR__ . '/app.php';
