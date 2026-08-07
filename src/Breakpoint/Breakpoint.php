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

namespace ZDebug\Breakpoint;

/**
 * A single breakpoint as registered by the IDE via breakpoint_set
 *
 * The PoC supports line breakpoints (with an optional condition, evaluated in the
 * frame) and exception breakpoints (matched by class name). $file is the resolved
 * filesystem path; the DBGp file:// URI is reconstructed on the way out.
 */
final class Breakpoint
{
    public const string TYPE_LINE      = 'line';
    public const string TYPE_CONDITION = 'conditional';
    public const string TYPE_EXCEPTION = 'exception';

    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public bool $enabled = true,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        public readonly ?string $condition = null,
        public readonly ?string $exceptionName = null,
        public readonly bool $temporary = false,
        public int $hitCount = 0,
    ) {}

    public function isLineType(): bool
    {
        return $this->type === self::TYPE_LINE || $this->type === self::TYPE_CONDITION;
    }

    public function state(): string
    {
        return $this->enabled ? 'enabled' : 'disabled';
    }
}
