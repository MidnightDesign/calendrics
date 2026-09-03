<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Duration;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\ZonedDateTime;

/**
 * The `since()` / `until()` engine for `ZonedDateTime`.
 *
 * Measuring the gap between two zoned instants splits into two problems that share almost
 * nothing, and which branch runs is decided by `largestUnit`:
 *
 *   - **Time-only** (`hour` and below, the default). The answer is elapsed nanoseconds,
 *     read straight off the two epochs. DST is irrelevant: an hour is an hour.
 *   - **Calendar** (`day` and above). The answer is a wall-clock quantity, so it is
 *     computed on local date fields and then *re-measured* against real elapsed time —
 *     add the date portion back to the earlier instant and see where it lands. That
 *     round-trip is what makes "one day later" mean the same clock time tomorrow even
 *     when tomorrow is 23 hours away, and it is why the calendar branch requires both
 *     values to be in the same zone.
 *
 * Rounding is layered on top and is itself two-sided: a calendar `smallestUnit` rounds by
 * *fractional progress through the current unit*, which needs the true length of that
 * unit — the interval between two real calendar anchors, not a nominal 30 days (see
 * {@see calcYearProgress()} / {@see calcMonthProgress()}). A time `smallestUnit` rounds
 * the nanosecond remainder, with the day it may overflow into taken from the actual
 * length of that local day.
 *
 * Throughout, the difference is computed in the positive direction and the sign is
 * applied last; `since()` flips it. Directional rounding modes are mirrored to match
 * ({@see negateRoundingMode()}), so `floor` keeps meaning "toward −∞" on a negative
 * result rather than "toward zero".
 *
 * @internal
 */
final class ZonedDifference
{
    /** @var list<string> */
    private const array VALID_UNITS = [
        'auto',
        'day',
        'days',
        'week',
        'weeks',
        'month',
        'months',
        'year',
        'years',
        'hour',
        'hours',
        'minute',
        'minutes',
        'second',
        'seconds',
        'millisecond',
        'milliseconds',
        'microsecond',
        'microseconds',
        'nanosecond',
        'nanoseconds',
    ];

    /** @var array<string, int> */
    private const array UNIT_RANK = [
        'year' => 10,
        'month' => 9,
        'week' => 8,
        'day' => 7,
        'hour' => 6,
        'minute' => 5,
        'second' => 4,
        'millisecond' => 3,
        'microsecond' => 2,
        'nanosecond' => 1,
    ];

    /** Largest increment permitted for each time unit — the size of the next unit up. */
    private const array MAX_INCREMENT_FOR_UNIT = [
        'hour' => 24,
        'minute' => 60,
        'second' => 60,
        'millisecond' => 1000,
        'microsecond' => 1000,
        'nanosecond' => 1000,
    ];

    private const int NS_PER_HOUR = 3_600_000_000_000;
    private const int NS_PER_MINUTE = 60_000_000_000;
    private const int NS_PER_DAY = 86_400_000_000_000;
    private const float NS_PER_DAY_F = 86_400_000_000_000.0;

    /**
     * Computes the Duration between $temporalDate and $other.
     *
     * TC39 always calls CalendarDateUntil as (receiver, other); `since()` negates the
     * result rather than swapping the operands, which is why $operation is carried
     * through instead of the caller pre-ordering the pair.
     *
     * @param string $operation 'since' or 'until'.
     * @param array<array-key, mixed>|object|null $options
     * @throws RangeError for invalid units, invalid rounding increments, or a calendar
     *                    `largestUnit` across two different zones.
     */
    public static function between(
        ZonedDateTime $temporalDate,
        ZonedDateTime $other,
        string $operation,
        array|object|null $options,
    ): Duration {
        [$normLargest, $normSmallest, $roundingMode, $roundingIncrement] = self::resolveOptions(
            $temporalDate,
            $options,
        );

        $isCalendarLargest = in_array($normLargest, ['year', 'month', 'week', 'day'], strict: true);

        if (
            $isCalendarLargest
            && ZoneOffsets::comparisonKey($temporalDate->timeZoneId) !== ZoneOffsets::comparisonKey($other->timeZoneId)
        ) {
            throw new RangeError(
                "Cannot compute {$operation}() with largestUnit '{$normLargest}' between different timezones.",
            );
        }

        // Positive when $other is later — the "until" direction.
        $diffNs = self::diffEpochNs($temporalDate, $other);
        $sign = $diffNs <=> 0;
        $outputSign = $operation === 'since' ? -$sign : $sign;
        $effectiveMode = $outputSign < 0 ? self::negateRoundingMode($roundingMode) : $roundingMode;

        if (!$isCalendarLargest) {
            return self::timeOnlyDifference(
                $temporalDate,
                $other,
                $sign,
                $outputSign,
                $normLargest,
                $normSmallest,
                roundingMode: $effectiveMode,
                roundingIncrement: $roundingIncrement,
            );
        }

        return self::calendarDifference(
            $temporalDate,
            $other,
            $sign,
            $outputSign,
            $diffNs,
            $normLargest,
            $normSmallest,
            $effectiveMode,
            $roundingIncrement,
        );
    }

