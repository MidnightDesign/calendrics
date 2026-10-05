<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Duration;
use Calendrics\Instant;
use Calendrics\PlainDate;
use Calendrics\PlainDateTime;
use Calendrics\PlainMonthDay;
use Calendrics\PlainTime;
use Calendrics\PlainYearMonth;
use Calendrics\ZonedDateTime;
use PHPUnit\Framework\Attributes\DataProvider;

final class SerializationTest extends CalendricsTestCase
{
    /**
     * These cases exercise PHP's Stringable/JsonSerializable integration with
     * the public parse factory. Upstream test262 owns the ISO formatting rules.
     *
     * @return iterable<string, array{\Closure(string): (\Stringable&\JsonSerializable), string}>
     */
    public static function values(): iterable
    {
        yield 'PlainDate with calendar' => [PlainDate::parse(...), '2024-03-15[u-ca=gregory]'];
        yield 'PlainDateTime with calendar and nanoseconds' => [
            PlainDateTime::parse(...),
            '2024-03-15T09:30:00.123456789[u-ca=gregory]',
        ];
        yield 'PlainTime with nanoseconds' => [PlainTime::parse(...), '09:30:00.123456789'];
        yield 'PlainYearMonth with calendar' => [PlainYearMonth::parse(...), '2024-03-01[u-ca=gregory]'];
        yield 'PlainMonthDay with calendar' => [PlainMonthDay::parse(...), '1972-12-25[u-ca=gregory]'];
        yield 'Instant with nanoseconds' => [Instant::parse(...), '2024-03-15T09:30:00.123456789Z'];
        yield 'ZonedDateTime with zone and calendar' => [
            ZonedDateTime::parse(...),
            '2024-03-15T09:30:00.123456789+01:00[Europe/Vienna][u-ca=gregory]',
        ];
        yield 'Duration with calendar and clock fields' => [Duration::parse(...), 'P1Y2M3W4DT5H6M7.123456789S'];
    }

    /** @param \Closure(string): (\Stringable&\JsonSerializable) $parse */
    #[DataProvider('values')]
    public function testPhpStringAndJsonRoundTrips(\Closure $parse, string $input): void
    {
        $value = $parse($input);
        $string = (string) $value;
        /** @var mixed $jsonValue */
        $jsonValue = json_decode(json_encode($value, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);

        static::assertIsString($jsonValue);
        static::assertSame($string, $value->jsonSerialize());
        static::assertSame($string, $jsonValue);
        static::assertEquals($value, $parse($string));
        static::assertEquals($value, $parse($jsonValue));
    }
}
