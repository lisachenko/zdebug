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

namespace ZDebug\Session;

/**
 * Evaluates a user expression against a set of variables
 *
 * Shared by the two features that need it: breakpoint conditions (evaluated in the hot
 * statement hook) and the DBGp `eval` command (evaluated in the command loop). Both run
 * inside the FFI statement callback, so the contract is absolute: this class NEVER
 * throws. Every failure - a parse error, an exception from the expression, an engine
 * error - comes back as a failed EvaluationResult.
 *
 * The expression is evaluated inside a closure whose only visible variables are the ones
 * handed in (via extract()), so the debuggee's scope is reproduced without leaking the
 * debugger's own state. When a `$this` object is present the closure is bound to it, so
 * `$this->privateField` resolves exactly as it would in the suspended frame. Writing back
 * to the frame is out of scope: the closure works on copies.
 *
 * The reentrancy latch in StatementHook must be held by the caller: the compiled
 * expression carries EXT_STMT oplines and would otherwise re-enter the statement handler.
 */
final class ConditionEvaluator
{
    /** Names that cannot be created by extract() and are handled separately */
    private const array RESERVED = ['this' => true, 'GLOBALS' => true];

    /**
     * Evaluates $expression with $variables in scope
     *
     * Variable names may be given with or without the leading '$' (the debugger's context
     * providers use the '$name' spelling the IDE sees), and a '$this' entry holding an
     * object binds the evaluation scope to it.
     *
     * @param array<string, mixed> $variables
     */
    public function evaluate(string $expression, array $variables = []): EvaluationResult
    {
        $code = self::asReturnStatement($expression);
        if ($code === null) {
            return EvaluationResult::failure('Empty expression');
        }

        [$scope, $boundObject] = self::splitScope($variables);

        try {
            $closure = self::evaluatorClosure();
            if ($boundObject !== null) {
                $bound = \Closure::bind($closure, $boundObject, $boundObject::class);
                if ($bound !== null) {
                    $closure = $bound;
                }
            }

            return EvaluationResult::success($closure($code, $scope));
        } catch (\Throwable $error) {
            // Includes ParseError for a malformed expression and anything the expression
            // itself throws; nothing may propagate towards the FFI callback above us.
            return EvaluationResult::failure($error::class . ': ' . $error->getMessage());
        }
    }

    /**
     * Convenience wrapper for breakpoint conditions: truthy only on a successful evaluation
     *
     * @param array<string, mixed> $variables
     */
    public function isTruthy(string $expression, array $variables = []): bool
    {
        return $this->evaluate($expression, $variables)->isTruthy;
    }

    /**
     * Wraps an expression into an evaluable `return ...;` statement, or null when empty
     */
    private static function asReturnStatement(string $expression): ?string
    {
        $trimmed = trim($expression);
        // A trailing ';' is common in watch windows and would break the parenthesized form
        $trimmed = rtrim($trimmed, "; \t\n\r\0\x0B");
        if ($trimmed === '') {
            return null;
        }

        // eval() shares the calling scope, so the code string drops the last variable the
        // evaluator itself needed: the expression then sees exactly the debuggee's names
        // and nothing else (get_defined_vars() included)
        return 'unset($__zdebugCode); return (' . $trimmed . ');';
    }

    /**
     * Splits the incoming variables into extractable ones and the optional $this object
     *
     * @param array<string, mixed> $variables
     *
     * @return array{array<string, mixed>, object|null}
     */
    private static function splitScope(array $variables): array
    {
        $scope       = [];
        $boundObject = null;
        foreach ($variables as $name => $value) {
            $name = ltrim($name, '$');
            if ($name === '') {
                continue;
            }
            if (isset(self::RESERVED[$name])) {
                if ($name === 'this' && is_object($value)) {
                    $boundObject = $value;
                }
                continue;
            }
            $scope[$name] = $value;
        }

        return [$scope, $boundObject];
    }

    /**
     * Builds the unbound closure the expression is evaluated in
     *
     * Declared from a static method so it carries no `$this` of its own and stays bindable
     * to the debuggee's object scope. Its own two parameters are prefixed out of the way so
     * extract(EXTR_SKIP) can never shadow them.
     *
     * @return \Closure(string, array<string, mixed>): mixed
     */
    private static function evaluatorClosure(): \Closure
    {
        return function (string $__zdebugCode, array $__zdebugScope): mixed {
            extract($__zdebugScope, EXTR_SKIP);
            unset($__zdebugScope);

            // Diagnostics are suppressed on purpose: a watch expression touching an
            // undefined variable must not spray warnings into the debuggee's output,
            // least of all from the hot path of a breakpoint condition.
            return @eval($__zdebugCode);
        };
    }
}
