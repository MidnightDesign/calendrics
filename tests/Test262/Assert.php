<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

use PHPUnit\Framework\Assert as PHPUnitAssert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\IncompleteTestError;
use PHPUnit\Framework\SkippedWithMessageException;

/**
 * Bridges TC39 test262 assert-style calls to PHPUnit.
 *
 * Method signatures match the JS assert API conventions so that generated
 * test scripts read naturally.
 *
 * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
 */
final class Assert
{
    private static ?object $overflowSentinel = null;

    /**
     * Returns a singleton sentinel used in place of BigInt values that exceed
     * PHP's int64 range. Passing this as actual or expected to sameValue()
     * causes the assertion to be silently skipped.
     */
    public static function int64Overflow(): object
    {
        return self::$overflowSentinel ??= new \stdClass();
    }

    /**
     * Asserts that a value is truthy (mirrors the bare assert() function in TC39 test262).
     */
    public static function assertTrue(mixed $value, ?string $message = null): void
    {
        // Match JS's truthiness check: treat falsy PHP values (false, 0, '', null, [], '0') as false.
        $bool =
            $value !== false
            && $value !== 0
            && $value !== 0.0
            && $value !== ''
            && $value !== null
            && $value !== []
            && $value !== '0';
        PHPUnitAssert::assertTrue($bool, $message ?? '');
    }

    /**
     * Asserts that $actual equals $expected (strict equality).
     *
     * Note: argument order matches JS's assert.sameValue(actual, expected),
     * which is the reverse of PHPUnit's assertSame(expected, actual).
     */
    public static function sameValue(mixed $actual, mixed $expected, ?string $message = null): void
    {
        if ($actual === self::int64Overflow() || $expected === self::int64Overflow()) {
            return;
        }
        // The JsUndefined sentinel is the test layer's marker for JS undefined; the spec
        // layer represents JS undefined as PHP null. Normalize for value-equality so
        // tests like `assert.sameValue(instance.era, undefined)` work whether `era`
        // returned PHP null (from a calendar with no eras) or the sentinel.
        if ($actual instanceof JsUndefined) {
            $actual = null;
        }
        if ($expected instanceof JsUndefined) {
            $expected = null;
        }
        // TC39 SameValue treats all numbers as the same type (JS has no int/float distinction).
        // Treat PHP int(n) and float(n.0) as equivalent when they have the same numeric value.
        if ((is_int($actual) || is_float($actual)) && (is_int($expected) || is_float($expected))) {
            $fa = (float) $actual;
            $fe = (float) $expected;
            // SameValue(NaN, NaN) is true in TC39.
            if (is_nan($fa) && is_nan($fe)) {
                PHPUnitAssert::assertNan($fa, $message !== null && $message !== '' ? $message : 'SameValue(NaN, NaN)');
                return;
            }
            // SameValue(-0, +0) is false in TC39, but PHP's === cannot see a zero's sign.
            // fdiv() can: 1/-0.0 is -INF where 1/0.0 is INF.
            if ($fa === 0.0 && $fe === 0.0 && fdiv(num1: 1.0, num2: $fa) !== fdiv(num1: 1.0, num2: $fe)) {
                PHPUnitAssert::fail(sprintf(
                    '%sSameValue(-0, +0) is false; expected %s but got %s.',
                    $message !== null && $message !== '' ? "{$message}: " : '',
                    self::describeZero($fe),
                    self::describeZero($fa),
                ));
            }
            if ($fa === $fe) {
                PHPUnitAssert::assertSame($fa, $fe, $message ?? '');
                return;
            }
        }
        PHPUnitAssert::assertSame($expected, $actual, $message ?? '');
    }

    private static function describeZero(float $zero): string
    {
        return fdiv(num1: 1.0, num2: $zero) < 0.0 ? '-0' : '+0';
    }

    /**
     * Asserts that $actual does NOT equal $unexpected (strict equality).
     *
     * Note: argument order matches JS's assert.notSameValue(actual, unexpected).
     */
    public static function notSameValue(mixed $actual, mixed $unexpected, ?string $message = null): void
    {
        if ($actual === self::int64Overflow() || $unexpected === self::int64Overflow()) {
            return;
        }
        // See sameValue() for the rationale on the JsUndefined → null normalization.
        if ($actual instanceof JsUndefined) {
            $actual = null;
        }
        if ($unexpected instanceof JsUndefined) {
            $unexpected = null;
        }
        PHPUnitAssert::assertNotSame($unexpected, $actual, $message ?? '');
    }

    /**
     * Asserts that $fn throws an instance of $exceptionClass.
     *
     * @param class-string<\Throwable> $exceptionClass
     */
    public static function throws(string $exceptionClass, callable $fn, ?string $message = null): void
    {
        try {
            $fn();
        } catch (AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            PHPUnitAssert::assertInstanceOf($exceptionClass, $e, $message ?? '');
            return;
        }
        PHPUnitAssert::fail("Expected {$exceptionClass} to be thrown, but nothing was thrown. " . ($message ?? ''));
    }

    /**
     * Asserts that two arrays have the same elements in the same order.
     *
     * Note: argument order matches JS's assert.compareArray(actual, expected).
     *
     * @param array<mixed> $actual
     * @param array<mixed> $expected
     */
    public static function compareArray(array $actual, array $expected, ?string $message = null): void
    {
        PHPUnitAssert::assertSame($expected, $actual, $message ?? '');
    }