    /**
     * Reads and cross-validates the four difference options.
     *
     * An explicit `largestUnit` smaller than `smallestUnit` is an error; an implicit one
     * (the `hour` default) silently widens instead, which is what lets
     * `until($x, ['smallestUnit' => 'day'])` work without also naming a largest unit.
     *
     * @param array<array-key, mixed>|object|null $options
     * @return array{0: string, 1: string, 2: string, 3: int} [largestUnit, smallestUnit, roundingMode, roundingIncrement]
     */
    private static function resolveOptions(ZonedDateTime $temporalDate, array|object|null $options): array
    {
        $largestUnit = 'hour';
        $largestUnitExplicit = false;
        $smallestUnit = 'nanosecond';
        $roundingMode = 'trunc';
        $roundingIncrement = 1;

        if ($options !== null) {
            $opts = Options::normalizeOptions($options, [
                'largestUnit',
                'roundingIncrement',
                'roundingMode',
                'smallestUnit',
            ]);

            if (array_key_exists('largestUnit', $opts)) {
                /** @var mixed $lu */
                $lu = $opts['largestUnit'];
                if ($lu !== null) {
                    $lu = Options::coerceEnumOption($lu, 'largestUnit');
                }
                if (is_string($lu)) {
                    if (!in_array($lu, self::VALID_UNITS, strict: true)) {
                        throw new RangeError("Invalid largestUnit value: \"{$lu}\".");
                    }
                    $largestUnit = $lu;
                    $largestUnitExplicit = true;
                }
            }

            if (array_key_exists('roundingIncrement', $opts)) {
                /** @var mixed $ri */
                $ri = $opts['roundingIncrement'];
                if ($ri !== null) {
                    $roundingIncrement = CalendarMath::validateRoundingIncrement($ri);
                }
            }

            if (array_key_exists('roundingMode', $opts)) {
                /** @var mixed $rm */
                $rm = $opts['roundingMode'];
                if ($rm !== null) {
                    $rm = Options::coerceEnumOption($rm, 'roundingMode');
                }
                if (is_string($rm)) {
                    $roundingMode = Options::roundingMode($rm);
                }
            }

            if (array_key_exists('smallestUnit', $opts)) {
                /** @var mixed $su */
                $su = $opts['smallestUnit'];
                if ($su !== null) {
                    $su = Options::coerceEnumOption($su, 'smallestUnit');
                }
                if (is_string($su)) {
                    if (!in_array($su, self::VALID_UNITS, strict: true)) {
                        throw new RangeError("Invalid smallestUnit value: \"{$su}\".");
                    }
                    $smallestUnit = $su;
                }
            }
        }

        // 'auto' is only meaningful for largestUnit, where it names this class's default.
        $normLargest = $largestUnit === 'auto' ? 'hour' : self::canonicalUnit($largestUnit);
        $normSmallest = self::canonicalUnit($smallestUnit);

        $suRank = self::UNIT_RANK[$normSmallest] ?? 1;
        $luRank = self::UNIT_RANK[$normLargest] ?? 4;
        if ($suRank > $luRank) {
            if ($largestUnitExplicit) {
                throw new RangeError(
                    "smallestUnit \"{$normSmallest}\" cannot be larger than largestUnit \"{$normLargest}\".",
                );
            }
            $normLargest = $normSmallest;
        }

        if ($roundingIncrement > 1) {
            $maxIncrement = self::MAX_INCREMENT_FOR_UNIT[$normSmallest] ?? 0;
            if (
                $maxIncrement > 0
                && ($roundingIncrement >= $maxIncrement || ($maxIncrement % $roundingIncrement) !== 0)
            ) {
                throw new RangeError("roundingIncrement {$roundingIncrement} is invalid for unit \"{$normSmallest}\".");
            }
        }

        // A day/week increment large enough to push the rounded result off the end of the
        // representable date range is rejected up front rather than at materialization.
        if ($roundingIncrement > 1 && in_array($normSmallest, ['day', 'week'], strict: true)) {
            $incDays = $normSmallest === 'week' ? $roundingIncrement * 7 : $roundingIncrement;
            $recLocal = $temporalDate->localComponents();
            $recEpochDays =
                CalendarMath::toJulianDay($recLocal['year'], $recLocal['month'], $recLocal['day']) - 2_440_588;
            if ((abs($recEpochDays) + $incDays) > 100_000_000) {
                throw new RangeError(
                    "roundingIncrement {$roundingIncrement} for unit \"{$normSmallest}\" would exceed the representable date range.",
                );
            }
        }

        return [$normLargest, $normSmallest, $roundingMode, $roundingIncrement];
    }

