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
use ZDebug\Protocol\Command;
use ZDebug\Protocol\DbgpCommand;
use ZDebug\Protocol\ErrorCode;
use ZDebug\Session\DispatchResult;
use ZDebug\Session\SuspendedState;

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
 * in full - one operation on the wire, so one handler serves both spellings.
 */
final class PropertyGet implements CommandHandler
{
    /** property_value returns the whole value; PHP_INT_MAX is "never clamp" for a byte count */
    private const int UNLIMITED_DATA = PHP_INT_MAX;

    public private(set) array $commands = [DbgpCommand::PropertyGet, DbgpCommand::PropertyValue];

    public function __construct(
        private readonly SuspendedState $state,
        private readonly ContextReader $reader,
        private readonly Responses $respond,
    ) {}

    public function handle(Command $command): DispatchResult
    {
        $fullName = trim((string) $command->argument('n', ''));
        if ($fullName === '') {
            return $this->respond->error($command, ErrorCode::InvalidOptions, "{$command->name} requires a property name in -n");
        }

        $contextId = $command->intArgument('c', ContextProvider::CONTEXT_LOCALS) ?? ContextProvider::CONTEXT_LOCALS;
        $depth     = $command->intArgument('d', 0)                               ?? 0;
        $frame     = $this->state->frameAtLevel($depth);
        if ($frame === null) {
            return $this->respond->error($command, ErrorCode::StackDepthInvalid, "No stack frame at depth {$depth}");
        }

        $property = PropertyPath::resolve($this->reader->variables($frame, $contextId, $depth), $fullName);
        if ($property === null) {
            return $this->respond->error($command, ErrorCode::PropertyDoesNotExist, "No such property '{$fullName}'");
        }

        $body = $this->reader->serializer($this->maxDataFor($command))->serialize(
            $property->name,
            $property->fullName,
            $property->value,
            $command->intArgument('p', 0) ?? 0,
            ContextReader::facetOf($property->fullName),
        );

        return $this->respond->reply($command, [], $body);
    }

    /**
     * The data clamp this request runs under, or null to keep the max_data feature
     *
     * property_value is defined as the unclamped read. For property_get, DBGp lets a
     * client pass its own byte budget in -m; Xdebug reads `-m 0` as "no limit at all",
     * which is how an IDE asks for a long string in full without switching commands.
     */
    private function maxDataFor(Command $command): ?int
    {
        if ($command->name === DbgpCommand::PropertyValue->value) {
            return self::UNLIMITED_DATA;
        }

        $requested = $command->intArgument('m');
        if ($requested === null) {
            return null;
        }

        return $requested > 0 ? $requested : self::UNLIMITED_DATA;
    }
}