    /**
     * Asserts a property-access trace recorded by the observers in
     * {@see Helper\ObserversAndCalendar}, ignoring the events PHP cannot produce.
     *
     * JS has two coercion hooks; PHP has one. `__toString` stands in for ToString, so
     * `get X.toString` / `call X.toString` events are recorded and compared. ToNumber
     * has no equivalent — an object cannot be handed to the implementation where an
     * integer is expected without changing the behavior under test — so numeric option
     * values travel unwrapped and never announce their coercion. Dropping the
     * `…valueOf` pairs from the expected trace is what makes the remaining sequence
     * comparable; the property READS around them, which are the point of these
     * fixtures, are compared in full.
     *
     * @param array<mixed> $expected
     */
    public static function compareObserverTrace(ObserverTrace $actual, array $expected, ?string $message = null): void
    {
        $observable = array_values(array_filter(
            $expected,
            static fn(mixed $event): bool => !is_string($event) || !str_ends_with($event, '.valueOf'),
        ));

        PHPUnitAssert::assertSame($observable, $actual->events(), $message ?? '');
    }

    /**
     * Marks the current test as skipped.
     *
     * @psalm-api
     * @throws SkippedWithMessageException always
     * @return never
     * @psalm-suppress InternalClass, InternalMethod
     */
    public static function skip(string $reason): never
    {
        throw new SkippedWithMessageException($reason);
    }

    /**
     * Asserts that a method exists on a class.
     *
     * @param class-string $class
     */
    public static function methodExists(string $class, string $method): void
    {
        PHPUnitAssert::assertTrue(method_exists($class, $method), "Expected method {$class}::{$method}() to exist");
    }

    /**
     * Asserts that a method has the expected number of parameters, matching
     * the JS function.length convention (number of named required arguments).
     *
     * Uses getNumberOfRequiredParameters() with getNumberOfParameters() as a
     * fallback to accommodate PHP compatibility signatures (e.g. null defaults
     * that exist to avoid TypeError on missing-arg calls).
     *
     * @param class-string $class
     */
    public static function methodLength(string $class, string $method, int $expected): void
    {
        $rm = new \ReflectionMethod($class, $method);
        $required = $rm->getNumberOfRequiredParameters();
        $total = $rm->getNumberOfParameters();
        PHPUnitAssert::assertTrue(
            $required === $expected || $total === $expected,
            "Expected {$class}::{$method} JS length {$expected}, " . "got required={$required}, total={$total}",
        );
    }

    /**
     * Asserts that `$prop` on `$fqcn` is a read-only accessor property.
     *
     * PHP port of the canonical test262 `prop-desc.js` accessor shape:
     *
     *     const descriptor = Object.getOwnPropertyDescriptor(Temporal.X.prototype, "P");
     *     assert.sameValue(typeof descriptor.get, "function");
     *     assert.sameValue(descriptor.set, undefined);
     *     assert.sameValue(descriptor.enumerable, false);
     *     assert.sameValue(descriptor.configurable, true);
     *
     * The load-bearing assertions are "a getter exists" and "no setter exists" —
     * i.e. the property is a read-only accessor. The enumerable/configurable bits
     * are pure JS object-model details with no PHP equivalent and are not modeled.
     *
     * In the PHP spec layer each such property is represented EITHER as a PHP 8.4
     * virtual property with a `get` hook and no `set` hook, OR as a stored
     * `readonly` property. Both are read-ok / write-throws, faithfully modeling
     * the JS "getter present / no setter" contract without needing an instance.
     *
     * Lives here beside {@see methodExists}/{@see methodLength}: all three answer
     * "does this class member have the shape test262's prop-desc / verifyProperty
     * expects?" via reflection.
     *
     * @param class-string $fqcn Fully-qualified spec class name (e.g. \Calendrics\Spec\PlainDate).
     * @param string       $prop Property name.
     */
    public static function readOnlyAccessor(string $fqcn, string $prop): void
    {
        $rc = new \ReflectionClass($fqcn);
        PHPUnitAssert::assertTrue($rc->hasProperty($prop), sprintf('%s::$%s exists', $fqcn, $prop));
        $rp = $rc->getProperty($prop);
        PHPUnitAssert::assertTrue($rp->isPublic(), sprintf('%s::$%s is public', $fqcn, $prop));
        if (!$rp->hasHooks()) {
            // Stored property: the read-only contract is encoded by `readonly`.
            PHPUnitAssert::assertTrue($rp->isReadOnly(), sprintf(
                '%s::$%s is a stored readonly property (read-only)',
                $fqcn,
                $prop,
            ));
            return;
        }
        // Virtual property: a `get` hook (getter present) and no `set` hook (read-only).
        PHPUnitAssert::assertTrue(
            $rp->hasHook(\PropertyHookType::Get),
            sprintf('%s::$%s has a get hook (getter present)', $fqcn, $prop),
        );
        PHPUnitAssert::assertFalse(
            $rp->hasHook(\PropertyHookType::Set),
            sprintf('%s::$%s has no set hook (read-only)', $fqcn, $prop),
        );
    }

    /**
     * Marks the current test as incomplete.
     *
     * @throws IncompleteTestError always
     * @return never
     * @psalm-suppress InternalClass, InternalMethod
     */
    public static function incomplete(string $reason): never
    {
        throw new IncompleteTestError($reason);
    }
}
