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

namespace ZDebug\Tests\Breakpoint;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZDebug\Breakpoint\Breakpoint;
use ZDebug\Breakpoint\BreakpointType;

final class BreakpointTest extends TestCase
{
    public function testWithoutAHitValueEveryHitBreaks(): void
    {
        $breakpoint = self::lineBreakpoint();

        foreach (range(1, 5) as $hit) {
            $breakpoint->hitCount = $hit;
            $this->assertTrue($breakpoint->hitConditionSatisfied(), "hit {$hit}");
        }
    }

    public function testANegativeHitValueIsTreatedAsNoLimit(): void
    {
        $breakpoint           = self::lineBreakpoint(-3, Breakpoint::HIT_EQUAL);
        $breakpoint->hitCount = 1;

        $this->assertTrue($breakpoint->hitConditionSatisfied());
    }

    /**
     * @param list<int> $breakingHits
     */
    #[DataProvider('hitConditionCases')]
    public function testHitConditionSemantics(string $condition, int $hitValue, array $breakingHits): void
    {
        $breakpoint = self::lineBreakpoint($hitValue, $condition);

        $actual = [];
        foreach (range(1, 7) as $hit) {
            $breakpoint->hitCount = $hit;
            if ($breakpoint->hitConditionSatisfied()) {
                $actual[] = $hit;
            }
        }

        $this->assertSame($breakingHits, $actual);
    }

    /**
     * @return iterable<string, array{string, int, list<int>}>
     */
    public static function hitConditionCases(): iterable
    {
        yield '>= 2 breaks from the second hit on' => [Breakpoint::HIT_GREATER_OR_EQUAL, 2, [2, 3, 4, 5, 6, 7]];
        yield '>= 1 breaks on every hit' => [Breakpoint::HIT_GREATER_OR_EQUAL, 1, [1, 2, 3, 4, 5, 6, 7]];
        yield '== 3 breaks on the third hit only' => [Breakpoint::HIT_EQUAL, 3, [3]];
        yield '% 2 breaks on every second hit' => [Breakpoint::HIT_MULTIPLE, 2, [2, 4, 6]];
        yield '% 3 breaks on every third hit' => [Breakpoint::HIT_MULTIPLE, 3, [3, 6]];
    }

    public function testAnUnknownHitConditionFallsBackToGreaterOrEqual(): void
    {
        $breakpoint           = self::lineBreakpoint(2, 'nonsense');
        $breakpoint->hitCount = 1;
        $this->assertFalse($breakpoint->hitConditionSatisfied());

        $breakpoint->hitCount = 3;
        $this->assertTrue($breakpoint->hitConditionSatisfied());
    }

    public function testConditionalBreakpointsCountAsLineBreakpoints(): void
    {
        $conditional = new Breakpoint(
            id: 1,
            type: BreakpointType::Conditional,
            file: '/app/app.php',
            line: 12,
            condition: '$i === 3',
        );

        $this->assertTrue($conditional->isLineType());
        $this->assertSame('enabled', $conditional->state());
        $this->assertSame(Breakpoint::HIT_GREATER_OR_EQUAL, $conditional->hitCondition);
        $this->assertSame(0, $conditional->hitValue);
    }

    private static function lineBreakpoint(int $hitValue = 0, string $hitCondition = Breakpoint::HIT_GREATER_OR_EQUAL): Breakpoint
    {
        return new Breakpoint(
            id: 1,
            type: BreakpointType::Line,
            file: '/app/app.php',
            line: 10,
            hitValue: $hitValue,
            hitCondition: $hitCondition,
        );
    }
}
