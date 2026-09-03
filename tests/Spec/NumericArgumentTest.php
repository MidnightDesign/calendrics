<?php

declare(strict_types=1);

namespace Calendrics\Tests\Spec;

use Calendrics\Exception\TypeError;
use Calendrics\Spec\Duration;
use Calendrics\Spec\Instant;
use Calendrics\Spec\ZonedDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Numeric arguments in the shapes the transpiled corpus cannot express.
 *
 * The spec layer stands in for JS's two numeric types with PHP's: a BigInt is an int,
 * a Number is a float. That mapping is only partly reachable from test262. A Number
 * where a BigInt is required transpiles (see `Instant/argument.js`), but no upstream
 * fixture passes one to the ZonedDateTime constructor; and an over-int64 epoch is
 * supplied as a decimal string, a spelling JS has no need of, so only strings longer
 * than int64 ever reach the string path.
 */
final class NumericArgumentTest extends TestCase
{
    public function testZonedDateTimeConstructorRejectsANumberEpoch(): void
    {
        // TC39 converts epochNanoseconds with ToBigInt, and ToBigInt(Number) is a TypeError.
        $this->expectException(TypeError::class);

        new ZonedDateTime(1.0, 'UTC');
    }

    public function testEpochMillisecondsAcceptsAnIntegralFloat(): void
    {
        $instant = Instant::fromEpochMilliseconds(1000.0);

        static::assertSame(1000, $instant->epochMilliseconds);
    }

    public function testDurationArithmeticKeepsAFloatFieldExact(): void
    {
        $sum = new Duration(nanoseconds: 1500.0)->add(new Duration());

        static::assertSame('PT0.0000015S', $sum->toString());
    }

    /** @return iterable<string, array{string, string}> */
    public static function shortEpochNanosecondStrings(): iterable
    {
        yield 'zero' => ['0', '1970-01-01T00:00:00Z'];
        yield 'sub-second' => ['123', '1970-01-01T00:00:00.000000123Z'];
        yield 'whole negative second' => ['-1000000000', '1969-12-31T23:59:59Z'];
    }

    #[DataProvider('shortEpochNanosecondStrings')]
    public function testInstantConstructorAcceptsAShortDecimalString(string $epoch, string $expected): void
    {
        $instant = new Instant($epoch);

        static::assertSame($expected, $instant->toString());
    }
}
