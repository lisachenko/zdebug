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
use ZDebug\Breakpoint\BreakpointType;
use ZDebug\Context\ContextProvider;
use ZDebug\Context\PropertyPath;
use ZDebug\Context\PropertySerializer;
use ZDebug\Context\PropertyWriter;
use ZDebug\Context\SourceReader;
use ZDebug\Protocol\Command;
use ZDebug\Protocol\DbgpCommand;
use ZDebug\Protocol\ErrorCode;
use ZDebug\Protocol\FileUri;
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Stepping\ResumeMode;

/**
 * Maps DBGp commands to debugger actions
 *
 * Every handler returns a DispatchResult; a command outside DbgpCommand answers error 4
 * and a handler that throws is turned into error 998 - the loop above never sees an
 * exception, because it runs inside the FFI statement callback where a throw is fatal.
 * Suspended state (stack, frames, status) is read through the SuspendedState contract;
 * the response XML is built entirely by ResponseBuilder.
 */
final class CommandDispatcher
{
    /** property_value returns the whole value; PHP_INT_MAX is "never clamp" for a byte count */
    private const int UNLIMITED_DATA = PHP_INT_MAX;

    public function __construct(
        private readonly SuspendedState $state,
        private readonly Features $features,
        private readonly BreakpointRegistry $breakpoints,
        private readonly ContextProvider $context,
        private readonly ResponseBuilder $xml,
        private readonly ConditionEvaluator $evaluator,
        private readonly SourceReader $source,
    ) {}

    public function dispatch(Command $command): DispatchResult
    {
        try {
            return $this->handle($command);
        } catch (\Throwable $error) {
            return DispatchResult::reply(
                $this->xml->error($command->name, $command->transactionId, ErrorCode::InternalException, $error->getMessage()),
            );
        }
    }

    private function handle(Command $command): DispatchResult
    {
        return match (DbgpCommand::tryFrom($command->name)) {
            DbgpCommand::Status           => $this->status($command),
            DbgpCommand::FeatureGet       => $this->featureGet($command),
            DbgpCommand::FeatureSet       => $this->featureSet($command),
            DbgpCommand::BreakpointSet    => $this->breakpointSet($command),
            DbgpCommand::BreakpointGet    => $this->breakpointGet($command),
            DbgpCommand::BreakpointUpdate => $this->breakpointUpdate($command),
            DbgpCommand::BreakpointRemove => $this->breakpointRemove($command),
            DbgpCommand::BreakpointList   => $this->breakpointList($command),
            DbgpCommand::StackDepth       => $this->stackDepth($command),
            DbgpCommand::StackGet         => $this->stackGet($command),
            DbgpCommand::ContextNames     => $this->contextNames($command),
            DbgpCommand::ContextGet       => $this->contextGet($command),
            DbgpCommand::TypemapGet       => $this->typemapGet($command),
            DbgpCommand::Source           => $this->source($command),
            DbgpCommand::PropertyGet      => $this->propertyGet($command, $this->requestedMaxData($command)),
            DbgpCommand::PropertyValue    => $this->propertyGet($command, self::UNLIMITED_DATA),
            DbgpCommand::PropertySet      => $this->propertySet($command),
            DbgpCommand::Eval             => $this->evaluate($command),
            DbgpCommand::Run              => DispatchResult::continuation(ResumeMode::Run),
            DbgpCommand::StepInto         => DispatchResult::continuation(ResumeMode::StepInto),
            DbgpCommand::StepOver         => DispatchResult::continuation(ResumeMode::StepOver),
            DbgpCommand::StepOut          => DispatchResult::continuation(ResumeMode::StepOut),
            DbgpCommand::Stop             => $this->stop($command),
            DbgpCommand::Detach           => $this->detach($command),
            DbgpCommand::Stdout,
            DbgpCommand::Stderr => $this->reply($command, ['success' => '0']),
            null                => $this->unimplemented($command),
        };
    }

    private function status(Command $command): DispatchResult
    {
        return $this->reply($command, [
            'status' => $this->state->status()->value,
            'reason' => 'ok',
        ]);
    }