    /**
     * Difference in time units only — elapsed nanoseconds, no calendar involved.
     *
     * Works in (seconds, sub-nanoseconds) rather than a combined nanosecond count: a span
     * approaching the ±275 760-year limit overflows int64 nanoseconds, and negating
     * {@see diffEpochNs()}'s PHP_INT_MIN sentinel would overflow to float.
     */
    private static function timeOnlyDifference(
        ZonedDateTime $temporalDate,
        ZonedDateTime $other,
        int $sign,
        int $outputSign,
        string $normLargest,
        string $normSmallest,
        string $roundingMode,
        int $roundingIncrement,
    ): Duration {
        [$tdSec, $tdSubNs] = $temporalDate->epochParts();
        [$otherSec, $otherSubNs] = $other->epochParts();
        $absDiffSec = $sign < 0 ? $tdSec - $otherSec : $otherSec - $tdSec;
        $absDiffSubNs = $sign < 0 ? $tdSubNs - $otherSubNs : $otherSubNs - $tdSubNs;
        if ($absDiffSubNs < 0) {
            $absDiffSec--;
            $absDiffSubNs += EpochLimits::NS_PER_SECOND;
        }

        /** @psalm-var int<1, 1000> $roundingIncrement */
        $nsIncrement = self::nsPerUnit($normSmallest) * $roundingIncrement;

        // EpochRounding dispatches on the increment: a sub-second one rounds the remainder
        // and carries into seconds, a coarser one rounds in the seconds domain, so the
        // combined nanosecond value never has to fit int64.
        [$absDiffSec, $absDiffSubNs] = EpochRounding::round($absDiffSec, $absDiffSubNs, $nsIncrement, $roundingMode);

        $luRank = self::UNIT_RANK[$normLargest] ?? 6;

        $h = $luRank >= 6 ? intdiv(num1: $absDiffSec, num2: 3_600) : 0;
        $remSec = $luRank >= 6 ? $absDiffSec % 3_600 : $absDiffSec;
        $min = $luRank >= 5 ? intdiv(num1: $remSec, num2: 60) : 0;
        $remSec = $luRank >= 5 ? $remSec % 60 : $remSec;
        $sec = $luRank >= 4 ? $remSec : 0;
        $rem = $luRank >= 4 ? $absDiffSubNs : ($remSec * EpochLimits::NS_PER_SECOND) + $absDiffSubNs;
        $msR = $luRank >= 3 ? intdiv(num1: $rem, num2: EpochLimits::NS_PER_MILLISECOND) : 0;
        $rem = $luRank >= 3 ? $rem % EpochLimits::NS_PER_MILLISECOND : $rem;
        $usR = $luRank >= 2 ? intdiv(num1: $rem, num2: EpochLimits::NS_PER_MICROSECOND) : 0;
        $nsR = $luRank >= 2 ? $rem % EpochLimits::NS_PER_MICROSECOND : $rem;

        return new Duration(
            hours: $outputSign * $h,
            minutes: $outputSign * $min,
            seconds: $outputSign * $sec,
            milliseconds: $outputSign * $msR,
            microseconds: $outputSign * $usR,
            nanoseconds: $outputSign * $nsR,
        );
    }

