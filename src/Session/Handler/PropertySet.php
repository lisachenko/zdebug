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
use ZDebug\Context\PropertyPath;
use ZDebug\Context\PropertyWriter;
use ZDebug\Protocol\Command;
use ZDebug\Protocol\DbgpCommand;
use ZDebug\Protocol\ErrorCode;
use ZDebug\Session\DispatchResult;
use ZDebug\Session\ReturnValue;
use ZDebug\Session\SuspendedState;

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
final class PropertySet implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::PropertySet];

    public function __construct(
        private readonly SuspendedState $state,
        private readonly ContextProvider $context,
        private readonly ContextReader $reader,
        private readonly Responses $respond,
    ) {}

    public function handle(Command $command): DispatchResult
    {
        $fullName = trim((string) $command->argument('n', ''));
        if ($fullName === '') {
            return $this->respond->error($command, ErrorCode::InvalidOptions, 'property_set requires a property name in -n');
        }

        $path = PropertyPath::parse($fullName);
        if ($path === null) {
            return $this->respond->error($command, ErrorCode::PropertyDoesNotExist, "'{$fullName}' does not address a property");
        }

        // The returning value is a view of a value in flight, not a slot: it is grafted
        // onto the context by ContextReader, so nothing would receive a write. Saying
        // so explicitly also settles the case of a debuggee that happens to have a real
        // local of that name - reads show the virtual one, so writes must not reach the other
        if ($path->base === ReturnValue::VARIABLE) {
            return $this->respond->error($command, ErrorCode::PropertyDoesNotExist, ReturnValue::VARIABLE . ' is a virtual property and cannot be written');
        }

        $contextId = $command->intArgument('c', ContextProvider::CONTEXT_LOCALS) ?? ContextProvider::CONTEXT_LOCALS;
        $depth     = $command->intArgument('d', 0)                               ?? 0;
        $frame     = $this->state->frameAtLevel($depth);
        if ($frame === null) {
            return $this->respond->error($command, ErrorCode::StackDepthInvalid, "No stack frame at depth {$depth}");
        }

        $slot = $this->context->slot($frame, $contextId, $path->base);
        if ($slot === null) {
            return $this->respond->error($command, ErrorCode::PropertyDoesNotExist, "'{$path->base}' is not a writable variable of this frame");
        }

        // A path with steps must already address something: "does not exist" is error 300,
        // and only a path that DOES exist may come back as a refused (success="0") write.
        // A bare variable is exempt on purpose - a declared-but-unset local has a slot to
        // write and no value to resolve, and giving it one is a legitimate edit
        $current = PropertyPath::resolve($this->reader->variables($frame, $contextId, $depth), $fullName);
        if ($path->steps !== [] && $current === null) {
            return $this->respond->error($command, ErrorCode::PropertyDoesNotExist, "No such property '{$fullName}'");
        }

        $value   = self::coerce($command->data ?? '', $command->argument('t'), $current?->value);
        $written = PropertyWriter::write($slot, $path->steps, $value);

        return $this->respond->reply($command, ['success' => $written ? '1' : '0']);
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
}
