<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Integration entry point: boot the debugger, THEN load the debuggee. Because app.php
 * is required after Debugger::attach(), its op_arrays are compiled with EXT_STMT and
 * are steppable. Mirrors the auto_prepend_file bootstrap flow.
 */
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

ZDebug\Debugger::attach(ZDebug\Config::fromEnvironment());

require __DIR__ . '/app.php';
