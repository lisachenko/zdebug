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

use ZDebug\Breakpoint\Breakpoint;
use ZDebug\Breakpoint\BreakpointRegistry;
use ZDebug\Context\ContextProvider;
use ZDebug\Context\PropertySerializer;
use ZDebug\Protocol\Command;
use ZDebug\Protocol\ErrorCode;
use ZDebug\Protocol\FileUri;
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Stepping\ResumeMode;

/**
 * Maps DBGp commands to debugger actions
 *
 * Every handler returns a DispatchResult; unknown commands answer error 4 and a handler
 * that throws is turned into error 998 - the loop above never sees an exception. The
 * dispatcher reads suspended state (stack, features) from the DebugSession it serves.
 */
final class CommandDispatcher
{
    public function __construct(
        private readonly DebugSession $session,
        private readonly Features $features,
        private readonly BreakpointRegistry $breakpoints,
        private readonly ContextProvider $context,
        private readonly ResponseBuilder $xml,
        private readonly ConditionEvaluator $evaluator,
    ) {}

    public function dispatch(Command $command): DispatchResult
    {
        try {
            return $this->handle($command);
        } catch (\Throwable $error) {
            return DispatchResult::reply(
                $this->xml->error($command->name, $command->transactionId, ErrorCode::INTERNAL_ERROR, $error->getMessage()),
            );
        }
    }

    private function handle(Command $command): DispatchResult
    {
        return match ($command->name) {
            'status'            => $this->status($command),
            'feature_get'       => $this->featureGet($command),
            'feature_set'       => $this->featureSet($command),
            'breakpoint_set'    => $this->breakpointSet($command),
            'breakpoint_remove' => $this->breakpointRemove($command),
            'breakpoint_list'   => $this->breakpointList($command),
            'stack_get'         => $this->stackGet($command),
            'context_names'     => $this->contextNames($command),
            'context_get'       => $this->contextGet($command),
            'eval'              => $this->evaluate($command),
            'run'               => DispatchResult::continuation(ResumeMode::Run),
            'step_into'         => DispatchResult::continuation(ResumeMode::StepInto),
            'step_over'         => DispatchResult::continuation(ResumeMode::StepOver),
            'step_out'          => DispatchResult::continuation(ResumeMode::StepOut),
            'stop'              => $this->stop($command),
            'detach'            => $this->detach($command),
            'stdout', 'stderr'  => $this->reply($command, ['success' => '0']),
            default             => $this->unimplemented($command),
        };
    }

    private function status(Command $command): DispatchResult
    {
        return $this->reply($command, [
            'status' => $this->session->status()->value,
            'reason' => 'ok',
        ]);
    }

    private function featureGet(Command $command): DispatchResult
    {
        $name      = (string) $command->argument('n', '');
        $supported = $this->features->supports($name);
        $value     = $this->features->get($name) ?? ($this->isCommandName($name) ? '1' : '0');

        return DispatchResult::reply($this->xml->response($command->name, $command->transactionId, [
            'feature_name' => $name,
            'supported'    => $supported || $this->isCommandName($name) ? '1' : '0',
        ], ResponseBuilder::escape($value)));
    }

    private function featureSet(Command $command): DispatchResult
    {
        $name    = (string) $command->argument('n', '');
        $value   = (string) $command->argument('v', '');
        $success = $this->features->set($name, $value);

        return $this->reply($command, [
            'feature' => $name,
            'success' => $success ? '1' : '0',
        ]);
    }

