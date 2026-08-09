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

use ZDebug\Context\ContextProvider;
use ZDebug\Protocol\Command;
use ZDebug\Protocol\DbgpCommand;
use ZDebug\Protocol\ErrorCode;
use ZDebug\Session\ConditionEvaluator;
use ZDebug\Session\DispatchResult;
use ZDebug\Session\SuspendedState;

/**
 * eval: evaluates an expression in a suspended frame and returns the result
 *
 * The expression arrives base64-encoded in the data part; -d selects the stack frame
 * (0, the innermost, by default). Evaluation is read-only - writing back to the frame
 * is out of scope - and can never throw: ConditionEvaluator turns every failure into
 * DBGp error 206, because this runs inside the FFI statement callback.
 */
final class Evaluate implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::Eval];

    public function __construct(
        private readonly SuspendedState $state,
        private readonly ContextReader $reader,
        private readonly ConditionEvaluator $evaluator,
        private readonly Responses $respond,
    ) {}

    public function handle(Command $command): DispatchResult
    {
        $expression = $command->data ?? '';
        if (trim($expression) === '') {
            return $this->respond->error($command, ErrorCode::InvalidExpression, 'eval requires an expression in the data part');
        }

        $depth = $command->intArgument('d', 0) ?? 0;
        $scope = $this->scopeAt($depth);
        if ($scope === null) {
            return $this->respond->error($command, ErrorCode::StackDepthInvalid, "No stack frame at depth {$depth}");
        }

        $result = $this->evaluator->evaluate($expression, $scope);
        if (!$result->ok) {
            return $this->respond->error($command, ErrorCode::EvalFailed, (string) $result->error);
        }

        $body = $this->reader->serializer()->serialize($expression, $expression, $result->value);

        return $this->respond->reply($command, ['success' => '1'], $body);
    }

    /**
     * Builds the variable scope an eval runs in, or null when the depth is out of range
     *
     * With nothing suspended (the `starting` and `stopping` states, where the IDE may still
     * probe a watch expression) there are no frames at all and the expression is evaluated
     * against an EMPTY scope rather than rejected, so constant expressions keep working.
     * Once a stack IS suspended, an out-of-range -d is a real client error and reported as
     * such - exactly like context_get.
     *
     * @return array<string, mixed>|null
     */
    private function scopeAt(int $depth): ?array
    {
        $frame = $this->state->frameAtLevel($depth);
        if ($frame !== null) {
            return $this->reader->variables($frame, ContextProvider::CONTEXT_LOCALS, $depth);
        }

        return $this->state->suspendedStack === [] ? [] : null;
    }
}
