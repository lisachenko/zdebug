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

namespace ZDebug\Stepping;

/**
 * The resume-mode state machine that decides, per statement, whether to suspend
 *
 * Depth is the number of frames on the stack (1 = top-level). It is captured at the
 * moment of resume; the statement hook feeds the current depth on each hit. The
 * comparisons use <= / < rather than == so that frames disappearing without a
 * same-depth statement (exceptions, finally) still stop the stepper correctly.
 */
final class StepController
{
    private ResumeMode $mode = ResumeMode::Run;

    private int $resumeDepth = 0;

    /**
     * Records how execution should resume, capturing the depth of the frame we resume from
     */
    public function resume(ResumeMode $mode, int $currentDepth): void
    {
        $this->mode        = $mode;
        $this->resumeDepth = $currentDepth;
    }

    public function mode(): ResumeMode
    {
        return $this->mode;
    }

    /**
     * Whether the stepper itself needs the current depth computed for this statement
     *
     * StepInto breaks unconditionally, so the (O(depth)) stack walk is skipped for it.
     */
    public function needsDepth(): bool
    {
        return $this->mode === ResumeMode::StepOver || $this->mode === ResumeMode::StepOut;
    }

    /**
     * Whether the stepper wants to break at a statement executing at $currentDepth
     */
    public function shouldBreak(int $currentDepth): bool
    {
        return match ($this->mode) {
            ResumeMode::StepInto                  => true,
            ResumeMode::StepOver                  => $currentDepth <= $this->resumeDepth,
            ResumeMode::StepOut                   => $currentDepth < $this->resumeDepth,
            ResumeMode::Run, ResumeMode::Stopping => false,
        };
    }

    /**
     * Whether the statement hook must observe statements at all under the current mode
     *
     * In pure Run mode with no active stepping the hook still runs for breakpoints, but
     * the stepper contributes nothing.
     */
    public function isStepping(): bool
    {
        return $this->mode === ResumeMode::StepInto
            || $this->mode === ResumeMode::StepOver
            || $this->mode === ResumeMode::StepOut;
    }
}