    /**
     * Difference with a calendar `largestUnit`.
     *
     * @throws RangeError if the intermediate arithmetic leaves the representable range.
     */
    private static function calendarDifference(
        ZonedDateTime $temporalDate,
        ZonedDateTime $other,
        int $sign,
        int $outputSign,
        int $diffNs,
        string $normLargest,
        string $normSmallest,
        string $effectiveMode,
        int $roundingIncrement,
    ): Duration {
        $tdLocal = $temporalDate->localComponents();
        $otherLocal = $other->localComponents();

        // Diff in the positive direction; the receiver is the later value when sign < 0.
        $earlierLocal = $sign >= 0 ? $tdLocal : $otherLocal;
        $laterLocal = $sign >= 0 ? $otherLocal : $tdLocal;
        $receiverIsLater = $sign < 0;

        $laterJdn = CalendarMath::toJulianDay($laterLocal['year'], $laterLocal['month'], $laterLocal['day']);
        $earlierJdn = CalendarMath::toJulianDay($earlierLocal['year'], $earlierLocal['month'], $earlierLocal['day']);

        $dateDiff = $laterJdn - $earlierJdn;
        $timeDiffNs = self::timeOfDayNs($laterLocal) - self::timeOfDayNs($earlierLocal);
        if ($timeDiffNs < 0) {
            $dateDiff--;
            $timeDiffNs += self::NS_PER_DAY;
        }

        $adjOtherJdn = $earlierJdn + $dateDiff;
        [$adjY2, $adjM2, $adjD2] = CalendarMath::fromJulianDay($adjOtherJdn);
        $calId = $temporalDate->calendarId;
        $tc39AdjJdn = null;

        if ($normLargest === 'day') {
            $span = new DateSpan(days: $dateDiff);
        } elseif ($normLargest === 'week') {
            $weeks = intdiv(num1: $dateDiff, num2: 7);
            $span = new DateSpan(weeks: $weeks, days: $dateDiff - ($weeks * 7));
        } elseif ($calId !== 'iso8601') {
            // Day and week are handled above, so only these two reach a calendar.
            [$tc39AdjJdn, $span] = self::nonIsoDateDiff(
                $tdLocal,
                $otherLocal,
                $calId,
                $normLargest === 'month' ? 'month' : 'year',
            );
        } else {
            $span = self::isoDateSpan(
                $earlierLocal['year'],
                $earlierLocal['month'],
                $earlierLocal['day'],
                $adjY2,
                $adjM2,
                $adjD2,
                $receiverIsLater,
            );
        }

        if ($normLargest === 'month') {
            $span = $span->monthsOnly();
        }

        // In an IANA zone, wall-clock time and elapsed time diverge across a transition.
        // Re-measure the time remainder against real epoch arithmetic by adding the date
        // portion back to the earlier instant.
        $isIanaTz =
            $temporalDate->timeZoneId !== 'UTC' && preg_match('/^[+\-]\d{2}:\d{2}$/', $temporalDate->timeZoneId) !== 1;
        $earlierZ = $sign >= 0 ? $temporalDate : $other;

        if ($isIanaTz && !$span->isZero()) {
            [$timeDiffNs, $span] = self::remeasureTimeRemainder(
                $earlierZ,
                $sign >= 0 ? $other : $temporalDate,
                $span,
                $timeDiffNs,
            );
        } elseif ($isIanaTz) {
            // Same date on both sides: the raw epoch difference is the time part.
            $absDiffNsSameDay = $sign < 0 ? -$diffNs : $diffNs;
            if ($absDiffNsSameDay >= 0) {
                $timeDiffNs = $absDiffNsSameDay;
            }
        }

        // Rounding by fractional progress needs the true length of the day the remainder
        // falls in, which is 23 or 25 hours on a transition day. Re-test the span rather
        // than reusing the check above: the re-measurement may have given a day back,
        // leaving nothing to add and no intermediate day to measure.
        $nsPerDayF = $isIanaTz && !$span->isZero()
            ? self::intermediateDayLengthNs($earlierZ, $span)
            : self::NS_PER_DAY_F;

        if (in_array($normSmallest, ['year', 'month', 'week', 'day'], strict: true)) {
            return self::roundToCalendarUnit(
                $tdLocal,
                $earlierLocal,
                $laterLocal,
                $normLargest,
                $normSmallest,
                $effectiveMode,
                $roundingIncrement,
                $outputSign,
                $receiverIsLater,
                $span,
                $timeDiffNs,
                $nsPerDayF,
            );
        }

        return self::roundToTimeUnit(
            $tdLocal,
            $earlierLocal,
            $calId,
            $normLargest,
            $normSmallest,
            $effectiveMode,
            $roundingIncrement,
            $outputSign,
            $sign,
            $adjOtherJdn,
            $tc39AdjJdn,
            $span,
            $timeDiffNs,
            $nsPerDayF,
        );
    }

    /**
     * Year/month/day breakdown for a non-ISO calendar, via the calendar protocol.
     *
     * TC39's DifferenceISODateTime borrows a day from the date portion only when the time
     * and date parts disagree in sign, which is a different adjustment from the
     * unconditional borrow the ISO path uses — hence the separate anchor computed here.
     *
     * @param array{year:int, month:int<1,12>, day:int<1,31>, hour:int<0,23>, minute:int<0,59>, second:int<0,59>, millisecond:int<0,999>, microsecond:int<0,999>, nanosecond:int<0,999>, offsetSec:int, offset:string} $tdLocal
     * @param array{year:int, month:int<1,12>, day:int<1,31>, hour:int<0,23>, minute:int<0,59>, second:int<0,59>, millisecond:int<0,999>, microsecond:int<0,999>, nanosecond:int<0,999>, offsetSec:int, offset:string} $otherLocal
     * @param 'month'|'year' $normLargest
     * @return array{0: int, 1: DateSpan} [adjustedJdn, span]
     */
    private static function nonIsoDateDiff(array $tdLocal, array $otherLocal, string $calId, string $normLargest): array
    {
        $tdJdn = CalendarMath::toJulianDay($tdLocal['year'], $tdLocal['month'], $tdLocal['day']);
        $otherJdn = CalendarMath::toJulianDay($otherLocal['year'], $otherLocal['month'], $otherLocal['day']);

        $timeSign = (self::timeOfDayNs($otherLocal) - self::timeOfDayNs($tdLocal)) <=> 0;
        $dateSign = $tdJdn <=> $otherJdn;
        $adjJdn = $timeSign !== 0 && $timeSign === -$dateSign ? $otherJdn - $timeSign : $otherJdn;

        [$adjY, $adjM, $adjD] = CalendarMath::fromJulianDay($adjJdn);
        [$years, $months, , $days] = CalendarFactory::get($calId)->dateUntil(
            $tdLocal['year'],
            $tdLocal['month'],
            $tdLocal['day'],
            $adjY,
            $adjM,
            $adjD,
            $normLargest,
        );

        return [$adjJdn, new DateSpan(years: abs($years), months: abs($months), days: abs($days))];
    }

