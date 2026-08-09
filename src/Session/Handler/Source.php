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

use ZDebug\Context\SourceReader;
use ZDebug\Protocol\Command;
use ZDebug\Protocol\DbgpCommand;
use ZDebug\Protocol\ErrorCode;
use ZDebug\Protocol\FileUri;
use ZDebug\Session\DispatchResult;
use ZDebug\Session\SuspendedState;

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
final class Source implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::Source];

    public function __construct(
        private readonly SuspendedState $state,
        private readonly SourceReader $reader,
        private readonly Responses $respond,
    ) {}

    public function handle(Command $command): DispatchResult
    {
        $fileUri = $command->argument('f');
        if ($fileUri === null || $fileUri === '') {
            $frame = $this->state->frameAtLevel($command->intArgument('d', 0) ?? 0);
            if ($frame === null) {
                return $this->respond->error($command, ErrorCode::CannotOpenFile, 'source requires -f when no frame is suspended');
            }
            $path = $frame->file;
        } else {
            $path = FileUri::toPath($fileUri);
        }

        $contents = $this->reader->read($path, $command->intArgument('b'), $command->intArgument('e'));
        if ($contents === null) {
            return $this->respond->error($command, ErrorCode::CannotOpenFile, "Cannot read source of '{$path}'");
        }

        return $this->respond->reply($command, [
            'success'  => '1',
            'encoding' => 'base64',
        ], '<![CDATA[' . base64_encode($contents) . ']]>');
    }
}
