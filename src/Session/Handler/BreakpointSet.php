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

use ZDebug\Breakpoint\Breakpoint;
use ZDebug\Breakpoint\BreakpointRegistry;
use ZDebug\Breakpoint\BreakpointType;
use ZDebug\Protocol\Command;
use ZDebug\Protocol\DbgpCommand;
use ZDebug\Protocol\ErrorCode;
use ZDebug\Protocol\FileUri;
use ZDebug\Session\DispatchResult;

/**
 * breakpoint_set: registers a breakpoint of any supported type
 *
 * -t selects the type (validated against BreakpointType, the same enum Features
 * advertises in breakpoint_types); the shape of the remaining arguments then depends on
 * it: a line/conditional breakpoint needs -f/-n, an exception one takes -x, a
 * call/return one requires -m. Hit bookkeeping (-h/-o) applies to every type.
 */
final class BreakpointSet implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::BreakpointSet];

    public function __construct(
        private readonly BreakpointRegistry $breakpoints,
        private readonly Responses $respond,
    ) {}

    public function handle(Command $command): DispatchResult
    {
        $rawType = (string) $command->argument('t', BreakpointType::Line->value);
        $type    = BreakpointType::tryFrom($rawType);
        if ($type === null) {
            return $this->respond->error($command, ErrorCode::BreakpointTypeUnsupported, "Unsupported breakpoint type '{$rawType}'");
        }

        $enabled = ($command->argument('s', 'enabled')) !== 'disabled';

        // -h / -o: break only on the n-th (or every n-th) hit
        $hitValue     = max(0, $command->intArgument('h', 0) ?? 0);
        $hitCondition = (string) $command->argument('o', Breakpoint::HIT_GREATER_OR_EQUAL);
        if (!in_array($hitCondition, Breakpoint::HIT_CONDITIONS, true)) {
            return $this->respond->error($command, ErrorCode::BreakpointInvalid, "Unsupported hit condition '{$hitCondition}'");
        }

        $id = $this->breakpoints->nextId();

        if ($type === BreakpointType::Call || $type === BreakpointType::Return) {
            $functionName = trim((string) $command->argument('m', ''));
            if ($functionName === '') {
                return $this->respond->error($command, ErrorCode::BreakpointInvalid, "A {$type->value} breakpoint requires a function name in -m");
            }
            $this->breakpoints->add(new Breakpoint(
                id: $id,
                type: $type,
                enabled: $enabled,
                functionName: $functionName,
                temporary: $command->argument('r') === '1',
                hitValue: $hitValue,
                hitCondition: $hitCondition,
            ));

            return $this->acknowledge($command, $id, $enabled);
        }

        if ($type === BreakpointType::Exception) {
            $this->breakpoints->add(new Breakpoint(
                id: $id,
                type: BreakpointType::Exception,
                enabled: $enabled,
                exceptionName: $command->argument('x'),
                hitValue: $hitValue,
                hitCondition: $hitCondition,
            ));

            return $this->acknowledge($command, $id, $enabled);
        }

        $fileUri = $command->argument('f');
        $line    = $command->intArgument('n');
        if ($fileUri === null || $line === null) {
            return $this->respond->error($command, ErrorCode::BreakpointInvalid, 'Line breakpoint requires -f and -n');
        }

        $condition = $command->data; // conditional breakpoints carry the expression in the data part
        $this->breakpoints->add(new Breakpoint(
            id: $id,
            type: $condition !== null && $condition !== '' ? BreakpointType::Conditional : BreakpointType::Line,
            enabled: $enabled,
            file: FileUri::toPath($fileUri),
            line: $line,
            condition: $condition !== '' ? $condition : null,
            temporary: $command->argument('r') === '1',
            hitValue: $hitValue,
            hitCondition: $hitCondition,
        ));

        return $this->acknowledge($command, $id, $enabled);
    }

    private function acknowledge(Command $command, int $id, bool $enabled): DispatchResult
    {
        return $this->respond->reply($command, [
            'id'       => (string) $id,
            'state'    => $enabled ? 'enabled' : 'disabled',
            'resolved' => 'resolved',
        ]);
    }
}