    private function breakpointSet(Command $command): DispatchResult
    {
        $type = (string) $command->argument('t', Breakpoint::TYPE_LINE);
        if ($type !== Breakpoint::TYPE_LINE && $type !== Breakpoint::TYPE_CONDITION && $type !== Breakpoint::TYPE_EXCEPTION) {
            return $this->error($command, ErrorCode::BREAKPOINT_TYPE_UNSUPPORTED, "Unsupported breakpoint type '{$type}'");
        }

        $enabled = ($command->argument('s', 'enabled')) !== 'disabled';
        $id      = $this->breakpoints->nextId();

        if ($type === Breakpoint::TYPE_EXCEPTION) {
            $this->breakpoints->add(new Breakpoint(
                id: $id,
                type: Breakpoint::TYPE_EXCEPTION,
                enabled: $enabled,
                exceptionName: $command->argument('x'),
            ));

            return $this->breakpointAck($command, $id, $enabled);
        }

        $fileUri = $command->argument('f');
        $line    = $command->intArgument('n');
        if ($fileUri === null || $line === null) {
            return $this->error($command, ErrorCode::BREAKPOINT_INVALID, 'Line breakpoint requires -f and -n');
        }

        $condition = $command->data; // conditional breakpoints carry the expression in the data part
        $this->breakpoints->add(new Breakpoint(
            id: $id,
            type: $condition !== null && $condition !== '' ? Breakpoint::TYPE_CONDITION : Breakpoint::TYPE_LINE,
            enabled: $enabled,
            file: FileUri::toPath($fileUri),
            line: $line,
            condition: $condition !== '' ? $condition : null,
            temporary: $command->argument('r') === '1',
        ));

        return $this->breakpointAck($command, $id, $enabled);
    }

    private function breakpointAck(Command $command, int $id, bool $enabled): DispatchResult
    {
        return $this->reply($command, [
            'id'       => (string) $id,
            'state'    => $enabled ? 'enabled' : 'disabled',
            'resolved' => 'resolved',
        ]);
    }

    private function breakpointRemove(Command $command): DispatchResult
    {
        $id = $command->intArgument('d');
        if ($id === null || !$this->breakpoints->remove($id)) {
            return $this->error($command, ErrorCode::BREAKPOINT_DOES_NOT_EXIST, 'No such breakpoint');
        }

        return $this->reply($command, []);
    }

    private function breakpointList(Command $command): DispatchResult
    {
        $body = '';
        foreach ($this->breakpoints->all() as $breakpoint) {
            $attributes = [
                'id'       => (string) $breakpoint->id,
                'type'     => $breakpoint->type,
                'state'    => $breakpoint->state(),
                'resolved' => 'resolved',
            ];
            if ($breakpoint->file !== null) {
                $attributes['filename'] = FileUri::fromPath($breakpoint->file);
            }
            if ($breakpoint->line !== null) {
                $attributes['lineno'] = (string) $breakpoint->line;
            }
            if ($breakpoint->exceptionName !== null) {
                $attributes['exception'] = $breakpoint->exceptionName;
            }
            $body .= '<breakpoint ' . ResponseBuilder::attributes($attributes) . '/>';
        }

        return DispatchResult::reply($this->xml->response($command->name, $command->transactionId, [], $body));
    }

    private function stackGet(Command $command): DispatchResult
    {
        $requested = $command->intArgument('d');
        $body      = '';
        foreach ($this->session->suspendedStack() as $frame) {
            if ($requested !== null && $frame->level !== $requested) {
                continue;
            }
            $body .= '<stack ' . ResponseBuilder::attributes([
                'where'    => $frame->where,
                'level'    => (string) $frame->level,
                'type'     => 'file',
                'filename' => FileUri::fromPath($frame->file),
                'lineno'   => (string) $frame->line,
            ]) . '/>';
        }

        return DispatchResult::reply($this->xml->response($command->name, $command->transactionId, [], $body));
    }

    private function contextNames(Command $command): DispatchResult
    {
        $body = '<context name="Locals" id="' . ContextProvider::CONTEXT_LOCALS . '"/>'
            . '<context name="Superglobals" id="' . ContextProvider::CONTEXT_SUPERGLOBALS . '"/>';

        return DispatchResult::reply($this->xml->response($command->name, $command->transactionId, [], $body));
    }

