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
use ZDebug\Session\Features;

/**
 * feature_set: stores an IDE-tunable feature, refusing the read-only ones
 */
final class FeatureSet implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::FeatureSet];

    public function __construct(
        private readonly Features $features,
        private readonly Responses $respond,
    ) {}

    public function handle(Command $command): DispatchResult
    {
        $name    = (string) $command->argument('n', '');
        $value   = (string) $command->argument('v', '');
        $success = $this->features->set($name, $value);

        return $this->respond->reply($command, [
            'feature' => $name,
            'success' => $success ? '1' : '0',
        ]);
    }
}
