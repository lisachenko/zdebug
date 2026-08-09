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
use ZDebug\Session\Features;

/**
 * feature_get: reads a feature value, or probes whether a command is implemented
 *
 * An unknown name that happens to be a command we dispatch answers supported="1" with
 * the value "1"; DbgpCommand is the same table CommandDispatcher verifies its handlers
 * against, so the engine can never advertise a command it would then answer error 4 for.
 */
final class FeatureGet implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::FeatureGet];

    public function __construct(
        private readonly Features $features,
        private readonly ResponseBuilder $xml,
    ) {}

    public function handle(Command $command): DispatchResult
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
}