    private function contextGet(Command $command): DispatchResult
    {
        $contextId = $command->intArgument('c', ContextProvider::CONTEXT_LOCALS) ?? ContextProvider::CONTEXT_LOCALS;
        $depth     = $command->intArgument('d', 0)                               ?? 0;
        $frame     = $this->session->frameAtLevel($depth);
        if ($frame === null) {
            return $this->error($command, ErrorCode::STACK_DEPTH_INVALID, "No stack frame at depth {$depth}");
        }

        $serializer = $this->propertySerializer();
        $body       = '';
        foreach ($this->context->variables($frame, $contextId) as $name => $value) {
            $body .= $serializer->serialize($name, $name, $value);
        }

        return DispatchResult::reply($this->xml->response($command->name, $command->transactionId, [
            'context' => (string) $contextId,
        ], $body));
    }

    /**
     * eval: evaluates an expression in a suspended frame and returns the result
     *
     * The expression arrives base64-encoded in the data part; -d selects the stack frame
     * (0, the innermost, by default). Evaluation is read-only - writing back to the frame
     * is out of scope - and can never throw: ConditionEvaluator turns every failure into
     * DBGp error 206, because this runs inside the FFI statement callback.
     */
    private function evaluate(Command $command): DispatchResult
    {
        $expression = $command->data ?? '';
        if (trim($expression) === '') {
            return $this->error($command, ErrorCode::INVALID_EXPRESSION, 'eval requires an expression in the data part');
        }

        $depth = $command->intArgument('d', 0) ?? 0;
        $scope = $this->evaluationScope($depth);
        if ($scope === null) {
            return $this->error($command, ErrorCode::STACK_DEPTH_INVALID, "No stack frame at depth {$depth}");
        }

        $result = $this->evaluator->evaluate($expression, $scope);
        if (!$result->ok) {
            return $this->error($command, ErrorCode::EVAL_FAILED, (string) $result->error);
        }

        $body = $this->propertySerializer()->serialize($expression, $expression, $result->value);

        return DispatchResult::reply($this->xml->response($command->name, $command->transactionId, [
            'success' => '1',
        ], $body));
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
    private function evaluationScope(int $depth): ?array
    {
        $frame = $this->session->frameAtLevel($depth);
        if ($frame !== null) {
            return $this->context->variables($frame, ContextProvider::CONTEXT_LOCALS);
        }

        return $this->session->suspendedStack() === [] ? [] : null;
    }

    private function stop(Command $command): DispatchResult
    {
        return DispatchResult::terminate($this->xml->response($command->name, $command->transactionId, [
            'status' => SessionStatus::Stopped->value,
            'reason' => 'ok',
        ]));
    }

    private function detach(Command $command): DispatchResult
    {
        return DispatchResult::terminate($this->xml->response($command->name, $command->transactionId, [
            'status' => SessionStatus::Stopping->value,
            'reason' => 'ok',
        ]));
    }

    private function unimplemented(Command $command): DispatchResult
    {
        return $this->error($command, ErrorCode::UNIMPLEMENTED, "Command '{$command->name}' is not implemented");
    }

    private function propertySerializer(): PropertySerializer
    {
        return new PropertySerializer(
            maxDepth: $this->features->getInt('max_depth', 1),
            maxChildren: $this->features->getInt('max_children', 100),
            maxData: $this->features->getInt('max_data', 1024),
        );
    }

    /**
     * @param array<string, string> $attributes
     */
    private function reply(Command $command, array $attributes): DispatchResult
    {
        return DispatchResult::reply($this->xml->response($command->name, $command->transactionId, $attributes));
    }

    private function error(Command $command, int $code, string $message): DispatchResult
    {
        return DispatchResult::reply($this->xml->error($command->name, $command->transactionId, $code, $message));
    }

    private function isCommandName(string $name): bool
    {
        // feature_get is also used to probe command availability; answer the ones we handle
        return in_array($name, [
            'break', 'eval', 'stdout', 'stderr', 'breakpoint_set', 'context_get', 'stack_get',
            'step_into', 'step_over', 'step_out', 'run', 'stop', 'detach', 'status', 'source',
        ], true);
    }
}
