<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\TransitionDirection;
use Calendrics\ZonedDateTime;
use PHPUnit\Framework\TestCase;

/**
 * Covers `ZonedDateTime::getTimeZoneTransition()`.
 *
 * Its own file rather than a section of ZonedDateTimeTest: these cases are about what
 * tzdb says, not about ZonedDateTime's surface, and that class is at the 140-method
 * ceiling mago.toml sets for it.
 *
 * The last two read real tzdb history, so each first asserts the premise it rests on —
 * a tzdb change should then fail on the reason rather than on the symptom.
 */
final class TimeZoneTransitionTest extends TestCase
{
    public function testGetTimeZoneTransitionNext(): void
    {
        // US Eastern has DST transitions. The method returns the next transition
        // point after the current instant.
        $zdt = ZonedDateTime::parse('2020-01-01T00:00:00-05:00[America/New_York]');
        $next = $zdt->getTimeZoneTransition(TransitionDirection::Next);

        static::assertNotNull($next);
        static::assertSame('America/New_York', $next->timeZoneId);
    }

    public function testGetTimeZoneTransitionPrevious(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-01T00:00:00-04:00[America/New_York]');
        $prev = $zdt->getTimeZoneTransition(TransitionDirection::Previous);

        static::assertNotNull($prev);
        // Previous transition from June 2020 should be March 2020 (spring forward)
        static::assertSame(2020, $prev->year);
        static::assertSame(3, $prev->month);
    }

    public function testGetTimeZoneTransitionFixedOffsetReturnsNull(): void
    {
        $zdt = new ZonedDateTime(0, '+05:30');

        static::assertNull($zdt->getTimeZoneTransition(TransitionDirection::Next));
    }

    public function testGetTimeZoneTransitionUtcReturnsNull(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC');

        static::assertNull($zdt->getTimeZoneTransition(TransitionDirection::Next));
    }

    /**
     * A tzdb rule change is only a transition if the UTC offset moves. Yukon left
     * DST in November 2020 by keeping PDT's -07:00 year-round under the name MST,
     * so from June 2020 the zone's remaining history is one entry that renames the
     * offset and never changes it — which is no next transition at all.
     */
    public function testGetTimeZoneTransitionNextIsNullWhenLaterEntriesKeepTheOffset(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-01T00:00:00-07:00[America/Whitehorse]');

        static::assertNotNull(
            $zdt->getTimeZoneTransition(TransitionDirection::Previous),
            'Whitehorse must still have offset transitions in its past, or a null "next" proves nothing',
        );
        static::assertNull($zdt->getTimeZoneTransition(TransitionDirection::Next));
    }

    /**
     * Africa/Abidjan's only offset transition is its 1912 move off local mean time.
     * Anchored on that very instant there is nothing earlier to find: "previous"
     * means strictly before, so the transition the anchor sits on does not count.
     */
    public function testGetTimeZoneTransitionPreviousIsNullOnTheZonesFirstTransition(): void
    {
        $firstTransition = -1_830_383_032_000_000_000;
        $zdt = new ZonedDateTime($firstTransition, 'Africa/Abidjan');

        static::assertSame(
            $firstTransition,
            new ZonedDateTime(
                $firstTransition - 1,
                'Africa/Abidjan',
            )->getTimeZoneTransition(TransitionDirection::Next)?->epochNanoseconds,
            'the anchor must sit exactly on the transition, or a null "previous" proves nothing',
        );
        static::assertNull($zdt->getTimeZoneTransition(TransitionDirection::Previous));
    }
}
