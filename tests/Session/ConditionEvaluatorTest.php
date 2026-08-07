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

namespace ZDebug\Tests\Session;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZDebug\Session\ConditionEvaluator;

final class ConditionEvaluatorTest extends TestCase
{
    public function testEvaluatesAConstantExpressionWithoutAnyScope(): void
    {
        $result = (new ConditionEvaluator())->evaluate('2 + 40');

        $this->assertTrue($result->ok);
        $this->assertSame(42, $result->value);
        $this->assertNull($result->error);
    }

    public function testLocalsAreVisibleToTheExpression(): void
    {
        $result = (new ConditionEvaluator())->evaluate('$seed * 2 + strlen($label)', [
            'seed'  => 20,
            'label' => 'ab',
        ]);

        $this->assertTrue($result->ok);
        $this->assertSame(42, $result->value);
    }

    public function testLocalNamesMayCarryTheDollarPrefixTheIdeSees(): void
    {
        // ContextProvider hands out '$name' keys; both spellings must work
        $result = (new ConditionEvaluator())->evaluate('$doubled', ['$doubled' => 14]);

        $this->assertTrue($result->ok);
        $this->assertSame(14, $result->value);
    }

    public function testArraysAndObjectsSurviveIntoTheExpression(): void
    {
        $result = (new ConditionEvaluator())->evaluate('$data["items"][1]', [
            'data' => ['items' => ['a', 'b']],
        ]);

        $this->assertTrue($result->ok);
        $this->assertSame('b', $result->value);
    }

    public function testThisIsBoundSoPrivateStateIsReachable(): void
    {
        $evaluator = new ConditionEvaluator();
        $scope     = ['$this' => new ConditionEvaluatorFixture(7)];

        $method = $evaluator->evaluate('$this->visible()', $scope);
        $this->assertTrue($method->ok);
        $this->assertSame(7, $method->value);

        // The closure is bound with the object's own scope, so private state resolves too
        $property = $evaluator->evaluate('$this->hidden', $scope);
        $this->assertTrue($property->ok, (string) $property->error);
        $this->assertSame(7, $property->value);
    }

    public function testTheDebuggerScopeDoesNotLeakIntoTheExpression(): void
    {
        $result = (new ConditionEvaluator())->evaluate('isset($__zdebugCode) || isset($__zdebugScope)');

        $this->assertTrue($result->ok);
        $this->assertFalse($result->value);
    }

    public function testATrailingSemicolonIsTolerated(): void
    {
        $result = (new ConditionEvaluator())->evaluate('  $value > 1;  ', ['value' => 5]);

        $this->assertTrue($result->ok);
        $this->assertTrue($result->value);
    }

    public function testASyntaxErrorFailsInsteadOfThrowing(): void
    {
        $result = (new ConditionEvaluator())->evaluate('1 +');

        $this->assertFalse($result->ok);
        $this->assertNull($result->value);
        $this->assertStringContainsString('ParseError', (string) $result->error);
    }

    public function testAThrowingExpressionFailsInsteadOfThrowing(): void
    {
        $result = (new ConditionEvaluator())->evaluate('intdiv(1, 0)');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('DivisionByZeroError', (string) $result->error);
    }

    public function testAnExpressionThrowingAUserExceptionIsCaught(): void
    {
        $result = (new ConditionEvaluator())->evaluate('$boom->explode()', [
            'boom' => new ConditionEvaluatorFixture(1),
        ]);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('boom', (string) $result->error);
    }

    public function testAnEmptyExpressionFails(): void
    {
        $result = (new ConditionEvaluator())->evaluate("  \n ");

        $this->assertFalse($result->ok);
        $this->assertSame('Empty expression', $result->error);
    }

    public function testAnUndefinedVariableEvaluatesQuietlyToNull(): void
    {
        // Warnings must not be sprayed into the debuggee's output from the hot path
        $result = (new ConditionEvaluator())->evaluate('$nothingHere');

        $this->assertTrue($result->ok);
        $this->assertNull($result->value);
    }

    #[DataProvider('truthinessCases')]
    public function testIsTruthyMirrorsPhpTruthinessAndNeverTrustsAFailure(
        string $expression,
        bool $expected,
    ): void {
        $this->assertSame($expected, (new ConditionEvaluator())->isTruthy($expression, ['count' => 3]));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function truthinessCases(): iterable
    {
        yield 'true comparison' => ['$count > 2', true];
        yield 'false comparison' => ['$count > 5', false];
        yield 'non-empty string' => ['"yes"', true];
        yield 'zero' => ['0', false];
        yield 'empty array' => ['[]', false];
        yield 'null' => ['null', false];
        yield 'syntax error' => ['$count >', false];
        yield 'throwing' => ['intdiv($count, 0)', false];
    }
}
