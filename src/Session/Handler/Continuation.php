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

namespace ZDebug\Session\Handler;

use ZDebug\Protocol\Command;
use ZDebug\Protocol\DbgpCommand;
use ZDebug\Session\DispatchResult;
use ZDebug\Stepping\ResumeMode;

/**
 * run / step_into / step_over / step_out: unblock the debuggee, answer at the next break
 *
 * The four differ only in the ResumeMode the command loop records before letting the
 * engine continue - the DispatchResult carries no response, because a continuation is
 * answered by the break (or session end) that eventually follows it.
 */
final class Continuation implements CommandHandler
{
    private const array MODES = [
        'run'       => ResumeMode::Run,
        'step_into' => ResumeMode::StepInto,
        'step_over' => ResumeMode::StepOver,
        'step_out'  => ResumeMode::StepOut,
    ];

    public private(set) array $commands = [DbgpCommand::Run, DbgpCommand::StepInto, DbgpCommand::StepOver, DbgpCommand::StepOut];

    public function handle(Command $command): DispatchResult
    {
        return DispatchResult::continuation(self::MODES[$command->name]);
    }
}