    /**
     * Re-measures the sub-day remainder as real elapsed time.
     *
     * Adding the date portion to the earlier instant and measuring what is left is the
     * only way to get a remainder that agrees with the epoch. A negative result means the
     * date portion overshot — the intermediate date fell in a DST gap — so one day comes
     * back off and the measurement repeats.
     *
     * @return array{0: int, 1: DateSpan} [timeDiffNs, span]
     */
    private static function remeasureTimeRemainder(
        ZonedDateTime $earlierZ,
        ZonedDateTime $laterZ,
        DateSpan $span,
        int $timeDiffNs,
    ): array {
        [$latSec, $latSub] = $laterZ->epochParts();

        [$intSec, $intSub] = $earlierZ->add($span->toDuration())->epochParts();
        $recomputedNs = (($latSec - $intSec) * EpochLimits::NS_PER_SECOND) + ($latSub - $intSub);
        if ($recomputedNs >= 0) {
            return [$recomputedNs, $span];
        }
        if ($span->days <= 0) {
            return [$timeDiffNs, $span];
        }

        $span = $span->withDays($span->days - 1);
        [$intSec2, $intSub2] = $earlierZ->add($span->toDuration())->epochParts();
        $recomputedNs2 = (($latSec - $intSec2) * EpochLimits::NS_PER_SECOND) + ($latSub - $intSub2);

        return [$recomputedNs2 >= 0 ? $recomputedNs2 : $timeDiffNs, $span];
    }

    /**
     * Length in nanoseconds of the local day the sub-day remainder falls in.
     *
     * Falls back to a nominal 24 hours when the intermediate instant is not representable,
     * which only happens at the extreme ends of the range where no transition applies.
     */
    private static function intermediateDayLengthNs(ZonedDateTime $earlierZ, DateSpan $span): float
    {
        try {
            $actualHours = $earlierZ->add($span->toDuration())->hoursInDay;
            if ($actualHours !== 24 && $actualHours > 0) {
                return (float) $actualHours * 3_600_000_000_000.0;
            }
        } catch (\Throwable $e) {
            unset($e);
        }
        return self::NS_PER_DAY_F;
    }

    /**
     * Rounds to a calendar `smallestUnit`, which zeroes the time portion entirely.
     *
     * @param array{year:int, month:int<1,12>, day:int<1,31>, hour:int<0,23>, minute:int<0,59>, second:int<0,59>, millisecond:int<0,999>, microsecond:int<0,999>, nanosecond:int<0,999>, offsetSec:int, offset:string} $tdLocal
     * @param array{year:int, month:int<1,12>, day:int<1,31>, hour:int<0,23>, minute:int<0,59>, second:int<0,59>, millisecond:int<0,999>, microsecond:int<0,999>, nanosecond:int<0,999>, offsetSec:int, offset:string} $earlierLocal
     * @param array{year:int, month:int<1,12>, day:int<1,31>, hour:int<0,23>, minute:int<0,59>, second:int<0,59>, millisecond:int<0,999>, microsecond:int<0,999>, nanosecond:int<0,999>, offsetSec:int, offset:string} $laterLocal
     */
    private static function roundToCalendarUnit(
        array $tdLocal,
        array $earlierLocal,
        array $laterLocal,
        string $normLargest,
        string $normSmallest,
        string $effectiveMode,
        int $roundingIncrement,
        int $outputSign,
        bool $receiverIsLater,
        DateSpan $span,
        int $timeDiffNs,
        float $nsPerDayF,
    ): Duration {
        if ($normSmallest === 'year') {
            $floorCount = intdiv(num1: $span->years, num2: $roundingIncrement) * $roundingIncrement;
            $progress = self::calcYearProgress(
                $tdLocal,
                $earlierLocal,
                $laterLocal,
                $floorCount,
                $roundingIncrement,
                $timeDiffNs,
                $receiverIsLater,
            );
            $roundUp = CalendarMath::applyCalendarRoundingProgress(
                $span->years,
                $progress,
                $roundingIncrement,
                $effectiveMode,
            );
            return new Duration(years: $outputSign * ($roundUp ? $floorCount + $roundingIncrement : $floorCount));
        }

        if ($normSmallest === 'month') {
            $totalMonths = ($span->years * 12) + $span->months;
            $floorCount = intdiv(num1: $totalMonths, num2: $roundingIncrement) * $roundingIncrement;
            $progress = self::calcMonthProgress(
                $tdLocal,
                $earlierLocal,
                $laterLocal,
                $floorCount,
                $roundingIncrement,
                $timeDiffNs,
                $receiverIsLater,
            );
            $roundUp = CalendarMath::applyCalendarRoundingProgress(
                $totalMonths,
                $progress,
                $roundingIncrement,
                $effectiveMode,
            );
            $roundedMonths = $roundUp ? $floorCount + $roundingIncrement : $floorCount;
            if ($normLargest === 'year') {
                $ry = intdiv(num1: $roundedMonths, num2: 12);
                return new Duration(years: $outputSign * $ry, months: $outputSign * ($roundedMonths - ($ry * 12)));
            }
            return new Duration(months: $outputSign * $roundedMonths);
        }

        $progress = $timeDiffNs > 0 ? (float) $timeDiffNs / $nsPerDayF : 0.0;

        if ($normSmallest === 'week') {
            $weekDays = ($span->weeks * 7) + $span->days;
            $weekIncrement = $roundingIncrement * 7;
            $roundUp = CalendarMath::applyCalendarRoundingProgress(
                $weekDays,
                $progress,
                $weekIncrement,
                $effectiveMode,
            );
            $q = intdiv(num1: $weekDays, num2: $weekIncrement);
            $roundedDays = $roundUp ? ($q + 1) * $weekIncrement : $q * $weekIncrement;
            // NudgeToCalendarUnit holds the years+months portion fixed and rounds only the
            // weeks+days remainder. With largestUnit month/year those are nonzero, and
            // dropping them here would silently lose a whole month.
            return new Duration(
                years: $outputSign * $span->years,
                months: $outputSign * $span->months,
                weeks: $outputSign * intdiv(num1: $roundedDays, num2: 7),
            );
        }

        // $normSmallest === 'day'
        $roundUp = CalendarMath::applyCalendarRoundingProgress(
            $span->days,
            $progress,
            $roundingIncrement,
            $effectiveMode,
        );
        $q = intdiv(num1: $span->days, num2: $roundingIncrement);
        $roundedDays = $roundUp ? ($q + 1) * $roundingIncrement : $q * $roundingIncrement;

        if ($normLargest === 'day') {
            return new Duration(days: $outputSign * $roundedDays);
        }
        if ($normLargest === 'week') {
            $totalDays = ($span->weeks * 7) + $roundedDays;
            $roundedWeeks = intdiv(num1: $totalDays, num2: 7);
            return new Duration(
                weeks: $outputSign * $roundedWeeks,
                days: $outputSign * ($totalDays - ($roundedWeeks * 7)),
            );
        }
        return new Duration(
            years: $outputSign * $span->years,
            months: $outputSign * $span->months,
            days: $outputSign * $roundedDays,
        );
    }

