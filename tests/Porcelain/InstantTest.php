<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Duration;
use Calendrics\Exception\RangeError;
use Calendrics\Instant;
use Calendrics\RoundingMode;
use Calendrics\Unit;

final class InstantTest extends CalendricsTestCase
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function testConstructorWithZero(): void
    {
        $i = new Instant(0);

        static::assertSame(0, $i->epochNanoseconds);
    }

    public function testConstructorWithPositiveValue(): void
    {
        // 2020-01-01T00:00:00Z in nanoseconds
        $ns = 1_577_836_800_000_000_000;
        $i = new Instant($ns);

        static::assertSame($ns, $i->epochNanoseconds);
    }

    public function testConstructorWithNegativeValue(): void
    {
        $ns = -1_000_000_000;
        $i = new Instant($ns);

        static::assertSame($ns, $i->epochNanoseconds);
    }

    // -------------------------------------------------------------------------
    // Virtual properties
    // -------------------------------------------------------------------------

    public function testEpochNanoseconds(): void
    {
        $ns = 1_577_836_800_123_456_789;
        $i = new Instant($ns);

        static::assertSame($ns, $i->epochNanoseconds);
    }

    public function testEpochMilliseconds(): void
    {
        $ns = 1_577_836_800_123_456_789;
        $i = new Instant($ns);

        static::assertSame(1_577_836_800_123, $i->epochMilliseconds);
    }

    public function testEpochMillisecondsZero(): void
    {
        $i = new Instant(0);

        static::assertSame(0, $i->epochMilliseconds);
    }

    public function testEpochMillisecondsNegative(): void
    {
        // -1.5 seconds => floor(-1_500_000_000 / 1_000_000) = -1500
        $i = new Instant(-1_500_000_000);

        static::assertSame(-1500, $i->epochMilliseconds);
    }

    // -------------------------------------------------------------------------
    // parse()
    // -------------------------------------------------------------------------

    public function testParseUtcZ(): void
    {
        $i = Instant::parse('1970-01-01T00:00:00Z');

        static::assertSame(0, $i->epochNanoseconds);
    }

    public function testParseWithOffset(): void
    {
        // 2020-01-01T00:00:00+00:00 = epoch 1577836800 seconds
        $i = Instant::parse('2020-01-01T00:00:00+00:00');

        static::assertSame(1_577_836_800_000_000_000, $i->epochNanoseconds);
    }

    public function testParseWithFractionalSeconds(): void
    {
        $i = Instant::parse('1970-01-01T00:00:01.500Z');

        static::assertSame(1_500_000_000, $i->epochNanoseconds);
    }

    public function testParseWithNanoseconds(): void
    {
        $i = Instant::parse('1970-01-01T00:00:00.123456789Z');

        static::assertSame(123_456_789, $i->epochNanoseconds);
    }

    public function testParseInvalidStringThrows(): void
    {
        $this->expectException(RangeError::class);

        Instant::parse('not-an-instant');
    }

    public function testParseEmptyStringThrows(): void
    {
        $this->expectException(RangeError::class);

        Instant::parse('');
    }

    // -------------------------------------------------------------------------
    // Static factories
    // -------------------------------------------------------------------------

    public function testFromEpochMilliseconds(): void
    {
        $i = Instant::fromEpochMilliseconds(1_577_836_800_000);

        static::assertSame(1_577_836_800_000_000_000, $i->epochNanoseconds);
        static::assertSame(1_577_836_800_000, $i->epochMilliseconds);
    }

    public function testFromEpochMillisecondsZero(): void
    {
        $i = Instant::fromEpochMilliseconds(0);

        static::assertSame(0, $i->epochNanoseconds);
    }

    public function testFromEpochMillisecondsNegative(): void
    {
        $i = Instant::fromEpochMilliseconds(-1000);

        static::assertSame(-1_000_000_000, $i->epochNanoseconds);
    }

    public function testFromEpochNanoseconds(): void
    {
        $ns = 1_577_836_800_123_456_789;
        $i = Instant::fromEpochNanoseconds($ns);

        static::assertSame($ns, $i->epochNanoseconds);
    }

    public function testFromEpochNanosecondsZero(): void
    {
        $i = Instant::fromEpochNanoseconds(0);

        static::assertSame(0, $i->epochNanoseconds);
    }

    // -------------------------------------------------------------------------
    // compare()
    // -------------------------------------------------------------------------

    public function testCompareEqual(): void
    {
        $a = new Instant(1_000_000_000);
        $b = new Instant(1_000_000_000);

        static::assertSame(0, Instant::compare($a, $b));
    }

    public function testCompareLess(): void
    {
        $a = new Instant(1_000_000_000);
        $b = new Instant(2_000_000_000);

        static::assertSame(-1, Instant::compare($a, $b));
    }

    public function testCompareGreater(): void
    {
        $a = new Instant(2_000_000_000);
        $b = new Instant(1_000_000_000);

        static::assertSame(1, Instant::compare($a, $b));
    }

    // -------------------------------------------------------------------------
    // equals()
    // -------------------------------------------------------------------------

    public function testEqualsTrue(): void
    {
        $a = new Instant(1_577_836_800_000_000_000);
        $b = new Instant(1_577_836_800_000_000_000);

        static::assertTrue($a->equals($b));
    }

    public function testEqualsFalse(): void
    {
        $a = new Instant(1_577_836_800_000_000_000);
        $b = new Instant(1_577_836_800_000_000_001);

        static::assertFalse($a->equals($b));
    }

    // -------------------------------------------------------------------------
    // add() / subtract()
    // -------------------------------------------------------------------------

    public function testAddHours(): void
    {
        $i = new Instant(0);
        $result = $i->add(new Duration(hours: 1));

        static::assertSame(3_600_000_000_000, $result->epochNanoseconds);
    }

    public function testAddMinutesAndSeconds(): void
    {
        $i = new Instant(0);
        $result = $i->add(new Duration(minutes: 30, seconds: 15));

        $expected = ((30 * 60) + 15) * 1_000_000_000;
        static::assertSame($expected, $result->epochNanoseconds);
    }

    public function testAddNanoseconds(): void
    {
        $i = new Instant(0);
        $result = $i->add(new Duration(nanoseconds: 500));

        static::assertSame(500, $result->epochNanoseconds);
    }

    public function testAddDoesNotMutateOriginal(): void
    {
        $i = new Instant(0);
        $i->add(new Duration(hours: 1));

        static::assertSame(0, $i->epochNanoseconds);
    }

    public function testAddWithCalendarFieldsThrows(): void
    {
        $i = new Instant(0);

        $this->expectException(RangeError::class);

        $i->add(new Duration(days: 1));
    }

    public function testSubtractHours(): void
    {
        $i = new Instant(7_200_000_000_000);
        $result = $i->subtract(new Duration(hours: 1));

        static::assertSame(3_600_000_000_000, $result->epochNanoseconds);
    }

    public function testSubtractToNegative(): void
    {
        $i = new Instant(0);
        $result = $i->subtract(new Duration(seconds: 1));

        static::assertSame(-1_000_000_000, $result->epochNanoseconds);
    }

    public function testSubtractWithCalendarFieldsThrows(): void
    {
        $i = new Instant(0);

        $this->expectException(RangeError::class);

        $i->subtract(new Duration(months: 1));
    }

    // -------------------------------------------------------------------------
    // round()
    // -------------------------------------------------------------------------

    public function testRoundToSecond(): void
    {
        // 1.6 seconds -> round halfExpand -> 2 seconds
        $i = new Instant(1_600_000_000);
        $result = $i->round(Unit::Second);

        static::assertSame(2_000_000_000, $result->epochNanoseconds);
    }

    public function testRoundToSecondTrunc(): void
    {
        // 1.6 seconds -> trunc -> 1 second
        $i = new Instant(1_600_000_000);
        $result = $i->round(Unit::Second, RoundingMode::Trunc);

        static::assertSame(1_000_000_000, $result->epochNanoseconds);
    }

    public function testRoundToMinute(): void
    {
        // 90 seconds -> round halfExpand -> 2 minutes
        $i = new Instant(90_000_000_000);
        $result = $i->round(Unit::Minute);

        static::assertSame(120_000_000_000, $result->epochNanoseconds);
    }

    public function testRoundToMillisecond(): void
    {
        $i = new Instant(1_500_500);
        $result = $i->round(Unit::Millisecond);

        static::assertSame(2_000_000, $result->epochNanoseconds);
    }

    public function testRoundWithIncrement(): void
    {
        // 7 seconds rounded to increment of 5 with halfExpand = 5 seconds
        $i = new Instant(7_000_000_000);
        $result = $i->round(Unit::Second, RoundingMode::HalfExpand, 5);

        static::assertSame(5_000_000_000, $result->epochNanoseconds);
    }

    public function testRoundCeil(): void
    {
        // 1.001 seconds -> ceil to second -> 2 seconds
        $i = new Instant(1_001_000_000);
        $result = $i->round(Unit::Second, RoundingMode::Ceil);

        static::assertSame(2_000_000_000, $result->epochNanoseconds);
    }

    // -------------------------------------------------------------------------
    // since() / until()
    // -------------------------------------------------------------------------

    public function testSinceBasic(): void
    {
        $a = new Instant(0);
        $b = new Instant(3_600_000_000_000);
        $d = $b->since($a);

        static::assertSame(3600, $d->seconds);
    }

    public function testSinceNegative(): void
    {
        $a = new Instant(3_600_000_000_000);
        $b = new Instant(0);
        $d = $b->since($a);

        static::assertSame(-3600, $d->seconds);
    }

    public function testSinceWithLargestUnit(): void
    {
        $a = new Instant(0);
        $b = new Instant(7_200_000_000_000);
        $d = $b->since($a, largestUnit: Unit::Hour);

        static::assertSame(2, $d->hours);
        static::assertSame(0, $d->seconds);
    }

    public function testSinceWithSmallestUnit(): void
    {
        $a = new Instant(0);
        $b = new Instant(1_500_000_000);
        $d = $b->since($a, smallestUnit: Unit::Second);

        static::assertSame(1, $d->seconds);
        static::assertSame(0, $d->nanoseconds);
    }

    public function testSinceWithRoundingMode(): void
    {
        $a = new Instant(0);
        $b = new Instant(1_600_000_000);
        $d = $b->since($a, smallestUnit: Unit::Second, roundingMode: RoundingMode::Ceil);

        static::assertSame(2, $d->seconds);
    }

    public function testUntilBasic(): void
    {
        $a = new Instant(0);
        $b = new Instant(3_600_000_000_000);
        $d = $a->until($b);

        static::assertSame(3600, $d->seconds);
    }

    public function testUntilNegative(): void
    {
        $a = new Instant(3_600_000_000_000);
        $b = new Instant(0);
        $d = $a->until($b);

        static::assertSame(-3600, $d->seconds);
    }

    public function testUntilWithLargestUnit(): void
    {
        $a = new Instant(0);
        $b = new Instant(7_200_000_000_000);
        $d = $a->until($b, largestUnit: Unit::Hour);

        static::assertSame(2, $d->hours);
    }

    // -------------------------------------------------------------------------
    // toZonedDateTime()
    // -------------------------------------------------------------------------

    public function testToZonedDateTimeUtc(): void
    {
        $i = Instant::parse('2020-01-01T00:00:00Z');
        $zdt = $i->toZonedDateTime('UTC');

        static::assertSame('UTC', $zdt->timeZoneId);
        static::assertSame(2020, $zdt->year);
        static::assertSame(1, $zdt->month);
        static::assertSame(1, $zdt->day);
        static::assertSame(0, $zdt->hour);
    }

    public function testToZonedDateTimeWithPositiveOffset(): void
    {
        // 2020-01-01T00:00:00Z displayed in +05:30 is 2020-01-01T05:30:00
        $i = Instant::parse('2020-01-01T00:00:00Z');
        $zdt = $i->toZonedDateTime('+05:30');

        static::assertSame('+05:30', $zdt->timeZoneId);
        static::assertSame(5, $zdt->hour);
        static::assertSame(30, $zdt->minute);
    }

    public function testToZonedDateTimeWithNegativeOffset(): void
    {
        // 2020-01-01T00:00:00Z displayed in -05:00 is 2019-12-31T19:00:00
        $i = Instant::parse('2020-01-01T00:00:00Z');
        $zdt = $i->toZonedDateTime('-05:00');

        static::assertSame('-05:00', $zdt->timeZoneId);
        static::assertSame(19, $zdt->hour);
        static::assertSame(31, $zdt->day);
    }

    public function testToZonedDateTimePreservesEpochNanoseconds(): void
    {
        $ns = 1_577_836_800_123_456_789;
        $i = new Instant($ns);
        $zdt = $i->toZonedDateTime('UTC');

        static::assertSame($ns, $zdt->epochNanoseconds);
    }

    // -------------------------------------------------------------------------
    // toString()
    // -------------------------------------------------------------------------

    public function testToStringDefault(): void
    {
        $i = Instant::parse('2020-01-01T00:00:00Z');

        static::assertSame('2020-01-01T00:00:00Z', $i->toString());
    }

    public function testToStringWithFractionalSecondDigits(): void
    {
        $i = Instant::parse('2020-01-01T00:00:00.100Z');

        static::assertSame('2020-01-01T00:00:00.100Z', $i->toString(fractionalSecondDigits: 3));
    }

    public function testToStringWithFractionalSecondDigitsZero(): void
    {
        $i = Instant::parse('2020-01-01T00:00:00.500Z');

        static::assertSame('2020-01-01T00:00:00Z', $i->toString(fractionalSecondDigits: 0));
    }

    public function testToStringWithSmallestUnit(): void
    {
        $i = Instant::parse('2020-01-01T00:00:30.123Z');

        // smallestUnit: minute produces HH:MM format (no seconds)
        static::assertSame('2020-01-01T00:00Z', $i->toString(smallestUnit: Unit::Minute));
    }

    public function testToStringWithRoundingMode(): void
    {
        $i = Instant::parse('2020-01-01T00:00:00.600Z');

        static::assertSame('2020-01-01T00:00:01Z', $i->toString(
            fractionalSecondDigits: 0,
            roundingMode: RoundingMode::Ceil,
        ));
    }

    public function testToStringWithTimeZone(): void
    {
        $i = Instant::parse('2020-01-01T00:00:00Z');

        static::assertSame('2020-01-01T05:30:00+05:30', $i->toString(timeZone: '+05:30'));
    }

    public function testToStringWithIanaTimeZone(): void
    {
        $i = Instant::parse('1970-01-01T00:00:00Z');

        static::assertSame('1969-12-31T19:00:00-05:00', $i->toString(timeZone: 'America/New_York'));
    }

    public function testToStringWithBracketIanaTimeZone(): void
    {
        $i = Instant::parse('1970-01-01T00:00:00Z');

        static::assertSame(
            '1969-12-31T19:00:00-05:00',
            $i->toString(timeZone: '1970-01-01T00:00+00:00[America/New_York]'),
        );
    }

    public function testToStringWithUnknownIanaTimeZoneThrows(): void
    {
        // A well-formed but unrecognized IANA name must be a RangeError, not a
        // silent fall back to UTC — matching toZonedDateTime()'s behavior.
        $i = Instant::parse('1970-01-01T00:00:00Z');

        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('Invalid timeZone "Bogus/Zone"');
        $i->toString(timeZone: 'Bogus/Zone');
    }

    public function testToStringWithUnknownBracketTimeZoneThrows(): void
    {
        // A bracket annotation names the zone explicitly; an unrecognized IANA name
        // must throw rather than silently fall back to the inline offset.
        $i = Instant::parse('1970-01-01T00:00:00Z');

        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('Invalid timeZone "Bogus/Zone"');
        $i->toString(timeZone: '1970-01-01T00:00+01:00[Bogus/Zone]');
    }

    // -------------------------------------------------------------------------
    // __toString() / jsonSerialize()
    // -------------------------------------------------------------------------

    public function testMagicToString(): void
    {
        $i = Instant::parse('2020-01-01T00:00:00Z');

        static::assertSame('2020-01-01T00:00:00Z', (string) $i);
    }

    public function testJsonSerialize(): void
    {
        $i = Instant::parse('2020-01-01T00:00:00Z');

        static::assertSame('"2020-01-01T00:00:00Z"', json_encode($i));
    }

    // -------------------------------------------------------------------------
    // toSpec() / fromSpec()
    // -------------------------------------------------------------------------

    public function testToSpecReturnsSpecInstant(): void
    {
        $ns = 1_577_836_800_123_456_789;
        $i = new Instant($ns);
        $spec = $i->toSpec();

        static::assertSame($ns, $spec->epochNanoseconds);
    }

    public function testFromSpecRoundTrip(): void
    {
        $ns = 1_577_836_800_123_456_789;
        $i = new Instant($ns);
        $restored = Instant::fromSpec($i->toSpec());

        static::assertTrue($i->equals($restored));
    }

    public function testFromSpecCreatesCorrectInstance(): void
    {
        $spec = new \Calendrics\Spec\Instant(42_000_000_000);
        $i = Instant::fromSpec($spec);

        static::assertSame(42_000_000_000, $i->epochNanoseconds);
        static::assertSame(42_000, $spec->epochMilliseconds);
    }

    // -------------------------------------------------------------------------
    // __debugInfo()
    // -------------------------------------------------------------------------

    public function testDebugInfoContainsExpectedKeys(): void
    {
        $i = Instant::parse('2020-01-01T00:00:00Z');
        $info = $i->__debugInfo();

        static::assertArrayHasKey('epochNanoseconds', $info);
        static::assertArrayHasKey('epochMilliseconds', $info);
        static::assertArrayHasKey('string', $info);
        static::assertSame(1_577_836_800_000_000_000, $info['epochNanoseconds']);
        static::assertSame(1_577_836_800_000, $info['epochMilliseconds']);
        static::assertSame('2020-01-01T00:00:00Z', $info['string']);
    }

    // -------------------------------------------------------------------------
    // parse() round-trip
    // -------------------------------------------------------------------------

    public function testParseRoundTrip(): void
    {
        $iso = '2020-06-15T12:30:45.123456789Z';
        $i = Instant::parse($iso);

        static::assertSame($iso, $i->toString(fractionalSecondDigits: 9));
    }

    public function testParseRoundTripDefault(): void
    {
        $iso = '2020-01-01T00:00:00Z';
        $i = Instant::parse($iso);

        static::assertSame($iso, (string) $i);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: until() forwards all options
    // -------------------------------------------------------------------------

    public function testUntilForwardsSmallestUnit(): void
    {
        $a = new Instant(0);
        $b = new Instant(1_500_000_000); // 1.5s
        $d = $a->until($b, smallestUnit: Unit::Second);

        static::assertSame(1, $d->seconds);
        static::assertSame(0, $d->nanoseconds);
    }

    public function testUntilForwardsRoundingMode(): void
    {
        $a = new Instant(0);
        $b = new Instant(1_600_000_000); // 1.6s
        $d = $a->until($b, smallestUnit: Unit::Second, roundingMode: RoundingMode::Ceil);

        static::assertSame(2, $d->seconds);
    }

    public function testUntilForwardsRoundingIncrement(): void
    {
        $a = new Instant(0);
        $b = new Instant(7_000_000_000); // 7s
        $d = $a->until($b, smallestUnit: Unit::Second, roundingIncrement: 5);

        // 7 seconds truncated to increment of 5 = 5
        static::assertSame(5, $d->seconds);
    }

    public function testSinceForwardsRoundingIncrement(): void
    {
        $a = new Instant(0);
        $b = new Instant(7_000_000_000); // 7s
        $d = $b->since($a, smallestUnit: Unit::Second, roundingIncrement: 5);

        static::assertSame(5, $d->seconds);
    }

    // -------------------------------------------------------------------------
    // fromDateTime() / toDateTime()
    // -------------------------------------------------------------------------

    public function testFromDateTimeRoundTripUtc(): void
    {
        $dt = new \DateTimeImmutable('2020-06-15T12:30:45.123456', new \DateTimeZone('UTC'));
        $i = Instant::fromDateTime($dt);

        static::assertSame(1_592_224_245_123_456_000, $i->epochNanoseconds);

        $back = $i->toDateTime();
        static::assertSame('2020-06-15T12:30:45.123456+00:00', $back->format('Y-m-d\TH:i:s.uP'));
    }

    public function testFromDateTimeNonUtcInputCollapsesToInstant(): void
    {
        // Same instant as 2020-06-15T12:30:45.123456 UTC, but expressed in Asia/Tokyo.
        $dt = new \DateTimeImmutable('2020-06-15T21:30:45.123456', new \DateTimeZone('Asia/Tokyo'));
        $i = Instant::fromDateTime($dt);

        static::assertSame(1_592_224_245_123_456_000, $i->epochNanoseconds);
    }

    public function testToDateTimeDefaultsToUtc(): void
    {
        $i = new Instant(1_592_224_245_000_000_000);
        $dt = $i->toDateTime();

        static::assertSame('UTC', $dt->format('e'));
        static::assertSame('2020-06-15T12:30:45+00:00', $dt->format('Y-m-d\TH:i:sP'));
    }

    public function testToDateTimeAcceptsExplicitTimeZone(): void
    {
        $i = new Instant(1_592_224_245_000_000_000); // 2020-06-15T12:30:45Z
        $dt = $i->toDateTime(new \DateTimeZone('Asia/Tokyo'));

        static::assertSame('Asia/Tokyo', $dt->format('e'));
        static::assertSame('2020-06-15T21:30:45+09:00', $dt->format('Y-m-d\TH:i:sP'));
    }

    public function testFromDateTimeDropsSubMicrosecondBitsByDocumentedContract(): void
    {
        // Build an Instant with sub-microsecond precision, convert through DateTime,
        // and confirm the lowest three decimal digits are zeroed (not preserved).
        $i = new Instant(1_592_224_245_123_456_789);
        $dt = $i->toDateTime();
        $back = Instant::fromDateTime($dt);

        static::assertSame(1_592_224_245_123_456_000, $back->epochNanoseconds);
    }

    public function testToDateTimeAtEpochZero(): void
    {
        $i = new Instant(0);
        $dt = $i->toDateTime();

        static::assertSame(0, $dt->getTimestamp());
        static::assertSame('000000', $dt->format('u'));
        static::assertSame('1970-01-01T00:00:00+00:00', $dt->format('Y-m-d\TH:i:sP'));
    }

    public function testToDateTimeNegativeEpochNanosecondsRoundTripsExactly(): void
    {
        // -1.5 ms before epoch: tests that floored division puts us in
        // "1969-12-31 23:59:59.998500 UTC", not "1970-01-01 00:00:00 -.0015".
        $i = new Instant(-1_500_000);
        $dt = $i->toDateTime();

        static::assertSame('1969-12-31T23:59:59.998500+00:00', $dt->format('Y-m-d\TH:i:s.uP'));

        $back = Instant::fromDateTime($dt);
        static::assertSame(-1_500_000, $back->epochNanoseconds);
    }

    public function testToDateTimeNegativeEpochSecondsBoundary(): void
    {
        // Exactly 1 second before epoch — usOfSec is zero, so the floor-borrow
        // branch must NOT trigger. Catches an off-by-one in the if-condition.
        $i = new Instant(-1_000_000_000);
        $dt = $i->toDateTime();

        static::assertSame('1969-12-31T23:59:59.000000+00:00', $dt->format('Y-m-d\TH:i:s.uP'));
        static::assertSame(-1_000_000_000, Instant::fromDateTime($dt)->epochNanoseconds);
    }

    public function testFromDateTimeAtEpochZero(): void
    {
        $dt = new \DateTimeImmutable('1970-01-01T00:00:00', new \DateTimeZone('UTC'));
        $i = Instant::fromDateTime($dt);

        static::assertSame(0, $i->epochNanoseconds);
    }

    public function testFromDateTimePreEpoch(): void
    {
        $dt = new \DateTimeImmutable('1969-12-31T23:59:59.998500', new \DateTimeZone('UTC'));
        $i = Instant::fromDateTime($dt);

        static::assertSame(-1_500_000, $i->epochNanoseconds);
    }

    public function testFromDateTimeFarFutureBeyondInt64Throws(): void
    {
        // PHP's int64 nanosecond range covers ~±292 years around 1970. Year 3000
        // is well past +2262 and would overflow `ts * 10^9` to float before the
        // `int` return type's TypeError fired. Surface as a range error instead.
        $dt = new \DateTimeImmutable('3000-01-01T00:00:00', new \DateTimeZone('UTC'));

        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('outside the representable int64 nanosecond range');

        Instant::fromDateTime($dt);
    }

    public function testFromDateTimeFarPastBeyondInt64Throws(): void
    {
        $dt = new \DateTimeImmutable('1500-01-01T00:00:00', new \DateTimeZone('UTC'));

        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('outside the representable int64 nanosecond range');

        Instant::fromDateTime($dt);
    }
}
