<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;
use Temporal\Spec\Duration;
use Temporal\Spec\Internal\Calendar\CalendarFactory;
use Temporal\Spec\ZonedDateTime;

use function assert;

/**
 * The engine behind `ZonedDateTime::since()` and `ZonedDateTime::until()`.
 *
 * Differencing two zoned date-times is the one operation on the class where all three of
 * its coordinate systems meet at once, which is why it does not fit beside the value
 * type: the answer is measured in calendar units against the LOCAL date, in elapsed time
 * against the EPOCH, and the conversion factor between them — the length of a day — is a
 * property of the ZONE that varies across DST transitions. TC39 spells this out as
 * DifferenceZonedDateTime → DifferenceISODateTime → NudgeToCalendarUnit; the layout here
 * follows that order:
 *
 *   1. Resolve and cross-validate the four rounding options (largestUnit, smallestUnit,
 *      roundingMode, roundingIncrement).
 *   2. Sign the interval so the rest of the computation runs earlier → later, and negate
 *      directional rounding modes when the OUTPUT is negative.
 *   3. For a calendar largestUnit: diff the local dates, then re-measure the time
 *      remainder by replaying the date portion through the zone, so a DST-shortened day
 *      is not silently counted as 24 hours.
 *   4. Round at the smallest unit — as calendar progress through a real interval when
 *      that unit is a calendar one, otherwise as nanoseconds.
 *
 * This class lives in `Temporal\Spec\Internal\` and is therefore not part of the public
 * BC contract. Signatures, behavior, and existence may change between any two releases.
 * External code must not depend on it.
 */
final class ZonedDateTimeDiff
{
    /**
     * Computes the signed Duration between two ZonedDateTimes.
     *
     * TC39 CalendarDateUntil is always called as (temporalDate, other); for "since" the
     * final result is negated rather than the arguments swapped, so both operations round
     * against the same interval.
     *
     * @param string $operation 'since' or 'until'
     * @param mixed $options
     */
    public static function between(
        ZonedDateTime $temporalDate,
        ZonedDateTime $other,
        string $operation,
        mixed $options,
    ): Duration {
        /** @var list<string> $validUnits */
        static $validUnits = [
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
        /** @var array<string, int> $unitRank */
        static $unitRank = [
            'year' => 8,
            'years' => 8,
            'month' => 7,
            'months' => 7,
            'week' => 6,
            'weeks' => 6,
            'day' => 5,
            'days' => 5,
            'auto' => 4,
            'hour' => 4,
            'hours' => 4,
            'minute' => 3,
            'minutes' => 3,
            'second' => 2,
            'seconds' => 2,
            'millisecond' => 1,
            'milliseconds' => 1,
            'microsecond' => 1,
            'microseconds' => 1,
            'nanosecond' => 1,
            'nanoseconds' => 1,
        ];

        // Default for ZDT: largestUnit = 'hour' (not 'day').
        $largestUnit = 'hour';
        $largestUnitExplicit = false;
        $smallestUnit = null;
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
                    if (!in_array($lu, $validUnits, strict: true)) {
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
                    if (!in_array($su, $validUnits, strict: true)) {
                        throw new RangeError("Invalid smallestUnit value: \"{$su}\".");
                    }
                    $smallestUnit = $su;
                }
            }
        }

        if ($smallestUnit === null) {
            $smallestUnit = 'nanosecond';
        }

        $normLargest = match ($largestUnit) {
            'years' => 'year',
            'months' => 'month',
            'weeks' => 'week',
            'days' => 'day',
            'auto' => 'hour',
            'hours' => 'hour',
            'minutes' => 'minute',
            'seconds' => 'second',
            'milliseconds' => 'millisecond',
            'microseconds' => 'microsecond',
            'nanoseconds' => 'nanosecond',
            default => $largestUnit,
        };
        $normSmallest = match ($smallestUnit) {
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
            default => $smallestUnit,
        };