    /**
     * Rounds a time `smallestUnit` under a calendar `largestUnit`.
     *
     * Rounding the remainder up can push it past a whole day, and that day has to be
     * folded back into the calendar portion — which, near a month boundary, means redoing
     * the date difference against a shifted anchor rather than just incrementing `days`.
     *
     * @param array{year:int, month:int<1,12>, day:int<1,31>, hour:int<0,23>, minute:int<0,59>, second:int<0,59>, millisecond:int<0,999>, microsecond:int<0,999>, nanosecond:int<0,999>, offsetSec:int, offset:string} $tdLocal
     * @param array{year:int, month:int<1,12>, day:int<1,31>, hour:int<0,23>, minute:int<0,59>, second:int<0,59>, millisecond:int<0,999>, microsecond:int<0,999>, nanosecond:int<0,999>, offsetSec:int, offset:string} $earlierLocal
     */
    private static function roundToTimeUnit(
        array $tdLocal,
        array $earlierLocal,
        string $calId,
        string $normLargest,
        string $normSmallest,
        string $effectiveMode,
        int $roundingIncrement,
        int $outputSign,
        int $sign,
        int $adjOtherJdn,
        ?int $tc39AdjJdn,
        DateSpan $span,
        int $timeDiffNs,
        float $nsPerDayF,
    ): Duration {
        /** @psalm-var int<1, 1000> $roundingIncrement */
        $nsIncrement = self::nsPerUnit($normSmallest) * $roundingIncrement;
        $absTimeNs = EpochRounding::roundAsIfPositive($timeDiffNs, $nsIncrement, $effectiveMode);

        $nsPerDayForOverflow = (int) $nsPerDayF;
        $overflowDays = intdiv(num1: $absTimeNs, num2: $nsPerDayForOverflow);
        $absTimeNs %= $nsPerDayForOverflow;
        $span = $span->withDays($span->days + $overflowDays);

        if ($overflowDays > 0 && in_array($normLargest, ['year', 'month'], strict: true)) {
            if ($calId !== 'iso8601') {
                assert($tc39AdjJdn !== null, description: 'the non-ISO date-diff branch must have set $tc39AdjJdn');
                [$anchorY, $anchorM, $anchorD] = CalendarMath::fromJulianDay(
                    $tc39AdjJdn + ($sign >= 0 ? $overflowDays : -$overflowDays),
                );
                [$years, $months, , $days] = CalendarFactory::get($calId)->dateUntil(
                    $tdLocal['year'],
                    $tdLocal['month'],
                    $tdLocal['day'],
                    $anchorY,
                    $anchorM,
                    $anchorD,
                    $normLargest,
                );
                $span = new DateSpan(years: abs($years), months: abs($months), days: abs($days));
            } else {
                [$anchorY, $anchorM, $anchorD] = CalendarMath::fromJulianDay($adjOtherJdn + $overflowDays);
                $span = self::isoDateSpan(
                    $earlierLocal['year'],
                    $earlierLocal['month'],
                    $earlierLocal['day'],
                    $anchorY,
                    $anchorM,
                    $anchorD,
                    $sign < 0,
                );
            }
            if ($normLargest === 'month') {
                $span = $span->monthsOnly();
            }
        }

        [$h, $min, $sec, $msR, $usR, $nsR] = CalendarMath::nsToTime($absTimeNs);

        return new Duration(
            years: $outputSign * $span->years,
            months: $outputSign * $span->months,
            weeks: $outputSign * $span->weeks,
            days: $outputSign * $span->days,
            hours: $outputSign * $h,
            minutes: $outputSign * $min,
            seconds: $outputSign * $sec,
            milliseconds: $outputSign * $msR,
            microseconds: $outputSign * $usR,
            nanoseconds: $outputSign * $nsR,
        );
    }

