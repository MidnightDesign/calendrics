<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Instant;
use Calendrics\ZonedDateTime;

final class EpochConversionTest extends CalendricsTestCase
{
    public function testInstantFromSpecPreservesWideEpoch(): void
    {
        $spec = \Calendrics\Spec\Instant::from('2500-01-01T00:00:00.123456789Z');
        $instant = Instant::fromSpec($spec);

        static::assertTrue($spec->equals($instant->toSpec()));
        static::assertSame($spec->toString(), (string) $instant);
    }

    public function testInstantToDateTimePreservesWideEpoch(): void
    {
        $instant = Instant::parse('2500-01-01T00:00:00.123456789Z');

        static::assertSame(
            '2500-01-01T09:00:00.123456+09:00',
            $instant->toDateTime(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d\TH:i:s.uP'),
        );
    }

    public function testInstantToDateTimeTruncatesNegativeSubmicrosecondsTowardZero(): void
    {
        static::assertSame(
            '1970-01-01T00:00:00.000000+00:00',
            new Instant(-1)
                ->toDateTime()
                ->format('Y-m-d\TH:i:s.uP'),
        );
        static::assertSame(
            '1969-12-31T23:59:59.999999+00:00',
            new Instant(-1_001)
                ->toDateTime()
                ->format('Y-m-d\TH:i:s.uP'),
        );
    }

    public function testZonedDateTimeFromSpecPreservesWideEpoch(): void
    {
        $spec = \Calendrics\Spec\ZonedDateTime::from('1500-01-01T12:00:00.123456789+05:30[+05:30][u-ca=hebrew]');
        $zoned = ZonedDateTime::fromSpec($spec);

        static::assertTrue($spec->equals($zoned->toSpec()));
        static::assertSame($spec->toString(), (string) $zoned);
    }

    public function testZonedDateTimeToDateTimePreservesWideEpoch(): void
    {
        $zoned = ZonedDateTime::parse('1500-01-01T12:00:00.123456789+05:30[+05:30]');

        static::assertSame('1500-01-01T12:00:00.123457+05:30', $zoned->toDateTime()->format('Y-m-d\TH:i:s.uP'));
    }
}
