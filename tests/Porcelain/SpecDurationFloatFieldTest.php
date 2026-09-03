<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec-layer Duration fields are int|float, a split that TC39 does not have — there every
 * field is a Number, so 0 and 0.0 are the same value. The whole implementation tests fields
 * for emptiness with `!== 0`, which a float zero passes, so a zero that reaches a field as a
 * float has to be normalized to int on construction.
 *
 * test262 cannot reach this: its fixtures build zero Durations from integer literals.
 */
final class SpecDurationFloatFieldTest extends TestCase
{
    /** @return iterable<string, array{0: Duration}> */
    public static function zeroDurationProvider(): iterable
    {
        yield 'positive float zero' => [new Duration(seconds: 0.0)];
        yield 'negative float zero' => [new Duration(seconds: -0.0)];
        yield 'float zero calendar field' => [new Duration(years: 0.0)];
        yield 'every field a float zero' => [
            new Duration(0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0),
        ];
    }

    #[DataProvider('zeroDurationProvider')]
    public function testFloatZeroDurationIsBlank(Duration $d): void
    {
        static::assertSame(0, $d->sign);
        static::assertTrue($d->blank);
        static::assertSame('PT0S', $d->toString());
    }

    #[DataProvider('zeroDurationProvider')]
    public function testFloatZeroDurationEqualsIntegerZeroDuration(Duration $d): void
    {
        static::assertTrue($d->equals(new Duration()));
        static::assertSame(0, Duration::compare($d, new Duration()));
    }

    public function testFloatZeroFieldsAreStoredAsIntegers(): void
    {
        $d = new Duration(seconds: -0.0, years: 0.0);

        static::assertSame(0, $d->seconds);
        static::assertSame(0, $d->years);
    }

    public function testFloatZeroCalendarFieldDoesNotDemandRelativeTo(): void
    {
        $d = new Duration(days: 1, years: 0.0);
        $reference = new Duration(days: 1);

        static::assertSame(0, Duration::compare($d, $reference));
        static::assertSame($reference->total('days'), $d->total('days'));
        static::assertSame($reference->round('hours')->toString(), $d->round('hours')->toString());
    }

    public function testNonZeroFloatFieldsKeepTheirValue(): void
    {
        $d = new Duration(seconds: 1.0);

        static::assertSame(1, $d->sign);
        static::assertFalse($d->blank);
        static::assertSame('PT1S', $d->toString());
    }

    public function testNegativeFloatFieldsKeepTheirSign(): void
    {
        $d = new Duration(seconds: -1.0);

        static::assertSame(-1, $d->sign);
        static::assertSame('-PT1S', $d->toString());
    }

    public function testFloatZeroDoesNotCountTowardsTheSignCheck(): void
    {
        $d = new Duration(hours: 1, minutes: -0.0, seconds: 0.0);

        static::assertSame(1, $d->sign);
        static::assertSame('PT1H', $d->toString());
    }

    /** @return iterable<string, array{0: float}> */
    public static function nonFiniteProvider(): iterable
    {
        yield 'NAN' => [NAN];
        yield 'INF' => [INF];
        yield '-INF' => [-INF];
    }

    #[DataProvider('nonFiniteProvider')]
    public function testNonFiniteFloatFieldsStillThrow(float $value): void
    {
        $this->expectException(RangeError::class);

        new Duration(seconds: $value);
    }
}