    /**
     * feature_get: reads a feature value, or probes whether a command is implemented
     *
     * An unknown name that happens to be a command we dispatch answers supported="1" with
     * the value "1"; DbgpCommand is the same table dispatch matches on, so the engine can
     * never advertise a command it would then answer error 4 for.
     */
    private function featureGet(Command $command): DispatchResult
    {
        $name      = (string) $command->argument('n', '');
        $isCommand = DbgpCommand::isSupported($name);
        $value     = $this->features->get($name) ?? ($isCommand ? '1' : '0');

        return DispatchResult::reply($this->xml->feature(
            $command->transactionId,
            $name,
            $this->features->supports($name) || $isCommand,
            $value,
        ));
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
        $rawType = (string) $command->argument('t', BreakpointType::Line->value);
        $type    = BreakpointType::tryFrom($rawType);
        if ($type === null) {
            return $this->error($command, ErrorCode::BreakpointTypeUnsupported, "Unsupported breakpoint type '{$rawType}'");
        }

        $enabled = ($command->argument('s', 'enabled')) !== 'disabled';

        // -h / -o: break only on the n-th (or every n-th) hit
        $hitValue     = max(0, $command->intArgument('h', 0) ?? 0);
        $hitCondition = (string) $command->argument('o', Breakpoint::HIT_GREATER_OR_EQUAL);
        if (!in_array($hitCondition, Breakpoint::HIT_CONDITIONS, true)) {
            return $this->error($command, ErrorCode::BreakpointInvalid, "Unsupported hit condition '{$hitCondition}'");
        }

        $id = $this->breakpoints->nextId();

        if ($type === BreakpointType::Call || $type === BreakpointType::Return) {
            $functionName = trim((string) $command->argument('m', ''));
            if ($functionName === '') {
                return $this->error($command, ErrorCode::BreakpointInvalid, "A {$type->value} breakpoint requires a function name in -m");
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

            return $this->breakpointAck($command, $id, $enabled);
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

            return $this->breakpointAck($command, $id, $enabled);
        }

        $fileUri = $command->argument('f');
        $line    = $command->intArgument('n');
        if ($fileUri === null || $line === null) {
            return $this->error($command, ErrorCode::BreakpointInvalid, 'Line breakpoint requires -f and -n');
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

    /**
     * breakpoint_update: changes a registered breakpoint in place, keeping its id
     *
     * The IDE sends this instead of remove+set when the user toggles a breakpoint, drags
     * it to another line or edits its hit condition, and expects the id (and with it the
     * accumulated hit count) to survive. Only the four fields DBGp allows are writable;
     * `-n` goes through the registry because the line is an index key, not just a field.
     */
    private function breakpointUpdate(Command $command): DispatchResult
    {
        $id         = $command->intArgument('d');
        $breakpoint = $id !== null ? $this->breakpoints->get($id) : null;
        if ($breakpoint === null) {
            return $this->error($command, ErrorCode::BreakpointDoesNotExist, 'No such breakpoint');
        }

        $state = $command->argument('s');
        if ($state !== null) {
            if ($state !== 'enabled' && $state !== 'disabled') {
                return $this->error($command, ErrorCode::BreakpointStateInvalid, "Unsupported breakpoint state '{$state}'");
            }
            $breakpoint->enabled = $state === 'enabled';
        }

        $hitCondition = $command->argument('o');
        if ($hitCondition !== null) {
            if (!in_array($hitCondition, Breakpoint::HIT_CONDITIONS, true)) {
                return $this->error($command, ErrorCode::BreakpointInvalid, "Unsupported hit condition '{$hitCondition}'");
            }
            $breakpoint->hitCondition = $hitCondition;
        }

        $hitValue = $command->intArgument('h');
        if ($hitValue !== null) {
            $breakpoint->hitValue = max(0, $hitValue);
        }

        $line = $command->intArgument('n');
        if ($line !== null) {
            $this->breakpoints->relocate($breakpoint, $line);
        }

        return $this->body($command, ResponseBuilder::breakpoint($breakpoint));
    }

    private function breakpointRemove(Command $command): DispatchResult
    {
        $id = $command->intArgument('d');
        if ($id === null || !$this->breakpoints->remove($id)) {
            return $this->error($command, ErrorCode::BreakpointDoesNotExist, 'No such breakpoint');
        }

        return $this->reply($command, []);
    }

    private function breakpointGet(Command $command): DispatchResult
    {
        $id         = $command->intArgument('d');
        $breakpoint = $id !== null ? $this->breakpoints->get($id) : null;
        if ($breakpoint === null) {
            return $this->error($command, ErrorCode::BreakpointDoesNotExist, 'No such breakpoint');
        }

        return $this->body($command, ResponseBuilder::breakpoint($breakpoint));
    }

    private function breakpointList(Command $command): DispatchResult
    {
        $body = '';
        foreach ($this->breakpoints->all() as $breakpoint) {
            $body .= ResponseBuilder::breakpoint($breakpoint);
        }

        return $this->body($command, $body);
    }

    /**
     * stack_depth: how many frames stack_get would report
     *
     * Clients call it to size their call-stack panel before fetching frames, and while
     * nothing is suspended the honest answer is zero rather than an error.
     */
    private function stackDepth(Command $command): DispatchResult
    {
        return $this->reply($command, ['depth' => (string) count($this->state->suspendedStack())]);
    }

    private function stackGet(Command $command): DispatchResult
    {
        $requested = $command->intArgument('d');
        $body      = '';
        foreach ($this->state->suspendedStack() as $frame) {
            if ($requested !== null && $frame->level !== $requested) {
                continue;
            }
            $body .= ResponseBuilder::stackFrame(
                $frame->level,
                $frame->where,
                FileUri::fromPath($frame->file),
                $frame->line,
            );
        }

        return $this->body($command, $body);
    }

    private function contextNames(Command $command): DispatchResult
    {
        return $this->body($command, ResponseBuilder::contextNames([
            'Locals'       => ContextProvider::CONTEXT_LOCALS,
            'Superglobals' => ContextProvider::CONTEXT_SUPERGLOBALS,
        ]));
    }

    private function contextGet(Command $command): DispatchResult
    {
        $contextId = $command->intArgument('c', ContextProvider::CONTEXT_LOCALS) ?? ContextProvider::CONTEXT_LOCALS;
        $depth     = $command->intArgument('d', 0)                               ?? 0;
        $frame     = $this->state->frameAtLevel($depth);
        if ($frame === null) {
            return $this->error($command, ErrorCode::StackDepthInvalid, "No stack frame at depth {$depth}");
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
     * typemap_get: how this engine's property types map onto the protocol's own
     *
     * A DBGp client uses the map to render a `type="int"` property as a number rather
     * than as a string it happens to be able to parse. The `type` column is the exact
     * spelling PropertySerializer puts on its <property> elements, so what the map
     * promises and what the properties carry cannot drift apart.
     */
    private function typemapGet(Command $command): DispatchResult
    {
        return DispatchResult::reply($this->xml->response(
            $command->name,
            $command->transactionId,
            ResponseBuilder::SCHEMA_NAMESPACES,
            ResponseBuilder::typeMap(),
        ));
    }

    /**
     * source: hands the IDE the code it is stopped in
     *
     * Needed whenever the IDE's copy of a file is not the one executing - remote
     * debugging, a container path, sources that were never checked out locally. -f
     * defaults to the file of the frame selected by -d, so "show me where I am" is a
     * one-argument command; -b / -e select an inclusive line range.
     *
     * A file outside the debugger's path filter, or one that cannot be read, is answered
     * with DBGp error 100 rather than an empty success - "I will not show you this" and
     * "this file is empty" are different answers to an IDE.
     */
    private function source(Command $command): DispatchResult
    {
        $fileUri = $command->argument('f');
        if ($fileUri === null || $fileUri === '') {
            $frame = $this->state->frameAtLevel($command->intArgument('d', 0) ?? 0);
            if ($frame === null) {
                return $this->error($command, ErrorCode::CannotOpenFile, 'source requires -f when no frame is suspended');
            }
            $path = $frame->file;
        } else {
            $path = FileUri::toPath($fileUri);
        }

        $contents = $this->source->read($path, $command->intArgument('b'), $command->intArgument('e'));
        if ($contents === null) {
            return $this->error($command, ErrorCode::CannotOpenFile, "Cannot read source of '{$path}'");
        }

        return DispatchResult::reply($this->xml->response($command->name, $command->transactionId, [
            'success'  => '1',
            'encoding' => 'base64',
        ], '<![CDATA[' . base64_encode($contents) . ']]>'));
    }

    /**
     * property_get / property_value: expands one variable of a suspended frame
     *
     * This is the other half of context_get. A context is rendered one level deep - deeper
     * would mean serializing an arbitrary object graph into every break - so an IDE that
     * wants to look inside a variable asks for it by the `fullname` the context response
     * put on it: "$error->previous", "$rows[3]['id']". PropertyPath walks that path back
     * through the same materialized values the context was built from, and the node found
     * is rendered with its own children, which is how the variables panel opens an
     * exception (message, code, file, line, trace, previous) rather than showing a bare
     * "object" leaf.
     *
     * -p pages a large container, -m overrides max_data for this one response; both are
     * only defaults the IDE may sharpen. property_value is the same lookup with the data
     * clamp lifted, which is what an IDE fetches when the user opens a truncated string
     * in full.
     */
    private function propertyGet(Command $command, ?int $maxData): DispatchResult
    {
        $fullName = trim((string) $command->argument('n', ''));
        if ($fullName === '') {
            return $this->error($command, ErrorCode::InvalidOptions, "{$command->name} requires a property name in -n");
        }

        $contextId = $command->intArgument('c', ContextProvider::CONTEXT_LOCALS) ?? ContextProvider::CONTEXT_LOCALS;
        $depth     = $command->intArgument('d', 0)                               ?? 0;
        $frame     = $this->state->frameAtLevel($depth);
        if ($frame === null) {
            return $this->error($command, ErrorCode::StackDepthInvalid, "No stack frame at depth {$depth}");
        }

        $property = PropertyPath::resolve($this->context->variables($frame, $contextId), $fullName);
        if ($property === null) {
            return $this->error($command, ErrorCode::PropertyDoesNotExist, "No such property '{$fullName}'");
        }

        $body = $this->propertySerializer($maxData)->serialize(
            $property->name,
            $property->fullName,
            $property->value,
            $command->intArgument('p', 0) ?? 0,
        );

        return DispatchResult::reply($this->xml->response($command->name, $command->transactionId, [], $body));
    }

    /**
     * property_set: writes a value back into the suspended debuggee
     *
     * The only command that changes the program rather than describing it, which is why
     * every step is a refusal by default: the base variable has to be a real CV slot of
     * the selected frame (or a superglobal), each intermediate step has to exist already,
     * and a write the engine rejects - a readonly property, a typed property the value
     * does not fit - comes back as success="0" rather than as a broken debuggee.
     *
     * The new value arrives base64-encoded in the data part. `-t` names its type; without
     * it the type of whatever is there now is kept, which is what an IDE relies on when
     * the user edits an int in the variables panel and types digits.
     */
    private function propertySet(Command $command): DispatchResult
    {
        $fullName = trim((string) $command->argument('n', ''));
        if ($fullName === '') {
            return $this->error($command, ErrorCode::InvalidOptions, 'property_set requires a property name in -n');
        }

        $path = PropertyPath::parse($fullName);
        if ($path === null) {
            return $this->error($command, ErrorCode::PropertyDoesNotExist, "'{$fullName}' does not address a property");
        }

        $contextId = $command->intArgument('c', ContextProvider::CONTEXT_LOCALS) ?? ContextProvider::CONTEXT_LOCALS;
        $depth     = $command->intArgument('d', 0)                               ?? 0;
        $frame     = $this->state->frameAtLevel($depth);
        if ($frame === null) {
            return $this->error($command, ErrorCode::StackDepthInvalid, "No stack frame at depth {$depth}");
        }

        $slot = $this->context->slot($frame, $contextId, $path->base);
        if ($slot === null) {
            return $this->error($command, ErrorCode::PropertyDoesNotExist, "'{$path->base}' is not a writable variable of this frame");
        }

        // A path with steps must already address something: "does not exist" is error 300,
        // and only a path that DOES exist may come back as a refused (success="0") write.
        // A bare variable is exempt on purpose - a declared-but-unset local has a slot to
        // write and no value to resolve, and giving it one is a legitimate edit
        $current = PropertyPath::resolve($this->context->variables($frame, $contextId), $fullName);
        if ($path->steps !== [] && $current === null) {
            return $this->error($command, ErrorCode::PropertyDoesNotExist, "No such property '{$fullName}'");
        }

        $value   = self::coerce($command->data ?? '', $command->argument('t'), $current?->value);
        $written = PropertyWriter::write($slot, $path->steps, $value);

        return $this->reply($command, ['success' => $written ? '1' : '0']);
    }

    /**
     * Turns the raw bytes of a property_set into the PHP value to store
     *
     * `-t` is the client's declared type; when it is absent the type currently at that
     * path is kept, so editing a variable in the IDE does not silently turn an int into
     * the string "42". An unknown type name falls through to the raw string rather than
     * being rejected: the bytes the client sent are still the closest thing to its intent.
     */
    private static function coerce(string $raw, ?string $declaredType, mixed $current): mixed
    {
        $type = $declaredType ?? match (true) {
            is_bool($current)  => 'bool',
            is_int($current)   => 'int',
            is_float($current) => 'float',
            default            => 'string',
        };

        return match ($type) {
            'bool', 'boolean' => !in_array(strtolower(trim($raw)), ['', '0', 'false', 'off', 'no'], true),
            'int', 'integer'  => (int) $raw,
            'float', 'double' => (float) $raw,
            'null'            => null,
            default           => $raw,
        };
    }

    /**
     * The per-command data clamp of a property_get, or null to keep the max_data feature
     *
     * DBGp lets a client pass its own byte budget in -m; Xdebug reads `-m 0` as "no limit
     * at all", which is how an IDE asks for a long string in full without switching to
     * property_value.
     */
    private function requestedMaxData(Command $command): ?int
    {
        $requested = $command->intArgument('m');
        if ($requested === null) {
            return null;
        }

        return $requested > 0 ? $requested : self::UNLIMITED_DATA;
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
            return $this->error($command, ErrorCode::InvalidExpression, 'eval requires an expression in the data part');
        }

        $depth = $command->intArgument('d', 0) ?? 0;
        $scope = $this->evaluationScope($depth);
        if ($scope === null) {
            return $this->error($command, ErrorCode::StackDepthInvalid, "No stack frame at depth {$depth}");
        }

        $result = $this->evaluator->evaluate($expression, $scope);
        if (!$result->ok) {
            return $this->error($command, ErrorCode::EvalFailed, (string) $result->error);
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
        $frame = $this->state->frameAtLevel($depth);
        if ($frame !== null) {
            return $this->context->variables($frame, ContextProvider::CONTEXT_LOCALS);
        }

        return $this->state->suspendedStack() === [] ? [] : null;
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
        return $this->error($command, ErrorCode::Unimplemented, "Command '{$command->name}' is not implemented");
    }

    /**
     * Builds the serializer for one response, honoring the IDE's current max_* features
     *
     * $maxData overrides the feature for commands that carry their own limit: the -m
     * argument of property_get, or property_value, which is defined as the unclamped read.
     */
    private function propertySerializer(?int $maxData = null): PropertySerializer
    {
        return new PropertySerializer(
            maxDepth: $this->features->getInt('max_depth'),
            maxChildren: $this->features->getInt('max_children'),
            maxData: $maxData ?? $this->features->getInt('max_data'),
        );
    }

    /**
     * @param array<string, string> $attributes
     */
    private function reply(Command $command, array $attributes): DispatchResult
    {
        return DispatchResult::reply($this->xml->response($command->name, $command->transactionId, $attributes));
    }

    /**
     * Replies with child elements and no extra attributes on the <response>
     */
    private function body(Command $command, string $body): DispatchResult
    {
        return DispatchResult::reply($this->xml->response($command->name, $command->transactionId, [], $body));
    }

    private function error(Command $command, ErrorCode $code, string $message): DispatchResult
    {
        return DispatchResult::reply($this->xml->error($command->name, $command->transactionId, $code, $message));
    }
}
