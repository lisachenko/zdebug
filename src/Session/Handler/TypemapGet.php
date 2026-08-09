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
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Session\DispatchResult;

/**
 * typemap_get: how this engine's property types map onto the protocol's own
 *
 * A DBGp client uses the map to render a `type="int"` property as a number rather
 * than as a string it happens to be able to parse. The `type` column is the exact
 * spelling PropertySerializer puts on its <property> elements, so what the map
 * promises and what the properties carry cannot drift apart.
 */
final class TypemapGet implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::TypemapGet];

    public function __construct(private readonly Responses $respond) {}

    public function handle(Command $command): DispatchResult
    {
        return $this->respond->reply($command, ResponseBuilder::SCHEMA_NAMESPACES, ResponseBuilder::typeMap());
    }
}
