<?php

declare(strict_types=1);

namespace Calendrics\Tests\Spec;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Duration;
use Calendrics\Spec\Instant;
use Calendrics\Spec\PlainDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Property-bag field and option values that arrive as something other than a number.
 *
 * TC39 drives the equivalent conversions through `{ valueOf() {…} }` observers, which
 * the transpiler cannot reproduce (the spec layer reads bags with `get_object_vars()`,
 * so no PHP object can intercept the read). The transpiled corpus therefore never puts
 * a string, bool, `\Stringable` or array in a field position, leaving the spec layer's
 * own coercion rules — documented on `CalendarMath::toFiniteInt()` and
 * `Duration::from()` — reachable only from PHP.
 */
final class InputCoercionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Duration property-bag fields
    // -------------------------------------------------------------------------

    /** @return iterable<string, array{mixed, int}> */
    public static function acceptedDurationFieldValues(): iterable
    {
        yield 'numeric string' => ['5', 5];
        yield 'stringable' => [new StringableValue('5'), 5];
        yield 'integral float' => [5.0, 5];
    }

    #[DataProvider('acceptedDurationFieldValues')]
    public function testDurationFieldValueIsCoercedToANumber(mixed $value, int $expected): void
    {
        $duration = Duration::from(['hours' => $value]);

        static::assertSame($expected, $duration->hours);
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejectedDurationFieldValues(): iterable
    {
        yield 'non-numeric string' => ['abc'];
        yield 'bool' => [true];
        yield 'array' => [[]];
        yield 'plain object' => [new stdClass()];
    }

    #[DataProvider('rejectedDurationFieldValues')]
    public function testDurationFieldValueThatIsNotANumberIsOutOfRange(mixed $value): void
    {
        $this->expectException(RangeError::class);

        Duration::from(['hours' => $value]);
    }

    // -------------------------------------------------------------------------
    // Calendar property-bag fields
    // -------------------------------------------------------------------------

    /** @return iterable<string, array{mixed}> */
    public static function acceptedYearValues(): iterable
    {
        yield 'numeric string' => ['2024'];
        yield 'stringable' => [new StringableValue('2024')];
    }

    #[DataProvider('acceptedYearValues')]
    public function testYearFieldIsCoercedToAnInteger(mixed $year): void
    {
        $date = PlainDate::from(['year' => $year, 'month' => 1, 'day' => 1]);

        static::assertSame('2024-01-01', $date->toString());
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejectedYearValues(): iterable
    {
        yield 'non-numeric string' => ['abc'];
        yield 'string overflowing to infinity' => ['1e999'];
        yield 'non-numeric stringable' => [new StringableValue('x')];
        yield 'stringable overflowing to infinity' => [new StringableValue('1e999')];
        yield 'array' => [[]];
        yield 'plain object' => [new stdClass()];
    }

    #[DataProvider('rejectedYearValues')]
    public function testYearFieldThatIsNotANumberIsOutOfRange(mixed $year): void
    {
        $this->expectException(RangeError::class);

        PlainDate::from(['year' => $year, 'month' => 1, 'day' => 1]);
    }

    public function testEraFieldIsCoercedToAString(): void
    {
        $date = PlainDate::from([
            'era' => new StringableValue('ce'),
            'eraYear' => 2024,
            'month' => 1,
            'day' => 1,
            'calendar' => 'gregory',
        ]);

        static::assertSame('2024-01-01[u-ca=gregory]', $date->toString());
    }

    public function testEraFieldCoercedFromAScalarIsValidatedAsTheResultingString(): void
    {
        // (string) true is "1", which is not an era of the gregorian calendar.
        $this->expectException(RangeError::class);

        PlainDate::from(['era' => true, 'eraYear' => 1, 'month' => 1, 'day' => 1, 'calendar' => 'gregory']);
    }

    public function testEraFieldThatCannotBecomeAStringIsATypeError(): void
    {
        $this->expectException(TypeError::class);

        PlainDate::from(['era' => [], 'eraYear' => 1, 'month' => 1, 'day' => 1, 'calendar' => 'gregory']);
    }

    // -------------------------------------------------------------------------
    // Option values
    // -------------------------------------------------------------------------

    public function testRoundingIncrementIsCoercedFromAStringable(): void
    {
        $difference = new PlainDate(2024, 1, 1)->until(new PlainDate(2024, 2, 1), [
            'smallestUnit' => 'days',
            'roundingIncrement' => new StringableValue('2'),
        ]);

        static::assertSame('P30D', $difference->toString());
    }

    public function testRoundingIncrementThatIsNotANumberIsOutOfRange(): void
    {
        $this->expectException(RangeError::class);

        new PlainDate(2024, 1, 1)->until(new PlainDate(2024, 2, 1), ['roundingIncrement' => new stdClass()]);
    }

    public function testDurationRoundingIncrementThatIsNotANumberIsOutOfRange(): void
    {
        $this->expectException(RangeError::class);

        Duration::from('PT1H')->round(['smallestUnit' => 'minutes', 'roundingIncrement' => []]);
    }

    public function testFractionalSecondDigitsIsCoercedFromAStringable(): void
    {
        $formatted = Instant::fromEpochMilliseconds(0)->toString([
            'fractionalSecondDigits' => new StringableValue('auto'),
        ]);

        static::assertSame('1970-01-01T00:00:00Z', $formatted);
    }

    // -------------------------------------------------------------------------
    // Objects in a value position
    // -------------------------------------------------------------------------

    public function testDurationWithRejectsATemporalObject(): void
    {
        $this->expectException(TypeError::class);

        new Duration(hours: 1)->with(new PlainDate(2024, 1, 1));
    }

    public function testInstantEqualsRejectsAnUnrelatedObject(): void
    {
        $this->expectException(TypeError::class);

        Instant::fromEpochMilliseconds(0)->equals(new stdClass());
    }
}
