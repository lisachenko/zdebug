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

namespace ZDebug\Tests\Stepping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZDebug\Stepping\ResumeMode;
use ZDebug\Stepping\StepController;

final class StepControllerTest extends TestCase
{
    public function testRunModeNeverBreaks(): void
    {
        $controller = new StepController();
        $controller->resume(ResumeMode::Run, 3);
        $this->assertFalse($controller->shouldBreak(3));
        $this->assertFalse($controller->needsDepth());
        $this->assertFalse($controller->isStepping());
    }

    public function testStepIntoAlwaysBreaksWithoutNeedingDepth(): void
    {
        $controller = new StepController();
        $controller->resume(ResumeMode::StepInto, 5);
        $this->assertFalse($controller->needsDepth());
        $this->assertTrue($controller->shouldBreak(99));
        $this->assertTrue($controller->shouldBreak(1));
    }

    #[DataProvider('depthCases')]
    public function testDepthComparisons(ResumeMode $mode, int $resumeDepth, int $currentDepth, bool $expected): void
    {
        $controller = new StepController();
        $controller->resume($mode, $resumeDepth);
        $this->assertSame($expected, $controller->shouldBreak($currentDepth));
    }

    /**
     * @return iterable<string, array{ResumeMode, int, int, bool}>
     */
    public static function depthCases(): iterable
    {
        // step_over: break at same or shallower depth, skip deeper (called) frames
        yield 'over: same depth breaks' => [ResumeMode::StepOver, 3, 3, true];
        yield 'over: shallower breaks' => [ResumeMode::StepOver, 3, 2, true];
        yield 'over: deeper does not break' => [ResumeMode::StepOver, 3, 4, false];
        // step_out: break only strictly shallower (after the current frame returns)
        yield 'out: same depth does not' => [ResumeMode::StepOut, 3, 3, false];
        yield 'out: shallower breaks' => [ResumeMode::StepOut, 3, 2, true];
        yield 'out: deeper does not' => [ResumeMode::StepOut, 3, 5, false];
    }

    public function testStepOverAndOutNeedDepth(): void
    {
        $controller = new StepController();
        $controller->resume(ResumeMode::StepOver, 1);
        $this->assertTrue($controller->needsDepth());
        $controller->resume(ResumeMode::StepOut, 1);
        $this->assertTrue($controller->needsDepth());
    }
}
