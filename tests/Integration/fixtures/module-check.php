<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Attaches the debugger with no IDE listening, then reports whether zdebug has
 * registered itself as a runtime engine module. Used by ModuleRegistrationTest.
 */
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A closed port so the debugger arms (registers the module) but degrades to undebugged.
// Explicit mode: a host Xdebug config (e.g. xdebug.mode=off on CI images) must not
// switch the debugger off through the compat fallback.
ZDebug\Debugger::attach(['client_port' => 1, 'connect_timeout_ms' => 50, 'mode' => 'debug']);

echo 'extension_loaded=' . (extension_loaded('zdebug') ? 'yes' : 'no') . "\n";
echo 'in_list=' . (in_array('zdebug', get_loaded_extensions(), true) ? 'yes' : 'no') . "\n";

$module = ZDebug\Debugger::instance()?->module();
$info   = $module !== null ? $module->getDisplayInfo() : [];
echo 'version=' . ($info['Version'] ?? '?') . "\n";
echo 'protocol=' . ($info['Protocol'] ?? '?') . "\n";
echo "MODULE CHECK DONE\n";
