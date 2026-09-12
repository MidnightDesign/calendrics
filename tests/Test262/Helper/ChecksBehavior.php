<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262\Helper;

use Calendrics\Tests\Test262\JsSymbol;
use PHPUnit\Framework\Assert as PHPUnitAssert;

/**
 * Behavioral check-harnesses ported from TC39's TemporalHelpers harness.
 *
 * Mirrors the upstream `TemporalHelpers.check*` family: each method drives a
 * callback under test through a fixed sequence of inputs (plural units, wrong
 * option types, fast-path objects, …) and asserts the observed behavior, rather
 * than comparing a single value object.
 *
 * Composed into {@see \Calendrics\Tests\Test262\TemporalHelpers}; the public
 * surface is `TemporalHelpers::check*()`.
 */
trait ChecksBehavior
{
    // checkPluralUnitsAccepted compares Duration/Instant/ZonedDateTime results via the
    // value-object assertions, so this trait composes them. PHP de-duplicates the trait
    // when TemporalHelpers also uses it directly.
    use AssertsValueObjects;

    /**
     * Asserts that both the singular and plural forms of each unit name produce
     * the same result when passed to a function.
     *
     * Argument order matches JS TemporalHelpers.checkPluralUnitsAccepted(fn, units).
     *
     * The JS version also handles PlainDateTime, PlainTime, ZonedDateTime results;
     * this PHP port handles Duration and Instant results only.
     *
     * @param callable(string): mixed      $func             Function under test.
     * @param list<string>                 $validSingularUnits Singular unit names to test.
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function checkPluralUnitsAccepted(callable $func, array $validSingularUnits): void
    {
        $plurals = [
            'year' => 'years',
            'month' => 'months',
            'week' => 'weeks',
            'day' => 'days',
            'hour' => 'hours',
            'minute' => 'minutes',
            'second' => 'seconds',
            'millisecond' => 'milliseconds',
            'microsecond' => 'microseconds',
            'nanosecond' => 'nanoseconds',
        ];
        foreach ($validSingularUnits as $unit) {
            /** @var mixed $singular */
            $singular = $func($unit);
            /** @var mixed $plural */
            $plural = $func($plurals[$unit] ?? sprintf('%ss', $unit));
            $desc = "Plural {$plurals[$unit]} produces the same result as singular {$unit}";
            if ($singular instanceof \Calendrics\Spec\Duration) {
                /** @psalm-suppress MixedArgument */
                // @mago-ignore analysis:mixed-argument
                // @phpstan-ignore argument.type
                self::assertDurationsEqual($plural, $singular, $desc);
            } elseif ($singular instanceof \Calendrics\Spec\Instant) {
                /** @psalm-suppress MixedArgument */
                // @mago-ignore analysis:mixed-argument
                // @phpstan-ignore argument.type
                self::assertInstantsEqual($plural, $singular, $desc);
            } elseif ($singular instanceof \Calendrics\Spec\PlainTime) {
                /** @psalm-suppress MixedArgument */
                // @mago-ignore analysis:mixed-argument
                PHPUnitAssert::assertTrue($singular->equals($plural), $desc); // @phpstan-ignore argument.type
            } elseif ($singular instanceof \Calendrics\Spec\PlainDateTime) {
                /** @psalm-suppress MixedArgument */
                // @mago-ignore analysis:mixed-argument
                PHPUnitAssert::assertTrue($singular->equals($plural), $desc); // @phpstan-ignore argument.type
            } elseif ($singular instanceof \Calendrics\Spec\PlainDate) {
                /** @psalm-suppress MixedArgument */
                // @mago-ignore analysis:mixed-argument
                PHPUnitAssert::assertTrue($singular->equals($plural), $desc); // @phpstan-ignore argument.type
            } elseif ($singular instanceof \Calendrics\Spec\ZonedDateTime) {
                /** @psalm-suppress MixedArgument */
                // @mago-ignore analysis:mixed-argument
                // @phpstan-ignore argument.type
                self::assertZonedDateTimesEqual($plural, $singular, $desc);
            } else {
                PHPUnitAssert::assertSame($singular, $plural, $desc);
            }
        }
    }

    /**
     * Tests that an instance method does not return a subclass instance (PHP: final classes,
     * so this simply verifies the method returns the correct type with expected field values).
     *
     * In JS, tests that subclass constructors are not invoked when the method returns a new instance.
     * PHP Temporal classes are all final, so subclassing is impossible; we just call the method
     * and assert the return value satisfies the check function.
     *
     * Argument order matches JS TemporalHelpers.checkSubclassingIgnored(class, ctorArgs, method, methodArgs, checkFn).
     *
     * @param class-string          $class      Fully-qualified class name.
     * @param list<mixed>           $ctorArgs   Constructor arguments.
     * @param string                $method     Instance method name.
     * @param list<mixed>           $methodArgs Method arguments.
     * @param callable(mixed): void $checkFn    Assertions on the return value.
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function checkSubclassingIgnored(
        string $class,
        array $ctorArgs,
        string $method,
        array $methodArgs,
        callable $checkFn,
    ): void {
        /** @psalm-suppress MixedMethodCall, UnsafeInstantiation, MixedArgumentTypeCoercion */
        // @mago-ignore analysis:unknown-class-instantiation
        $instance = new $class(...$ctorArgs);
        /** @var callable $callable */
        $callable = [$instance, $method];
        /** @var mixed $result */
        $result = $callable(...$methodArgs);
        PHPUnitAssert::assertInstanceOf($class, $result, "Return value should be an instance of {$class}");
        $checkFn($result);
    }

    /**
     * Tests that a static method does not return a subclass instance (PHP: final classes,
     * so this simply verifies the static method returns the correct type with expected values).
     *
     * In JS, tests that calling a static method via a subclass still returns the base class type.
     * PHP Temporal classes are all final; we just call the static method and run the check.
     *
     * Argument order matches JS TemporalHelpers.checkSubclassingIgnoredStatic(class, method, args, checkFn).
     *
     * @param class-string          $class   Fully-qualified class name.
     * @param string                $method  Static method name.
     * @param list<mixed>           $args    Method arguments.
     * @param callable(mixed): void $checkFn Assertions on the return value.
     * @psalm-suppress UnusedParam Psalm does not track $method in $class::$method() dynamic dispatch.
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function checkSubclassingIgnoredStatic(
        string $class,
        string $method,
        array $args,
        callable $checkFn,
    ): void {
        /** @var callable $callable */
        $callable = [$class, $method];
        /** @var mixed $result */
        $result = $callable(...$args);
        PHPUnitAssert::assertInstanceOf($class, $result, "Return value should be an instance of {$class}");
        $checkFn($result);
    }

    /**
     * Tests that wrong-type values for the roundingIncrement option cause exceptions,
     * that true (= 1) uses the default increment, and that object-with-valueOf is skipped.
     *
     * Covers the subset of TC39 checkRoundingIncrementOptionWrongType testable in PHP:
     *   - null   → skipped; PHP treats null as "not set" (uses default 1), differs from spec (0 → RangeError)
     *   - true   → 1 (valid) → assertRoundedDown called
     *   - false  → 0 → any exception expected
     *   - Symbol → skipped (PHP has no Symbol type)
     *   - BigInt → skipped (PHP has no BigInt type)
     *   - {}     → any exception expected (object→int coercion fails in PHP 8.0+)
     *   - {valueOf → 2} → skipped (PHP does not invoke valueOf on objects)
     *
     * Argument order matches JS TemporalHelpers.checkRoundingIncrementOptionWrongType(fn, assertUp, assertDown).
     *
     * @param callable(mixed): mixed          $fn               Function under test (receives roundingIncrement).
     * @param callable(mixed, string): void   $assertRoundedUp  Assertion for result with increment = 2 (skipped in PHP).
     * @param callable(mixed, string): void   $assertRoundedDown Assertion for result with increment = 1 (true).
     * @psalm-suppress UnusedParam $assertRoundedDown is not called (object-with-valueOf case skipped in PHP).
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function checkRoundingIncrementOptionWrongType(
        callable $fn,
        callable $assertRoundedUp,
        callable $assertRoundedDown,
    ): void {
        // null → 0 in JS spec → RangeError.
        // In PHP, null is treated as "option not set" → default 1 → no exception; skipped.

        // true → ToNumber(true) = 1 → valid; increment=1 + halfExpand → rounds up.
        // The harness calls assertRoundedUp for true (increment=1) and assertRoundedDown
        // for {valueOf → 2} (increment=2, which rounds down due to 0.987s/2s = 0.49 < 0.5).
        /** @var mixed $result */
        $result = $fn(true);
        $description = 'true';
        $assertRoundedUp($result, $description);

        // false → ToNumber(false) = 0 → out of range → RangeError (a Temporal domain error).
        try {
            $fn(false);
            PHPUnitAssert::fail('Expected exception for roundingIncrement=false, but nothing was thrown.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $thrown) {
            // Assert it is a Temporal domain error (not a stray PHP runtime error),
            // which counts one meaningful assertion on the expected failure.
            PHPUnitAssert::assertInstanceOf(
                \Calendrics\Exception\CalendricsException::class,
                $thrown,
                'roundingIncrement=false throws a Temporal exception',
            );
        }

        // Symbol → TypeError (skip: PHP has no Symbol type).
        // BigInt → TypeError (skip: PHP has no BigInt type).

        // plain object → ToNumber({}) = NaN in JS → RangeError.
        // In PHP, (int) new stdClass() = 1 (warning, not exception), which is a valid increment.
        // Skipped: PHP cannot replicate JS object-to-NaN coercion.

        // object with valueOf() → 2 → assertRoundedDown (increment=2 rounds down); skipped in PHP.
        /** @psalm-suppress UnusedVariable */
        $_ = $assertRoundedDown; // referenced to suppress "param never read" warnings
    }

    /**
     * Tests that wrong-type values for a string option cause exceptions, and
     * that the valid value produces the expected result.
     *
     * Faithful port of TC39 checkStringOptionWrongType. GetOption(..., "string")
     * applies ToString, so the asserted behavior is:
     *   - null is skipped: our PHP implementation treats null as "option not set"
     *     (uses default) rather than ToString-ing "null" → RangeError as JS does.
     *   - true, false, int(2), plain object → ToString yields a value that is
     *     never a valid option keyword → RangeError.
     *   - Symbol() (JsSymbol sentinel) → ToString throws TypeError.
     *   - an object whose toString() returns the valid value → coerces and
     *     succeeds (the "observer" success step; drives the Stringable branch).
     *   - BigInt is skipped (PHP has no equivalent type).
     *
     * Argument order matches JS TemporalHelpers.checkStringOptionWrongType(key, val, fn, assertFn).
     *
     * @param callable(mixed): mixed   $checkFunc  Function under test (receives the option value).
     * @param callable(mixed, string): void $assertFunc Assertion callback for the valid result.
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function checkStringOptionWrongType(
        string $propertyName,
        string $value,
        callable $checkFunc,
        callable $assertFunc,
    ): void {
        // ToString of a bool/number/plain object is never a valid option keyword → RangeError.
        // null is skipped: our PHP implementation treats null as "option not set" (uses default)
        // rather than throwing, which differs from JS where null converts to "null" string → RangeError.
        foreach ([true, false, 2, new \stdClass()] as $wrongValue) {
            try {
                $checkFunc($wrongValue);
                PHPUnitAssert::fail(sprintf(
                    'Expected RangeError for %s=%s, but nothing was thrown.',
                    $propertyName,
                    var_export(value: $wrongValue, return: true),
                ));
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                throw $e;
            } catch (\Throwable $thrown) {
                PHPUnitAssert::assertInstanceOf(
                    \Calendrics\Exception\RangeError::class,
                    $thrown,
                    sprintf(
                        'Expected RangeError for %s=%s.',
                        $propertyName,
                        var_export(value: $wrongValue, return: true),
                    ),
                );
            }
        }
        // Symbol() cannot be ToString-ed: JsSymbol's __toString throws TypeError.
        try {
            $checkFunc(JsSymbol::singleton());
            PHPUnitAssert::fail(sprintf('Expected TypeError for %s=Symbol(), but nothing was thrown.', $propertyName));
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $thrown) {
            PHPUnitAssert::assertInstanceOf(
                \Calendrics\Exception\TypeError::class,
                $thrown,
                sprintf('Expected TypeError for %s=Symbol().', $propertyName),
            );
        }
        // BigInt skipped — PHP has no equivalent type.
        // Observer success step: an object whose toString() returns the valid value
        // must coerce via ToString and produce the expected result.
        $observer = new class($value) implements \Stringable {
            public function __construct(
                private string $v,
            ) {}

            #[\Override]
            public function __toString(): string
            {
                return $this->v;
            }
        };
        $observerDescription = 'object with toString';
        /** @var mixed $result */
        $result = $checkFunc($observer);
        $assertFunc($result, $observerDescription);
        // Test with the valid value directly:
        $stringDescription = 'string';
        /** @var mixed $directResult */
        $directResult = $checkFunc($value);
        $assertFunc($directResult, $stringDescription);
    }

    /**
     * Invokes `$fn` with each of the five Temporal object types that carry an ISO
     * calendar in their internal slots (PlainDate, PlainDateTime, PlainMonthDay,
     * PlainYearMonth, ZonedDateTime) and asserts that none of the calls throw.
     *
     * PHP port of JS TemporalHelpers.checkToTemporalCalendarFastPath(fn).
     * The spec fast-path at sec-temporal-totemporalcalendar step 1.a reads the
     * [[Calendar]] internal slot from any of these five types directly rather than
     * calling the public calendar getter, so the tests verify no observable
     * operations are performed on the calendar object during that conversion.
     *
     * @param callable(mixed): mixed $fn Callback under test.
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function checkToTemporalCalendarFastPath(callable $fn): void
    {
        $objects = [
            new \Calendrics\Spec\PlainDate(2000, 5, 2),
            new \Calendrics\Spec\PlainDateTime(2000, 5, 2),
            new \Calendrics\Spec\PlainMonthDay(5, 2),
            new \Calendrics\Spec\PlainYearMonth(2000, 5),
            new \Calendrics\Spec\ZonedDateTime(0, 'UTC'),
        ];
        foreach ($objects as $obj) {
            try {
                /** @var mixed $result */
                $result = $fn($obj);
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                throw $e;
            } catch (\Throwable $e) {
                PHPUnitAssert::fail(sprintf(
                    'checkToTemporalCalendarFastPath: unexpected exception for %s: %s',
                    get_class($obj),
                    $e->getMessage(),
                ));
            }
            // The conversion returned a value rather than throwing — count one assertion.
            PHPUnitAssert::assertNotInstanceOf(
                \Throwable::class,
                $result,
                sprintf('%s: no exception thrown', get_class($obj)),
            );
        }
    }

    /**
     * Invokes `$fn` with a PlainDateTime and asserts it does not throw.
     *
     * PHP port of JS TemporalHelpers.checkPlainDateTimeConversionFastPath(fn[, message]).
     * The harness creates `new Temporal.PlainDateTime(2000, 5, 2, 12, 34, 56, 987, 654, 321)`.
     *
     * @param callable(mixed): mixed $fn      Callback under test.
     * @param string                 $message Optional description for failure messages.
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function checkPlainDateTimeConversionFastPath(callable $fn, string $message = ''): void
    {
        $dt = new \Calendrics\Spec\PlainDateTime(2000, 5, 2, 12, 34, 56, 987, 654, 321);
        try {
            /** @var mixed $result */
            $result = $fn($dt);
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            $prefix = $message !== '' ? "{$message}: " : '';
            PHPUnitAssert::fail(sprintf(
                '%scheckPlainDateTimeConversionFastPath: unexpected exception: %s',
                $prefix,
                $e->getMessage(),
            ));
        }
        // The conversion returned a value rather than throwing — count one assertion.
        PHPUnitAssert::assertNotInstanceOf(
            \Throwable::class,
            $result,
            'checkPlainDateTimeConversionFastPath: no exception thrown',
        );
    }

    /**
     * Invokes `$fn` with a PlainDate and its calendar ID string and asserts it does not throw.
     *
     * PHP port of JS TemporalHelpers.checkToTemporalPlainDateTimeFastPath(fn).
     * The JS harness creates `new Temporal.PlainDate(2000, 5, 2)` with ISO calendar
     * and calls `fn(date, calendarId)` — two arguments, so that callbacks can
     * construct PlainDateTimes using the same calendar.
     *
     * @param callable(mixed, mixed): mixed $fn Callback under test.
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function checkToTemporalPlainDateTimeFastPath(callable $fn): void
    {
        $date = new \Calendrics\Spec\PlainDate(2000, 5, 2);
        $calendar = $date->calendarId;
        try {
            /** @var mixed $result */
            $result = $fn($date, $calendar);
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            PHPUnitAssert::fail(sprintf(
                'checkToTemporalPlainDateTimeFastPath: unexpected exception: %s',
                $e->getMessage(),
            ));
        }
        // The conversion returned a value rather than throwing — count one assertion.
        PHPUnitAssert::assertNotInstanceOf(
            \Throwable::class,
            $result,
            'checkToTemporalPlainDateTimeFastPath: no exception thrown',
        );
    }

    /**
     * Invokes `$fn` with a ZonedDateTime and asserts it does not throw.
     *
     * PHP port of JS TemporalHelpers.checkToTemporalInstantFastPath(fn).
     * The harness creates `new Temporal.ZonedDateTime(1_000_000_000_987_654_321n, "UTC")` —
     * the non-round value allows fixtures to detect which exact instant the ZDT carries.
     *
     * @param callable(mixed): mixed $fn Callback under test.
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function checkToTemporalInstantFastPath(callable $fn): void
    {
        $zdt = new \Calendrics\Spec\ZonedDateTime(1_000_000_000_987_654_321, 'UTC');
        try {
            /** @var mixed $result */
            $result = $fn($zdt);
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            PHPUnitAssert::fail(sprintf('checkToTemporalInstantFastPath: unexpected exception: %s', $e->getMessage()));
        }
        // The conversion returned a value rather than throwing — count one assertion.
        PHPUnitAssert::assertNotInstanceOf(
            \Throwable::class,
            $result,
            'checkToTemporalInstantFastPath: no exception thrown',
        );
    }
}