    /**
     * Year/month/day breakdown between two ISO dates, in the (smaller, larger) direction.
     *
     * Always asks for a year breakdown: both callers flatten to months themselves when
     * `largestUnit` says so, after the DST re-measurement has had its say.
     *
     * @param int<1, 12> $m1
     * @param int<1, 12> $m2
     * @param bool $receiverIsY2 True when the caller's receiver is the larger date, which
     *                           is where the day remainder then anchors.
     */
    private static function isoDateSpan(
        int $y1,
        int $m1,
        int $d1,
        int $y2,
        int $m2,
        int $d2,
        bool $receiverIsY2,
    ): DateSpan {
        [$years, $months, , $days] = CalendarFactory::get('iso8601')->dateUntil(
            $y1,
            $m1,
            $d1,
            $y2,
            $m2,
            $d2,
            'year',
            $receiverIsY2,
        );

        return new DateSpan(years: $years, months: $months, days: $days);
    }

    /**
     * Fractional progress through the current year-increment, for year-level rounding.
     *
     * A year is 365 or 366 days, so the fraction is measured against the real interval
     * between the floor anchor and the next one — both obtained by adding whole years to
     * the receiver — rather than against a nominal length.
     *
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $recLocal
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $earlierLocal
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $laterLocal
     */
    private static function calcYearProgress(
        array $recLocal,
        array $earlierLocal,
        array $laterLocal,
        int $floorCount,
        int $increment,
        int $timeDiffNs,
        bool $receiverIsLater,
    ): float {
        return self::calcProgress(
            $recLocal,
            $earlierLocal,
            $laterLocal,
            $floorCount,
            $increment,
            $timeDiffNs,
            $receiverIsLater,
            byYears: true,
        );
    }

    /**
     * Fractional progress through the current month-increment, for month-level rounding.
     *
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $recLocal
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $earlierLocal
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $laterLocal
     */
    private static function calcMonthProgress(
        array $recLocal,
        array $earlierLocal,
        array $laterLocal,
        int $floorCount,
        int $increment,
        int $timeDiffNs,
        bool $receiverIsLater,
    ): float {
        return self::calcProgress(
            $recLocal,
            $earlierLocal,
            $laterLocal,
            $floorCount,
            $increment,
            $timeDiffNs,
            $receiverIsLater,
            byYears: false,
        );
    }

    /**
     * Shared body of {@see calcYearProgress()} and {@see calcMonthProgress()}.
     *
     * Both walk the receiver by $floorCount units to get the floor anchor and by
     * $floorCount + $increment to get the next one; the interval between those two anchors
     * is the denominator, and the span from the floor anchor to the far endpoint (plus the
     * sub-day remainder) is the numerator. The receiver walks backward when it is the
     * later of the two values, so the anchors stay on the same side of the interval.
     *
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $recLocal
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $earlierLocal
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $laterLocal
     */
    private static function calcProgress(
        array $recLocal,
        array $earlierLocal,
        array $laterLocal,
        int $floorCount,
        int $increment,
        int $timeDiffNs,
        bool $receiverIsLater,
        bool $byYears,
    ): float {
        $step = $receiverIsLater ? -1 : 1;
        $floorSteps = $step * $floorCount;
        $nextSteps = $step * ($floorCount + $increment);

        $floorDate = self::addYearsMonthsToDate(
            $recLocal['year'],
            $recLocal['month'],
            $recLocal['day'],
            $byYears ? $floorSteps : 0,
            $byYears ? 0 : $floorSteps,
        );
        $nextDate = self::addYearsMonthsToDate(
            $recLocal['year'],
            $recLocal['month'],
            $recLocal['day'],
            $byYears ? $nextSteps : 0,
            $byYears ? 0 : $nextSteps,
        );

        $floorJdn = CalendarMath::toJulianDay($floorDate[0], $floorDate[1], $floorDate[2]);
        $farJdn = $receiverIsLater
            ? CalendarMath::toJulianDay($earlierLocal['year'], $earlierLocal['month'], $earlierLocal['day'])
            : CalendarMath::toJulianDay($laterLocal['year'], $laterLocal['month'], $laterLocal['day']);
        $remDays = $receiverIsLater ? $floorJdn - $farJdn : $farJdn - $floorJdn;

        $nextJdn = CalendarMath::toJulianDay($nextDate[0], $nextDate[1], $nextDate[2]);
        $intervalDays = abs($nextJdn - $floorJdn);
        if ($intervalDays === 0) {
            return 0.0;
        }

        $totalRemNs = (float) (($remDays * self::NS_PER_DAY) + $timeDiffNs);
        return $totalRemNs / ((float) $intervalDays * self::NS_PER_DAY_F);
    }

