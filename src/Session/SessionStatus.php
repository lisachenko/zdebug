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

namespace ZDebug\Session;

/**
 * DBGp session status, reported in every continuation response
 *
 * @see https://xdebug.org/docs/dbgp#status
 */
enum SessionStatus: string
{
    case Starting = 'starting';
    case Running  = 'running';
    case Break    = 'break';
    case Stopping = 'stopping';
    case Stopped  = 'stopped';
}