        /** @var array<string, int> $canonRank */
        static $canonRank = [
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
        $suRank = $canonRank[$normSmallest] ?? 1;
        $luRank = $canonRank[$normLargest] ?? 4;

        if ($suRank > $luRank) {
            if ($largestUnitExplicit) {
                throw new RangeError(
                    "smallestUnit \"{$normSmallest}\" cannot be larger than largestUnit \"{$normLargest}\".",
                );
            }
            $normLargest = $normSmallest;
        }

        // Validate roundingIncrement against smallest unit.
        if ($roundingIncrement > 1) {
            /** @var array<string, int> $maxIncrementForUnit */
            static $maxIncrementForUnit = [
                'hour' => 24,
                'minute' => 60,
                'second' => 60,
                'millisecond' => 1000,
                'microsecond' => 1000,
                'nanosecond' => 1000,
            ];
            $maxIncrement = $maxIncrementForUnit[$normSmallest] ?? 0;
            if (
                $maxIncrement > 0
                && ($roundingIncrement >= $maxIncrement || ($maxIncrement % $roundingIncrement) !== 0)
            ) {
                throw new RangeError("roundingIncrement {$roundingIncrement} is invalid for unit \"{$normSmallest}\".");
            }
        }

        // Validate that rounding increment for day units doesn't exceed the date range.
        if ($roundingIncrement > 1 && in_array($normSmallest, ['day', 'week'], strict: true)) {
            $incDays = $normSmallest === 'week' ? $roundingIncrement * 7 : $roundingIncrement;
            $maxEpochDays = 100_000_000;
            // Check both directions from the earlier/later endpoints.
            $recLocal = $temporalDate->localComponents();
            $recEpochDays =
                CalendarMath::toJulianDay($recLocal['year'], $recLocal['month'], $recLocal['day']) - 2_440_588;
            if ((abs($recEpochDays) + $incDays) > $maxEpochDays) {
                throw new RangeError(
                    "roundingIncrement {$roundingIncrement} for unit \"{$normSmallest}\" would exceed the representable date range.",
                );
            }
        }

        $isCalendarLargest = in_array($normLargest, ['year', 'month', 'week', 'day'], strict: true);

        // TC39: for calendar-largest units, require matching canonical timezones.
        if (
            $isCalendarLargest
            && TimeZoneIdentity::comparisonId($temporalDate->timeZoneId) !== TimeZoneIdentity::comparisonId($other->timeZoneId)
        ) {
            throw new RangeError(
                "Cannot compute {$operation}() with largestUnit '{$normLargest}' between different timezones.",
            );
        }

        // Epoch ns difference: other − temporalDate.
        // Positive when other > temporalDate (the "until" direction).
        $diffNs = self::diffEpochNs($temporalDate, $other);

        // Overall sign.
        $sign = $diffNs <=> 0;

        // For "since", negate the output sign per TC39 spec.
        $outputSign = $operation === 'since' ? -$sign : $sign;

        // Negate directional rounding modes for negative output durations so that
        // floor/ceil behave correctly toward -infinity/+infinity.
        $effectiveMode = $outputSign < 0 ? self::negateRoundingMode($roundingMode) : $roundingMode;

        if ($isCalendarLargest) {
            // Use local date/time fields for calendar-aware diff.
            $tdLocal = $temporalDate->localComponents();
            $otherLocal = $other->localComponents();

            // Assign earlier/later so we always diff in the positive direction.
            if ($sign >= 0) {
                $earlierLocal = $tdLocal;
                $laterLocal = $otherLocal;
            } else {
                $earlierLocal = $otherLocal;
                $laterLocal = $tdLocal;
            }

            // Date diff in JDN.
            $laterJdn = CalendarMath::toJulianDay($laterLocal['year'], $laterLocal['month'], $laterLocal['day']);
            $earlierJdn = CalendarMath::toJulianDay(
                $earlierLocal['year'],
                $earlierLocal['month'],
                $earlierLocal['day'],
            );
            $laterTimeNs =
                ($laterLocal['hour'] * 3_600_000_000_000)
                + ($laterLocal['minute'] * 60_000_000_000)
                + ($laterLocal['second'] * EpochLimits::NS_PER_SECOND)
                + ($laterLocal['millisecond'] * EpochLimits::NS_PER_MILLISECOND)
                + ($laterLocal['microsecond'] * EpochLimits::NS_PER_MICROSECOND)
                + $laterLocal['nanosecond'];
            $earlierTimeNs =
                ($earlierLocal['hour'] * 3_600_000_000_000)
                + ($earlierLocal['minute'] * 60_000_000_000)
                + ($earlierLocal['second'] * EpochLimits::NS_PER_SECOND)
                + ($earlierLocal['millisecond'] * EpochLimits::NS_PER_MILLISECOND)
                + ($earlierLocal['microsecond'] * EpochLimits::NS_PER_MICROSECOND)
                + $earlierLocal['nanosecond'];

            $dateDiff = $laterJdn - $earlierJdn;
            $timeDiffNs = $laterTimeNs - $earlierTimeNs;

            // Borrow one day if time part is negative.
            if ($timeDiffNs < 0) {
                $dateDiff--;
                $timeDiffNs += 86_400_000_000_000;
            }

            // Calendar diff. adjOtherJdn is the adjusted other date after borrow.
            $adjOtherJdn = $earlierJdn + $dateDiff;
            [$adjY2, $adjM2, $adjD2] = CalendarMath::fromJulianDay($adjOtherJdn);
            $calId = $temporalDate->calendarId;
            $tc39AdjJdn = null;

            if ($normLargest === 'day') {
                $days = $dateDiff;
                [$years, $months, $weeks] = [0, 0, 0];
            } elseif ($normLargest === 'week') {
                $weeks = intdiv(num1: $dateDiff, num2: 7);
                $days = $dateDiff - ($weeks * 7);
                [$years, $months] = [0, 0];
            } elseif ($calId !== 'iso8601') {
                // TC39 CalendarDateUntil(temporalDate, adjustedOther) — always
                // in (this, other) order. Compute adjustedOther per TC39
                // DifferenceISODateTime: only borrow when signs conflict.
                $tdJdn = CalendarMath::toJulianDay($tdLocal['year'], $tdLocal['month'], $tdLocal['day']);
                $otherJdn2 = CalendarMath::toJulianDay($otherLocal['year'], $otherLocal['month'], $otherLocal['day']);
                $rawTdTimeNs =
                    ($tdLocal['hour'] * 3_600_000_000_000)
                    + ($tdLocal['minute'] * 60_000_000_000)
                    + ($tdLocal['second'] * EpochLimits::NS_PER_SECOND)
                    + ($tdLocal['millisecond'] * EpochLimits::NS_PER_MILLISECOND)
                    + ($tdLocal['microsecond'] * EpochLimits::NS_PER_MICROSECOND)
                    + $tdLocal['nanosecond'];
                $rawOtherTimeNs =
                    ($otherLocal['hour'] * 3_600_000_000_000)
                    + ($otherLocal['minute'] * 60_000_000_000)
                    + ($otherLocal['second'] * EpochLimits::NS_PER_SECOND)
                    + ($otherLocal['millisecond'] * EpochLimits::NS_PER_MILLISECOND)
                    + ($otherLocal['microsecond'] * EpochLimits::NS_PER_MICROSECOND)
                    + $otherLocal['nanosecond'];
                $rawTD = $rawOtherTimeNs - $rawTdTimeNs;
                $tS = $rawTD <=> 0;
                $dS = $tdJdn <=> $otherJdn2;
                $tc39AdjJdn = $otherJdn2;
                if ($tS !== 0 && $tS === -$dS) {
                    $tc39AdjJdn = $otherJdn2 - $tS;
                }
                [$tc39Y, $tc39M, $tc39D] = CalendarMath::fromJulianDay($tc39AdjJdn);
                $cal = CalendarFactory::get($calId);
                [$years, $months, , $days] = $cal->dateUntil(
                    $tdLocal['year'],
                    $tdLocal['month'],
                    $tdLocal['day'],
                    $tc39Y,
                    $tc39M,
                    $tc39D,
                    $normLargest,
                );
                $years = abs($years);
                $months = abs($months);
                $days = abs($days);
                $weeks = 0;
            } else {
                // ISO calendar: calendarDiff expects (smaller, larger).
                $receiverIsLater = $sign < 0;
                [$years, $months, $days] = self::calendarDiff(
                    $earlierLocal['year'],
                    $earlierLocal['month'],
                    $earlierLocal['day'],
                    $adjY2,
                    $adjM2,
                    $adjD2,
                    $receiverIsLater,
                );
                $weeks = 0;
            }

            // Convert years to months when largestUnit is 'month'.
            if ($normLargest === 'month') {
                $months = ($years * 12) + $months;
                $years = 0;
            }

            // TC39 DifferenceZonedDateTime: recompute timeDiffNs using actual
            // epoch arithmetic when the timezone is an IANA zone (not fixed-offset).
            // This correctly handles DST transitions where wall-clock time
            // differs from elapsed time.
            $tzForRecompute = $temporalDate->timeZoneId;
            $isIanaTz = $tzForRecompute !== 'UTC' && preg_match('/^[+\-]\d{2}:\d{2}$/', $tzForRecompute) !== 1;
            if ($isIanaTz && ($years !== 0 || $months !== 0 || $weeks !== 0 || $days !== 0)) {
                // Add date portion to the earlier ZDT, measure remaining ns.
                $earlierZ = $sign >= 0 ? $temporalDate : $other;
                $laterZ = $sign >= 0 ? $other : $temporalDate;
                $intermediate = $earlierZ->add(new Duration(
                    years: $years,
                    months: $months,
                    weeks: $weeks,
                    days: $days,
                ));
                [$intSec, $intSub] = $intermediate->epochParts();
                [$latSec, $latSub] = $laterZ->epochParts();
                $recomputedNs = (($latSec - $intSec) * 1_000_000_000) + ($latSub - $intSub);
                if ($recomputedNs >= 0) {
                    $timeDiffNs = $recomputedNs;
                } elseif ($days > 0) {
                    // Negative time means the date portion overshot (DST gap at
                    // the intermediate date). Reduce days by 1 and recompute.
                    $days--;
                    $intermediate2 = $earlierZ->add(new Duration(
                        years: $years,
                        months: $months,
                        weeks: $weeks,
                        days: $days,
                    ));
                    [$intSec2, $intSub2] = $intermediate2->epochParts();
                    $recomputedNs2 = (($latSec - $intSec2) * 1_000_000_000) + ($latSub - $intSub2);
                    if ($recomputedNs2 >= 0) {
                        $timeDiffNs = $recomputedNs2;
                    }
                }
            } elseif ($isIanaTz) {
                // Same date, no date diff: use raw epoch diff for the time part.
                $absDiffNsSameDay = $sign < 0 ? -$diffNs : $diffNs;
                if ($absDiffNsSameDay >= 0) {
                    $timeDiffNs = $absDiffNsSameDay;
                }
            }

            $isSmallestCalendar = in_array($normSmallest, ['year', 'month', 'week', 'day'], strict: true);

            // The receiver (temporalDate) is the later date when sign < 0.
            $receiverIsLater = $sign < 0;

            // For rounding, determine earlier/later local components.
            if ($sign >= 0) {
                $earlierLocal = $tdLocal;
                $laterLocal = $otherLocal;
            } else {
                $earlierLocal = $otherLocal;
                $laterLocal = $tdLocal;
            }

            // For IANA timezones, compute the actual day length at the intermediate
            // date (after adding date portion). This is needed for DST-aware
            // progress computation where 24h might not equal 1 day.
            $nsPerDayF = 86_400_000_000_000.0;
            if ($isIanaTz && ($years !== 0 || $months !== 0 || $weeks !== 0 || $days !== 0)) {
                try {
                    $earlierZ3 = $sign >= 0 ? $temporalDate : $other;
                    $intermediate3 = $earlierZ3->add(new Duration(
                        years: $years,
                        months: $months,
                        weeks: $weeks,
                        days: $days,
                    ));
                    $actualHours = $intermediate3->hoursInDay;
                    if ($actualHours !== 24 && $actualHours > 0) {
                        $nsPerDayF = (float) $actualHours * 3_600_000_000_000.0;
                    }
                } catch (\Throwable $e) {
                    // Keep default 24h
                    unset($e);
                }
            }

            if ($isSmallestCalendar) {
                // Calendar-unit rounding: zero out time and round the calendar part.

                // Receiver's local components for calendar-aware rounding.
                $recLocal = $tdLocal;

                if ($normSmallest === 'year') {
                    $floorCount = intdiv(num1: $years, num2: $roundingIncrement) * $roundingIncrement;

                    $progress = self::calcYearProgress(
                        $recLocal,
                        $earlierLocal,
                        $laterLocal,
                        $floorCount,
                        $roundingIncrement,
                        $days,
                        $timeDiffNs,
                        $receiverIsLater,
                    );
                    $roundUp = CalendarMath::applyCalendarRoundingProgress(
                        $years,
                        $progress,
                        $roundingIncrement,
                        $effectiveMode,
                    );
                    $roundedYears = $roundUp ? $floorCount + $roundingIncrement : $floorCount;
                    return new Duration(years: $outputSign * $roundedYears);
                }
                if ($normSmallest === 'month') {
                    $totalMonths = ($years * 12) + $months;
                    $floorCount = intdiv(num1: $totalMonths, num2: $roundingIncrement) * $roundingIncrement;

                    $progress = self::calcMonthProgress(
                        $recLocal,
                        $earlierLocal,
                        $laterLocal,
                        $floorCount,
                        $roundingIncrement,
                        $days,
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
                        $rm = $roundedMonths - ($ry * 12);
                        return new Duration(years: $outputSign * $ry, months: $outputSign * $rm);
                    }
                    return new Duration(months: $outputSign * $roundedMonths);
                }
                if ($normSmallest === 'week') {
                    $totalDays = ($weeks * 7) + $days;
                    $progress = $timeDiffNs > 0 ? (float) $timeDiffNs / $nsPerDayF : 0.0;
                    $weekDays = $totalDays;
                    $weekIncrement = $roundingIncrement * 7;
                    $roundUp = CalendarMath::applyCalendarRoundingProgress(
                        $weekDays,
                        $progress,
                        $weekIncrement,
                        $effectiveMode,
                    );
                    $q = intdiv(num1: $weekDays, num2: $weekIncrement);
                    $roundedDays = $roundUp ? ($q + 1) * $weekIncrement : $q * $weekIncrement;
                    // Preserve the years/months from the date difference. Per TC39
                    // NudgeToCalendarUnit (unit=week), the years+months portion is held fixed
                    // (AdjustDateDurationRecord(duration.[[Date]], 0, 0)) and only the
                    // weeks+days remainder is rounded. With largestUnit=month/year these can be
                    // nonzero; dropping them lost a whole month (e.g. P1M weeks..months → 0).
                    // For largestUnit=week they are already 0, so this is a no-op there.
                    return new Duration(
                        years: $outputSign * $years,
                        months: $outputSign * $months,
                        weeks: $outputSign * intdiv(num1: $roundedDays, num2: 7),
                    );
                }
                // normSmallest === 'day'
                $progress = $timeDiffNs > 0 ? (float) $timeDiffNs / $nsPerDayF : 0.0;
                $roundUp = CalendarMath::applyCalendarRoundingProgress(
                    $days,
                    $progress,
                    $roundingIncrement,
                    $effectiveMode,
                );
                $q = intdiv(num1: $days, num2: $roundingIncrement);
                $roundedDays = $roundUp ? ($q + 1) * $roundingIncrement : $q * $roundingIncrement;
                if ($normLargest === 'day') {
                    return new Duration(days: $outputSign * $roundedDays);
                }
                if ($normLargest === 'week') {
                    $totalDays = ($weeks * 7) + $roundedDays;
                    $roundedWeeks = intdiv(num1: $totalDays, num2: 7);
                    $remDays = $totalDays - ($roundedWeeks * 7);
                    return new Duration(weeks: $outputSign * $roundedWeeks, days: $outputSign * $remDays);
                }
                return new Duration(
                    years: $outputSign * $years,
                    months: $outputSign * $months,
                    days: $outputSign * $roundedDays,
                );
            }

            // smallestUnit is a time unit but largestUnit is a calendar unit.
            $absTimeNs = $timeDiffNs;
            $nsPerSmallest = match ($normSmallest) {
                'hour' => 3_600_000_000_000,
                'minute' => 60_000_000_000,
                'second' => EpochLimits::NS_PER_SECOND,
                'millisecond' => EpochLimits::NS_PER_MILLISECOND,
                'microsecond' => EpochLimits::NS_PER_MICROSECOND,
                default => 1,
            };
            /** @psalm-var int<1, 1000> $roundingIncrement */
            $nsIncrement = $nsPerSmallest * $roundingIncrement;
            $absTimeNs = EpochRounding::roundAsIfPositive($absTimeNs, $nsIncrement, $effectiveMode);

            // Handle day overflow from rounding time.
            // Use DST-aware day length for IANA timezones.
            $nsPerDayForOverflow = (int) $nsPerDayF;
            $overflowDays = intdiv(num1: $absTimeNs, num2: $nsPerDayForOverflow);
            $absTimeNs %= $nsPerDayForOverflow;
            $days += $overflowDays;

            // Re-balance calendar units when day overflow pushes past month boundaries.
            if ($overflowDays > 0 && in_array($normLargest, ['year', 'month'], strict: true)) {
                if ($calId !== 'iso8601') {
                    // Non-ISO: shift tc39AdjJdn by overflow in the diff direction.
                    // $tc39AdjJdn was assigned in the earlier non-ISO branch above.
                    assert($tc39AdjJdn !== null, description: 'non-ISO branch above must have defined $tc39AdjJdn');
                    $tc39Jdn2 = $tc39AdjJdn + ($sign >= 0 ? $overflowDays : -$overflowDays);
                    [$anchorY, $anchorM, $anchorD] = CalendarMath::fromJulianDay($tc39Jdn2);
                    $cal2 = CalendarFactory::get($calId);
                    [$years, $months, , $days] = $cal2->dateUntil(
                        $tdLocal['year'],
                        $tdLocal['month'],
                        $tdLocal['day'],
                        $anchorY,
                        $anchorM,
                        $anchorD,
                        $normLargest,
                    );
                    $years = abs($years);
                    $months = abs($months);
                    $days = abs($days);
                } else {
                    // ISO: use swap-based adjOtherJdn + overflow.
                    $isoAdjJdn2 = $adjOtherJdn + $overflowDays;
                    [$anchorY, $anchorM, $anchorD] = CalendarMath::fromJulianDay($isoAdjJdn2);
                    [$years, $months, $days] = self::calendarDiff(
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
                    $months = ($years * 12) + $months;
                    $years = 0;
                }
                $weeks = 0;
            }

            $h = intdiv(num1: $absTimeNs, num2: 3_600_000_000_000);
            $rem = $absTimeNs % 3_600_000_000_000;
            $min = intdiv(num1: $rem, num2: 60_000_000_000);
            $rem %= 60_000_000_000;
            $sec = intdiv(num1: $rem, num2: EpochLimits::NS_PER_SECOND);
            $rem %= EpochLimits::NS_PER_SECOND;
            $msR = intdiv(num1: $rem, num2: EpochLimits::NS_PER_MILLISECOND);
            $rem %= EpochLimits::NS_PER_MILLISECOND;
            $usR = intdiv(num1: $rem, num2: EpochLimits::NS_PER_MICROSECOND);
            $nsR = $rem % EpochLimits::NS_PER_MICROSECOND;

            return new Duration(
                years: $outputSign * $years,
                months: $outputSign * $months,
                weeks: $outputSign * $weeks,
                days: $outputSign * $days,
                hours: $outputSign * $h,
                minutes: $outputSign * $min,
                seconds: $outputSign * $sec,
                milliseconds: $outputSign * $msR,
                microseconds: $outputSign * $usR,
                nanoseconds: $outputSign * $nsR,
            );
        }

        // Time-only units: hybrid (sec, subNs) decomposition. Avoids the int64
        // overflow that would otherwise occur for spans approaching the spec's
        // representable range (~±275,760 years from epoch). diffEpochNs returns
        // a PHP_INT_MIN/MAX sentinel for those spans, and `-PHP_INT_MIN` overflows
        // to float; sourcing the diff directly from (sec, subNs) sidesteps both.
        [$tdSec, $tdSubNs] = $temporalDate->epochParts();
        [$otherSec, $otherSubNs] = $other->epochParts();
        $absDiffSec = $sign < 0 ? $tdSec - $otherSec : $otherSec - $tdSec;
        $absDiffSubNs = $sign < 0 ? $tdSubNs - $otherSubNs : $otherSubNs - $tdSubNs;
        // Borrow if subNs is negative.
        if ($absDiffSubNs < 0) {
            $absDiffSec--;
            $absDiffSubNs += EpochLimits::NS_PER_SECOND;
        }
        // Now: $absDiffSec >= 0 and 0 <= $absDiffSubNs < NS_PER_SECOND. Both fit
        // int64 since |epochSec| < 8.64×10¹² (the spec range).

        $nsPerSmallest = match ($normSmallest) {
            'hour' => 3_600_000_000_000,
            'minute' => 60_000_000_000,
            'second' => EpochLimits::NS_PER_SECOND,
            'millisecond' => EpochLimits::NS_PER_MILLISECOND,
            'microsecond' => EpochLimits::NS_PER_MICROSECOND,
            default => 1,
        };
        /** @psalm-var int<1, 1000> $roundingIncrement */
        $nsIncrement = $nsPerSmallest * $roundingIncrement;

        // Round the non-negative (absDiffSec, absDiffSubNs) pair by nsIncrement. The
        // shared helper dispatches both regimes internally: a strictly sub-second
        // increment rounds only the sub-second remainder (carrying into seconds), while
        // a second-or-coarser increment rounds in the seconds domain so the combined
        // nanosecond value never has to fit int64. Inputs are pre-absoluted above
        // (absDiffSec ≥ 0, absDiffSubNs in [0, 1e9)), matching the helper's contract.
        [$absDiffSec, $absDiffSubNs] = EpochRounding::round($absDiffSec, $absDiffSubNs, $nsIncrement, $effectiveMode);

        /** @var array<string,int> $timeUnitRank */
        static $timeUnitRank = [
            'hour' => 6,
            'minute' => 5,
            'second' => 4,
            'millisecond' => 3,
            'microsecond' => 2,
            'nanosecond' => 1,
        ];
        $luTimeRank = $timeUnitRank[$normLargest] ?? 6;

        // Decompose (absDiffSec, absDiffSubNs) into time units. The seconds part
        // covers hour/minute/second; the sub-second part covers ms/µs/ns. Both
        // halves stay in int64 throughout.
        $h = $luTimeRank >= 6 ? intdiv(num1: $absDiffSec, num2: 3_600) : 0;
        $remSec = $luTimeRank >= 6 ? $absDiffSec % 3_600 : $absDiffSec;
        $min = $luTimeRank >= 5 ? intdiv(num1: $remSec, num2: 60) : 0;
        $remSec = $luTimeRank >= 5 ? $remSec % 60 : $remSec;
        $sec = $luTimeRank >= 4 ? $remSec : 0;
        $rem = $luTimeRank >= 4 ? $absDiffSubNs : ($remSec * EpochLimits::NS_PER_SECOND) + $absDiffSubNs;
        $msR = $luTimeRank >= 3 ? intdiv(num1: $rem, num2: EpochLimits::NS_PER_MILLISECOND) : 0;
        $rem = $luTimeRank >= 3 ? $rem % EpochLimits::NS_PER_MILLISECOND : $rem;
        $usR = $luTimeRank >= 2 ? intdiv(num1: $rem, num2: EpochLimits::NS_PER_MICROSECOND) : 0;
        $nsR = $luTimeRank >= 2 ? $rem % EpochLimits::NS_PER_MICROSECOND : $rem;

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
     * Calendar-aware year/month/day breakdown between two dates.
     *
     * @param int<1, 12> $m1
     * @param int<1, 12> $m2
     * @return array{0: int, 1: int, 2: int} [years, months, days] — all non-negative.
     */
    private static function calendarDiff(
        int $y1,
        int $m1,
        int $d1,
        int $y2,
        int $m2,
        int $d2,
        bool $receiverIsY2 = true,
    ): array {
        $sign = $y2 > $y1 || $y2 === $y1 && ($m2 > $m1 || $m2 === $m1 && $d2 >= $d1) ? 1 : -1;

        $receiverIsY2AfterSwap = $receiverIsY2;

        if ($sign < 0) {
            [$y1, $m1, $d1, $y2, $m2, $d2] = [$y2, $m2, $d2, $y1, $m1, $d1];
            $receiverIsY2AfterSwap = !$receiverIsY2;
        }

        $years = $y2 - $y1;
        $months = $m2 - $m1;

        if ($months < 0) {
            $years--;
            $months += 12;
        }

        if ($d2 < $d1) {
            if ($months > 0) {
                $months--;
            } else {
                $years--;
                $months = 11;
            }
        }

        if ($receiverIsY2AfterSwap) {
            $anchorMonth = $m2 - $months;
            $anchorYear = $y2 - $years;
            if ($anchorMonth <= 0) {
                $anchorYear--;
                $anchorMonth += 12;
            }
            $anchorMaxDay = CalendarMath::calcDaysInMonth($anchorYear, $anchorMonth);
            $anchorDay = min($d2, $anchorMaxDay);
            $days =
                CalendarMath::toJulianDay($anchorYear, $anchorMonth, $anchorDay)
                - CalendarMath::toJulianDay($y1, $m1, $d1);
        } else {
            $anchorMonth = $m1 + $months;
            $anchorYear = $y1 + $years;
            if ($anchorMonth > 12) {
                $anchorYear++;
                $anchorMonth -= 12;
            }
            $anchorMaxDay = CalendarMath::calcDaysInMonth($anchorYear, $anchorMonth);
            $anchorDay = min($d1, $anchorMaxDay);
            $days =
                CalendarMath::toJulianDay($y2, $m2, $d2)
                - CalendarMath::toJulianDay($anchorYear, $anchorMonth, $anchorDay);
        }

        return [$sign * $years, $sign * $months, $sign * $days];
    }

    /**
     * Computes the fractional progress for year-level rounding using actual calendar dates.
     *
     * Adds floorYears to the receiver date, then floorYears+increment to get
     * the true interval length, and measures how far the remainder extends.
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
        int $days,
        int $timeDiffNs,
        bool $receiverIsLater,
    ): float {
        $nsPerDayF = 86_400_000_000_000.0;
        if ($receiverIsLater) {
            // Anchor from the later date backward.
            $floorDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                -$floorCount,
                0,
            );
            $nextDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                -($floorCount + $increment),
                0,
            );
            // Remaining: from earlier to the floor anchor.
            $floorJdn = CalendarMath::toJulianDay($floorDate[0], $floorDate[1], $floorDate[2]);
            $earlierJdn = CalendarMath::toJulianDay(
                $earlierLocal['year'],
                $earlierLocal['month'],
                $earlierLocal['day'],
            );
            $remDays = $floorJdn - $earlierJdn;
        } else {
            // Anchor from the earlier date forward.
            $floorDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                $floorCount,
                0,
            );
            $nextDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                $floorCount + $increment,
                0,
            );
            // Remaining: from the floor anchor to the later date.
            $floorJdn = CalendarMath::toJulianDay($floorDate[0], $floorDate[1], $floorDate[2]);
            $laterJdn = CalendarMath::toJulianDay($laterLocal['year'], $laterLocal['month'], $laterLocal['day']);
            $remDays = $laterJdn - $floorJdn;
        }
        $nextJdn = CalendarMath::toJulianDay($nextDate[0], $nextDate[1], $nextDate[2]);
        $intervalDays = abs($nextJdn - $floorJdn);

        $totalRemNs = (float) (($remDays * 86_400_000_000_000) + $timeDiffNs);
        return $intervalDays > 0 ? $totalRemNs / ((float) $intervalDays * $nsPerDayF) : 0.0;
    }

    /**
     * Computes the fractional progress for month-level rounding using actual calendar dates.
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
        int $days,
        int $timeDiffNs,
        bool $receiverIsLater,
    ): float {
        $nsPerDayF = 86_400_000_000_000.0;
        if ($receiverIsLater) {
            $floorDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                0,
                -$floorCount,
            );
            $nextDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                0,
                -($floorCount + $increment),
            );
            $floorJdn = CalendarMath::toJulianDay($floorDate[0], $floorDate[1], $floorDate[2]);
            $earlierJdn = CalendarMath::toJulianDay(
                $earlierLocal['year'],
                $earlierLocal['month'],
                $earlierLocal['day'],
            );
            $remDays = $floorJdn - $earlierJdn;
        } else {
            $floorDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                0,
                $floorCount,
            );
            $nextDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                0,
                $floorCount + $increment,
            );
            $floorJdn = CalendarMath::toJulianDay($floorDate[0], $floorDate[1], $floorDate[2]);
            $laterJdn = CalendarMath::toJulianDay($laterLocal['year'], $laterLocal['month'], $laterLocal['day']);
            $remDays = $laterJdn - $floorJdn;
        }
        $nextJdn = CalendarMath::toJulianDay($nextDate[0], $nextDate[1], $nextDate[2]);
        $intervalDays = abs($nextJdn - $floorJdn);

        $totalRemNs = (float) (($remDays * 86_400_000_000_000) + $timeDiffNs);
        return $intervalDays > 0 ? $totalRemNs / ((float) $intervalDays * $nsPerDayF) : 0.0;
    }

    /**
     * Adds years and months to a date, clamping the day to the new month's max.
     *
     * @return array{0:int, 1:int, 2:int} [year, month, day]
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
        $maxDay = CalendarMath::calcDaysInMonth($newYear, $newMonth);
        return [$newYear, $newMonth, min($day, $maxDay)];
    }

    /**
     * Negates directional rounding modes for use on absolute values of negative durations.
     *
     * Symmetric modes (trunc, expand, halfTrunc, halfExpand, halfEven) are unchanged.
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
     * Computes the signed nanosecond difference ($b - $a) using true epoch parts.
     *
     * When both values fit in int64, uses plain arithmetic. Falls back to
     * seconds + sub-ns decomposition to avoid int overflow for proleptic dates.
     *
     * @return int Nanosecond difference (may still overflow for spans > ~292 years,
     *             but calendar-largest paths only use this for sign detection).
     */
    private static function diffEpochNs(ZonedDateTime $a, ZonedDateTime $b): int
    {
        [$aSec, $aSubNs] = $a->epochParts();
        [$bSec, $bSubNs] = $b->epochParts();
        $diffSec = $bSec - $aSec;
        $diffSubNs = $bSubNs - $aSubNs;
        // Safe multiplication check: |diffSec| * 1e9 fits int64 when |diffSec| < ~9.2e9
        $maxSafeSecDiff = 9_000_000_000;
        if ($diffSec > $maxSafeSecDiff || $diffSec < -$maxSafeSecDiff) {
            // Return a large sentinel value preserving sign; callers that need
            // the calendar path only use this for sign, not magnitude.
            return $diffSec > 0 ? PHP_INT_MAX : PHP_INT_MIN;
        }
        return ($diffSec * EpochLimits::NS_PER_SECOND) + $diffSubNs;
    }
}
