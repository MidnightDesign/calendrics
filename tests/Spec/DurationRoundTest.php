<?php

declare(strict_types=1);

namespace Temporal\Tests\Spec;

use PHPUnit\Framework\TestCase;
use Temporal\Exception\RangeError;
use Temporal\Spec\Duration;
use Temporal\Spec\ZonedDateTime;

/**
 * Rounding totals that outgrow int64.
 *
 * A valid duration reaches 9.007e24 nanoseconds — past int64, and past the range
 * where a float64 holds integers exactly. These cases pin the (seconds, sub-second
 * nanoseconds) split that carries such totals through rounding, and the single
 * float64 narrowing at the end that the spec does prescribe. The test262 corpus
 * does not reach here: its precision fixtures all stay inside int64.
 */
final class DurationRoundTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Zoned anchors past the int64 nanosecond ceiling
    // -------------------------------------------------------------------------

    public function testLargeDayCountWithZonedStringAnchorStaysPositive(): void
    {
        // 150 000 days is 1.296e19 ns — past int64. 2020-01-01 is EST but the day
        // 150 000 days later is EDT, so the span is one hour short of 3 600 000 h.
        $rounded = Duration::from('P150000D')->round([
            'largestUnit' => 'hours',
            'relativeTo' => '2020-01-01T00:00[America/New_York]',
        ]);

        static::assertSame(3_599_999, $rounded->hours);
        static::assertSame('PT3599999H', $rounded->toString());
    }

    public function testLargeDayCountWithZonedPropertyBagAnchorStaysPositive(): void
    {
        $rounded = Duration::from('P100000DT5H')->round([
            'largestUnit' => 'hours',
            'relativeTo' => ['year' => 2020, 'month' => 1, 'day' => 1, 'timeZone' => 'America/New_York'],
        ]);

        static::assertSame(2_400_004, $rounded->hours);
        static::assertSame('PT2400004H', $rounded->toString());
    }

    public function testLargeDayCountWithZonedDateTimeAnchorStaysPositive(): void
    {
        $rounded = Duration::from('P100000DT5H')->round([
            'largestUnit' => 'hours',
            'relativeTo' => ZonedDateTime::from('2020-01-01T00:00[America/New_York]'),
        ]);

        static::assertSame(2_400_004, $rounded->hours);
        static::assertSame('PT2400004H', $rounded->toString());
    }

    public function testNegativeAnchoredRoundingCountsTheRealOffsetChange(): void
    {
        // 150 000 days before 2020 lands in New York's LMT era (-04:56:02), so the
        // span is 3 min 58 s longer than a whole number of hours from EST.
        $rounded = Duration::from('-P150000D')->round([
            'largestUnit' => 'hours',
            'relativeTo' => '2020-01-01T00:00[America/New_York]',
        ]);

        static::assertSame('-PT3600000H3M58S', $rounded->toString());
    }

    // -------------------------------------------------------------------------
    // Rounding leaves nothing below smallestUnit
    // -------------------------------------------------------------------------

    public function testRoundingToHoursLeavesNoSubHourResidue(): void
    {
        $rounded = Duration::from('P200000000DT30M0.0005S')->round('hours');

        static::assertSame('P200000000DT1H', $rounded->toString());
        static::assertSame(0, $rounded->minutes);
        static::assertSame(0, $rounded->seconds);
        static::assertSame(0, $rounded->milliseconds);
        static::assertSame(0, $rounded->microseconds);
        static::assertSame(0, $rounded->nanoseconds);
    }

    public function testRoundingToMicrosecondsCarriesIntoTheNextDay(): void
    {
        $rounded = Duration::from('P106751DT23H59M59.999999999S')->round('microseconds');

        static::assertSame('P106752D', $rounded->toString());
    }

    // -------------------------------------------------------------------------
    // The one float64 narrowing the spec does prescribe
    // -------------------------------------------------------------------------

    public function testLargestUnitNanosecondsNarrowsToTheNearestFloat64(): void
    {
        // 9007199254740991463129087 ns sits between two float64s that are 2^30 apart;
        // the lower one is nearer. Scaling the seconds up a unit at a time would round
        // at every step and land on the other side.
        $rounded = Duration::from('PT9007199254740991.463129087S')->round(['largestUnit' => 'nanoseconds']);

        static::assertSame(9_007_199_254_740_990_926_258_176.0, $rounded->nanoseconds);
        static::assertSame('PT9007199254740990.926258176S', $rounded->toString());
    }

    public function testLargestUnitMicrosecondsNarrowsToTheNearestFloat64(): void
    {
        $rounded = Duration::from('PT9007199254740991.463129088S')->round(['largestUnit' => 'microseconds']);

        static::assertSame(9_007_199_254_740_990_951_424.0, $rounded->microseconds);
        static::assertSame(88, $rounded->nanoseconds);
    }

    public function testLargestUnitMillisecondsNarrowsToTheNearestFloat64(): void
    {
        // A millisecond total still fits int64, but not 2^53 — so it is exact all the
        // way to the field and only narrows on the way in.
        $rounded = Duration::from('PT9007199254740991.463129088S')->round(['largestUnit' => 'milliseconds']);

        static::assertSame(9_007_199_254_740_990_976.0, $rounded->milliseconds);
        static::assertSame(129, $rounded->microseconds);
        static::assertSame(88, $rounded->nanoseconds);
    }

    public function testNarrowingUpPastMaxTimeDurationThrows(): void
    {
        // One nanosecond more than the case above, and the nearest float64 is the
        // upper neighbour — which is past MaxTimeDuration even though the exact
        // total is not.
        $this->expectException(RangeError::class);

        Duration::from('PT9007199254740991.463129088S')->round(['largestUnit' => 'nanoseconds']);
    }
}
