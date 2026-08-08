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

namespace ZDebug\Config;

/**
 * The debugger settings a configuration source may contribute
 *
 * The backing values double as the documented public keys of the explicit config
 * array accepted by Debugger::attach(), and as the storage keys inside Settings.
 */
enum Setting: string
{
    case ClientHost       = 'client_host';
    case ClientPort       = 'client_port';
    case IdeKey           = 'idekey';
    case Mode             = 'mode';
    case PathFilter       = 'path_filter';
    case ConnectTimeoutMs = 'connect_timeout_ms';
    case Log              = 'log';
}