    /**
     * Adds years and months to a date, clamping the day to the resulting month's length.
     *
     * @return array{0: int, 1: int, 2: int} [year, month, day]
     */
    private static function addYearsMonthsToDate(int $year, int $month, int $day, int $addYears, int $addMonths): array
    {
        $newYear = $year + $addYears;
        $newMonth = $month + $addMonths;
        if ($newMonth > 12) {
            $newYear += intdiv(num1: $newMonth - 1, num2: 12);
            $newMonth = (($newMonth - 1) % 12) + 1;
        } elseif ($newMonth < 1) {
            $newYear += intdiv(num1: $newMonth - 12, num2: 12);
            $newMonth = (((($newMonth - 1) % 12) + 12) % 12) + 1;
        }
        return [$newYear, $newMonth, min($day, CalendarMath::calcDaysInMonth($newYear, $newMonth))];
    }

    /**
     * Signed nanosecond difference ($b − $a), from true epoch parts.
     *
     * Saturates to PHP_INT_MIN/MAX past ~292 years, which is the range beyond which the
     * product overflows int64. Callers past that point only read the sign — the calendar
     * branch takes its magnitudes from local date fields, not from here.
     */
    private static function diffEpochNs(ZonedDateTime $a, ZonedDateTime $b): int
    {
        [$aSec, $aSubNs] = $a->epochParts();
        [$bSec, $bSubNs] = $b->epochParts();
        $diffSec = $bSec - $aSec;
        $maxSafeSecDiff = 9_000_000_000;
        if ($diffSec > $maxSafeSecDiff || $diffSec < -$maxSafeSecDiff) {
            return $diffSec > 0 ? PHP_INT_MAX : PHP_INT_MIN;
        }
        return ($diffSec * EpochLimits::NS_PER_SECOND) + ($bSubNs - $aSubNs);
    }

    /**
     * Mirrors directional rounding modes so they keep their meaning once the sign is
     * reapplied to an absolute value. Symmetric modes are returned unchanged.
     */
    private static function negateRoundingMode(string $mode): string
    {
        return match ($mode) {
            'floor' => 'ceil',
            'ceil' => 'floor',
            'halfFloor' => 'halfCeil',
            'halfCeil' => 'halfFloor',
            default => $mode,
        };
    }

    /**
     * Folds a unit name to its singular spelling, the form every table here is keyed by.
     *
     * Names not in the table are returned unchanged; the caller has already rejected
     * anything outside {@see VALID_UNITS}.
     */
    private static function canonicalUnit(string $unit): string
    {
        return match ($unit) {
            'years' => 'year',
            'months' => 'month',
            'weeks' => 'week',
            'days' => 'day',
            'hours' => 'hour',
            'minutes' => 'minute',
            'seconds' => 'second',
            'milliseconds' => 'millisecond',
            'microseconds' => 'microsecond',
            'nanoseconds' => 'nanosecond',
            default => $unit,
        };
    }

    /**
     * Nanoseconds in one $unit, for the time units rounding operates on.
     */
    private static function nsPerUnit(string $unit): int
    {
        return match ($unit) {
            'hour' => self::NS_PER_HOUR,
            'minute' => self::NS_PER_MINUTE,
            'second' => EpochLimits::NS_PER_SECOND,
            'millisecond' => EpochLimits::NS_PER_MILLISECOND,
            'microsecond' => EpochLimits::NS_PER_MICROSECOND,
            default => 1,
        };
    }

    /**
     * Nanoseconds elapsed since local midnight for a local-components record.
     *
     * @param array{year:int, month:int<1,12>, day:int<1,31>, hour:int<0,23>, minute:int<0,59>, second:int<0,59>, millisecond:int<0,999>, microsecond:int<0,999>, nanosecond:int<0,999>, offsetSec:int, offset:string} $local
     */
    private static function timeOfDayNs(array $local): int
    {
        return (
            ($local['hour'] * self::NS_PER_HOUR)
            + ($local['minute'] * self::NS_PER_MINUTE)
            + ($local['second'] * EpochLimits::NS_PER_SECOND)
            + ($local['millisecond'] * EpochLimits::NS_PER_MILLISECOND)
            + ($local['microsecond'] * EpochLimits::NS_PER_MICROSECOND)
            + $local['nanosecond']
        );
    }
}
