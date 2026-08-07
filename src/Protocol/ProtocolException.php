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

namespace ZDebug\Protocol;

/**
 * Raised when an incoming DBGp packet cannot be parsed
 */
final class ProtocolException extends \RuntimeException
{
    public static function emptyCommand(): self
    {
        return new self('Empty DBGp command line');
    }

    public static function missingTransactionId(string $command): self
    {
        return new self("DBGp command '{$command}' is missing the required -i transaction id");
    }

    public static function malformedData(string $command): self
    {
        return new self("DBGp command '{$command}' carries a malformed base64 data part");
    }
}
