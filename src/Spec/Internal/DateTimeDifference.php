<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;
use Temporal\Spec\Duration;
use Temporal\Spec\Internal\Calendar\CalendarFactory;
use Temporal\Spec\PlainDateTime;

/**
 * The `since()`/`until()` engine for `PlainDateTime`.
 *
 * A date-time difference has two halves that round differently. The time half is a
 * plain integer of nanoseconds, and rounding it is ordinary arithmetic. The calendar
 * half has no fixed unit length — "one month" spans 28 to 31 days depending on where
 * it starts — so rounding to a calendar unit (TC39 NudgeToCalendarUnit) must measure
 * the actual span between the floor multiple and the next one by adding both candidate
 * durations to a real date, and express the leftover as fractional progress through
 * that span.
 *
 * The engine works on magnitudes: endpoints are swapped so the raw difference is
 * non-negative, directional rounding modes are flipped when the reported sign is
 * negative, and the sign is applied to the finished fields. Time rounding can carry
 * into the date (23:59 rounding up to a full day); when the largest unit is a calendar
 * unit that carry re-runs the calendar diff from the shifted endpoint rather than just
 * incrementing days, because the extra day may change the month or year count.
 *
 * @internal
 */
final class DateTimeDifference
{
    private function __construct() {}

    /**
     * Computes the difference between $temporalDate and $other for since()/until().
     *
     * TC39 CalendarDateUntil is always called as (temporalDate, other). For
     * "since", the final result is negated.
     *
     * @param string $operation 'since' or 'until'
     * @param array<array-key, mixed>|object|null $options ['largestUnit' => ..., 'smallestUnit' => ..., 'roundingMode' => ..., 'roundingIncrement' => ...]
     */
    public static function between(
        PlainDateTime $temporalDate,
        PlainDateTime $other,
        string $operation,
        array|object|null $options,
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
            'year' => 9,
            'years' => 9,
            'month' => 8,
            'months' => 8,
            'week' => 7,
            'weeks' => 7,
            'day' => 6,
            'days' => 6,
            'auto' => 6,
            'hour' => 5,
            'hours' => 5,
            'minute' => 4,
            'minutes' => 4,
            'second' => 3,
            'seconds' => 3,
            'millisecond' => 2,
            'milliseconds' => 2,
            'microsecond' => 1,
            'microseconds' => 1,
            'nanosecond' => 0,
            'nanoseconds' => 0,
        ];

        $largestUnit = 'day'; // default per TC39 PlainDateTime spec
        $largestUnitExplicit = false;
        $smallestUnit = null;
        $roundingMode = 'trunc';
        $roundingIncrement = 1;

        if ($options !== null) {
            $opts = Options::requireObject($options, [
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

        // Normalize plural/auto to canonical singular.
        $normLargest = match ($largestUnit) {
            'years' => 'year',
            'months' => 'month',
            'weeks' => 'week',
            'days', 'auto' => 'day',
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
            'days', 'auto' => 'day',
            'hours' => 'hour',
            'minutes' => 'minute',
            'seconds' => 'second',
            'milliseconds' => 'millisecond',
            'microseconds' => 'microsecond',
            'nanoseconds' => 'nanosecond',
            default => $smallestUnit,
        };

        $suRank = $unitRank[$normSmallest];
        $luRank = $unitRank[$normLargest];

        if ($suRank > $luRank) {
            if ($largestUnitExplicit) {
                throw new RangeError(
                    "smallestUnit \"{$normSmallest}\" cannot be larger than largestUnit \"{$normLargest}\".",
                );
            }
            $normLargest = $normSmallest;
            $luRank = $suRank;
        }

        // Validate roundingIncrement for time units: must divide evenly into next higher unit.
        if ($roundingIncrement > 1) {
            /** @var array<string, int> $maxIncrementMap */
            static $maxIncrementMap = [
                'hour' => 24,
                'minute' => 60,
                'second' => 60,
                'millisecond' => 1000,
                'microsecond' => 1000,
                'nanosecond' => 1000,
            ];
            $maxInc = $maxIncrementMap[$normSmallest] ?? 0;
            if ($maxInc > 0 && ($roundingIncrement >= $maxInc || ($maxInc % $roundingIncrement) !== 0)) {
                throw new RangeError(
                    "roundingIncrement {$roundingIncrement} does not divide evenly into the next highest unit for \"{$normSmallest}\".",
                );
            }
        }

        // Compute the raw date and time differences: other − temporalDate.
        // Positive when other > temporalDate (the "until" direction).
        $tdJdn = CalendarMath::toJulianDay($temporalDate->isoYear, $temporalDate->isoMonth, $temporalDate->isoDay);
        $otherJdn = CalendarMath::toJulianDay($other->isoYear, $other->isoMonth, $other->isoDay);
        $tdNs = TimeOfDay::toNs(
            $temporalDate->hour,
            $temporalDate->minute,
            $temporalDate->second,
            $temporalDate->millisecond,
            $temporalDate->microsecond,
            $temporalDate->nanosecond,
        );
        $otherNs = TimeOfDay::toNs(
            $other->hour,
            $other->minute,
            $other->second,
            $other->millisecond,
            $other->microsecond,
            $other->nanosecond,
        );

        $dateDiff = $otherJdn - $tdJdn;
        $timeDiffNs = $otherNs - $tdNs;

        // The overall sign is determined by the combined date+time diff.
        $sign = 0;
        if ($dateDiff > 0 || $dateDiff === 0 && $timeDiffNs > 0) {
            $sign = 1;
        } elseif ($dateDiff < 0 || $timeDiffNs < 0) {
            $sign = -1;
        }

        // For "since", negate the output sign per TC39 spec.
        $outputSign = $operation === 'since' ? -$sign : $sign;

        // Work in the positive direction; assign earlier/later.
        if ($sign >= 0) {
            $earlier = $temporalDate;
            $later = $other;
        } else {
            $earlier = $other;
            $later = $temporalDate;
        }
        $earlierJdn = CalendarMath::toJulianDay($earlier->isoYear, $earlier->isoMonth, $earlier->isoDay);
        $dateDiff = CalendarMath::toJulianDay($later->isoYear, $later->isoMonth, $later->isoDay) - $earlierJdn;
        $timeDiffNs =
            TimeOfDay::toNs(
                $later->hour,
                $later->minute,
                $later->second,
                $later->millisecond,
                $later->microsecond,
                $later->nanosecond,
            )
            - TimeOfDay::toNs(
                $earlier->hour,
                $earlier->minute,
                $earlier->second,
                $earlier->millisecond,
                $earlier->microsecond,
                $earlier->nanosecond,
            );

        // Borrow one day from the date component when the time part is negative.
        if ($timeDiffNs < 0) {
            $dateDiff--;
            $timeDiffNs += TimeOfDay::NS_PER_DAY;
        }
        // Both $dateDiff and $timeDiffNs are now non-negative.

        $isCalendarLargest = $luRank >= 6; // day or above

        if ($isCalendarLargest) {
            // The adjusted other date after borrowing: earlierJdn + dateDiff.
            $adjOtherJdn = $earlierJdn + $dateDiff;
            [$adjY2, $adjM2, $adjD2] = CalendarMath::fromJulianDay($adjOtherJdn);
            $calId = $temporalDate->calendarId;
            $nonIsoAdjJdn = 0;

            if ($normLargest === 'day') {
                $days = $dateDiff;
                [$years, $months, $weeks] = [0, 0, 0];
            } elseif ($normLargest === 'week') {
                $weeks = intdiv(num1: $dateDiff, num2: 7);
                $days = $dateDiff - ($weeks * 7);
                [$years, $months] = [0, 0];
            } else {
                if ($calId !== 'iso8601') {
                    // For non-ISO calendars, use CalendarDateUntil(temporalDate,
                    // adjustedOther) in (this, other) order per TC39 spec.
                    // Compute the adjusted other JDN by borrowing from the date
                    // component when the time difference and date difference have
                    // different signs.
                    $rawDateDiff = $otherJdn - $tdJdn;
                    $rawTimeDiff = $otherNs - $tdNs;
                    $nonIsoAdjJdn = $otherJdn;
                    if ($rawDateDiff !== 0 && $rawTimeDiff !== 0) {
                        $dateSign = $rawDateDiff > 0 ? 1 : -1;
                        $timeSign = $rawTimeDiff > 0 ? 1 : -1;
                        if ($dateSign !== $timeSign) {
                            // Borrow one day in the direction of the date diff.
                            $nonIsoAdjJdn = $otherJdn - $dateSign;
                        }
                    }
                    [$adjY2b, $adjM2b, $adjD2b] = CalendarMath::fromJulianDay($nonIsoAdjJdn);
                    $cal = CalendarFactory::get($calId);
                    [$years, $months, , $days] = $cal->dateUntil(
                        $temporalDate->isoYear,
                        $temporalDate->isoMonth,
                        $temporalDate->isoDay,
                        $adjY2b,
                        $adjM2b,
                        $adjD2b,
                        $normLargest,
                    );
                    // Take absolute values — the output sign is applied later.
                    $years = abs($years);
                    $months = abs($months);
                    $days = abs($days);
                } else {
                    // ISO calendar: calendarDiff expects (smaller, larger).
                    $receiverIsLater = $sign < 0;
                    [$years, $months, $days] = self::calendarDiff(
                        $earlier->isoYear,
                        $earlier->isoMonth,
                        $earlier->isoDay,
                        $adjY2,
                        $adjM2,
                        $adjD2,
                        $receiverIsLater,
                    );
                    // Convert years to months when largestUnit is 'month'.
                    if ($normLargest === 'month') {
                        $months = ($years * 12) + $months;
                        $years = 0;
                    }
                }
                $weeks = 0;
            }

            $isSmallestCalendar = in_array($normSmallest, ['year', 'month', 'week', 'day'], strict: true);

            // The receiver (temporalDate) is the later date when sign < 0.
            $receiverIsLater = $sign < 0;

            if ($isSmallestCalendar) {
                // Calendar-unit rounding: zero out time and round the calendar part.
                if ($normSmallest === 'year') {
                    $totalMonths = ($years * 12) + $months;
                    $roundedYears = self::roundCalendarYears(
                        $years,
                        $totalMonths,
                        $days,
                        $timeDiffNs,
                        $temporalDate,
                        $roundingIncrement,
                        $roundingMode,
                        $receiverIsLater,
                        $outputSign,
                    );
                    return new Duration(years: $outputSign * $roundedYears);
                }
                if ($normSmallest === 'month') {
                    $totalMonths = ($years * 12) + $months;
                    $roundedMonths = self::roundCalendarMonths(
                        $totalMonths,
                        $days,
                        $timeDiffNs,
                        $temporalDate,
                        $roundingIncrement,
                        $roundingMode,
                        $receiverIsLater,
                        $outputSign,
                    );
                    if ($normLargest === 'year') {
                        $roundedYears = intdiv(num1: $roundedMonths, num2: 12);
                        $roundedMonths -= $roundedYears * 12;
                        return new Duration(years: $outputSign * $roundedYears, months: $outputSign * $roundedMonths);
                    }
                    return new Duration(months: $outputSign * $roundedMonths);
                }
                if ($normSmallest === 'week') {
                    $totalDays = ($weeks * 7) + $days;
                    $weekIncrement = $roundingIncrement * 7;
                    $roundedDays = self::roundDaysWithTime(
                        $totalDays,
                        $timeDiffNs,
                        $weekIncrement,
                        $roundingMode,
                        $outputSign,
                    );
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
                $roundedDays = self::roundDaysWithTime(
                    $days,
                    $timeDiffNs,
                    $roundingIncrement,
                    $roundingMode,
                    $outputSign,
                );
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
            $nsPerSmallest = self::nsPerTimeUnit($normSmallest);
            /** @psalm-var int<1, 1000> $roundingIncrement */
            $nsIncrement = $nsPerSmallest * $roundingIncrement;
            // For negative output diffs, flip floor/ceil.
            $effTimeMode = $outputSign < 0 ? self::flipDirectionalMode($roundingMode) : $roundingMode;
            $absTimeNs = TimeOfDay::roundPositive($timeDiffNs, $nsIncrement, $effTimeMode);

            // Handle day overflow from rounding time (e.g., 23:59 rounds up to 24:00).
            $overflowDays = intdiv(num1: $absTimeNs, num2: TimeOfDay::NS_PER_DAY);
            $absTimeNs %= TimeOfDay::NS_PER_DAY;

            // When time overflow produces extra days, recompute the calendar diff
            // from the updated position to properly rebalance months/years.
            if ($overflowDays > 0 && $normLargest !== 'day' && $normLargest !== 'week') {
                // Overflow from time rounding: recompute calendar diff.
                if ($calId !== 'iso8601') {
                    // Non-ISO: shift nonIsoAdjJdn by overflow in the diff direction.
                    $tc39Jdn2 = $nonIsoAdjJdn + ($sign >= 0 ? $overflowDays : -$overflowDays);
                    [$adjY3, $adjM3, $adjD3] = CalendarMath::fromJulianDay($tc39Jdn2);
                    [$years, $months, , $days] = CalendarFactory::get($calId)->dateUntil(
                        $temporalDate->isoYear,
                        $temporalDate->isoMonth,
                        $temporalDate->isoDay,
                        $adjY3,
                        $adjM3,
                        $adjD3,
                        $normLargest,
                    );
                    $years = abs($years);
                    $months = abs($months);
                    $days = abs($days);
                } else {
                    // ISO: add overflow to the swap-based adjOtherJdn.
                    $isoAdjJdn2 = $adjOtherJdn + $overflowDays;
                    [$adjY3, $adjM3, $adjD3] = CalendarMath::fromJulianDay($isoAdjJdn2);
                    [$years, $months, $days] = self::calendarDiff(
                        $earlier->isoYear,
                        $earlier->isoMonth,
                        $earlier->isoDay,
                        $adjY3,
                        $adjM3,
                        $adjD3,
                        $sign < 0,
                    );
                    if ($normLargest === 'month') {
                        $months = ($years * 12) + $months;
                        $years = 0;
                    }
                }
            } else {
                $days += $overflowDays;
            }

            [$h, $min, $sec, $ms, $us, $ns] = TimeOfDay::decompose($absTimeNs);

            return new Duration(
                years: $outputSign * $years,
                months: $outputSign * $months,
                weeks: $outputSign * $weeks,
                days: $outputSign * $days,
                hours: $outputSign * $h,
                minutes: $outputSign * $min,
                seconds: $outputSign * $sec,
                milliseconds: $outputSign * $ms,
                microseconds: $outputSign * $us,
                nanoseconds: $outputSign * $ns,
            );
        }

        // largestUnit is a time unit (hour or smaller): accumulate all days into ns.
        $totalAbsNs = ($dateDiff * TimeOfDay::NS_PER_DAY) + $timeDiffNs;

        $nsPerSmallest = self::nsPerTimeUnit($normSmallest);
        /** @psalm-var int<1, 1000> $roundingIncrement */
        $nsIncrement = $nsPerSmallest * $roundingIncrement;
        // For negative output diffs, flip floor/ceil so they retain their directional meaning.
        $effectiveRoundMode = $outputSign < 0 ? self::flipDirectionalMode($roundingMode) : $roundingMode;
        $roundedAbsNs = TimeOfDay::roundPositive($totalAbsNs, $nsIncrement, $effectiveRoundMode);

        // Decompose based on largest unit (no conversion to higher units).
        /** @var array<string, int> $timeUnitNs */
        static $timeUnitNs = [
            'hour' => 3_600_000_000_000,
            'minute' => 60_000_000_000,
            'second' => 1_000_000_000,
            'millisecond' => 1_000_000,
            'microsecond' => 1_000,
            'nanosecond' => 1,
        ];
        /** @var list<'hour'|'minute'|'second'|'millisecond'|'microsecond'|'nanosecond'> $timeUnitOrder */
        static $timeUnitOrder = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];

        $rem = $roundedAbsNs;
        $h = 0;
        $min = 0;
        $sec = 0;
        $ms = 0;
        $us = 0;
        $ns = 0;
        $started = false;
        foreach ($timeUnitOrder as $unit) {
            if ($unit === $normLargest) {
                $started = true;
            }
            if (!$started) {
                continue;
            }
            $perUnit = $timeUnitNs[$unit];
            $val = intdiv(num1: $rem, num2: $perUnit);
            $rem %= $perUnit;
            match ($unit) {
                'hour' => $h = $val,
                'minute' => $min = $val,
                'second' => $sec = $val,
                'millisecond' => $ms = $val,
                'microsecond' => $us = $val,
                'nanosecond' => $ns = $val,
            };
        }

        return new Duration(
            hours: $outputSign * $h,
            minutes: $outputSign * $min,
            seconds: $outputSign * $sec,
            milliseconds: $outputSign * $ms,
            microseconds: $outputSign * $us,
            nanoseconds: $outputSign * $ns,
        );
    }

    /**
     * Nanoseconds per time unit (hour and below).
     */
    private static function nsPerTimeUnit(string $unit): int
    {
        return match ($unit) {
            'hour' => TimeOfDay::NS_PER_HOUR,
            'minute' => TimeOfDay::NS_PER_MINUTE,
            'second' => EpochLimits::NS_PER_SECOND,
            'millisecond' => EpochLimits::NS_PER_MILLISECOND,
            'microsecond' => EpochLimits::NS_PER_MICROSECOND,
            default => 1,
        };
    }

    /**
     * Flips the directional rounding modes for rounding a magnitude whose reported
     * sign is negative (floor↔ceil, halfFloor↔halfCeil).
     */
    private static function flipDirectionalMode(string $mode): string
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
     * Calendar-aware year/month/day breakdown between two dates, as used by since()/until().
     *
     * @param int<1, 12> $m1
     * @param int<1, 12> $m2
     * @return array{0: int, 1: int, 2: int}
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
        // Both call sites pass (y1,m1,d1) = the earlier endpoint and (y2,m2,d2)
        // derived from earlierJdn + a non-negative day count, so (y2,m2,d2) is always
        // >= (y1,m1,d1) lexicographically. The diff is therefore always non-negative and
        // the swap path a smaller second operand would need is unreachable here.
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

        if ($receiverIsY2) {
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

        return [$years, $months, $days];
    }

    /**
     * Rounds days (non-negative) plus remaining time-of-day nanoseconds using the given
     * rounding mode. The time ns acts as fractional progress toward the next day.
     */
    private static function roundDaysWithTime(int $days, int $timeNs, int $increment, string $mode, int $sign = 1): int
    {
        $progress = $timeNs > 0 ? (float) $timeNs / (float) TimeOfDay::NS_PER_DAY : 0.0;
        $roundUp = CalendarMath::applyCalendarRoundingProgress($days, $progress, $increment, $mode, $sign);
        $q = intdiv(num1: $days, num2: $increment);
        return $roundUp ? ($q + 1) * $increment : $q * $increment;
    }

    /**
     * Calendar-aware rounding for months (NudgeToCalendarUnit, unit=months).
     *
     * Rounds $totalMonths (non-negative) + $remainingDays + $remainingTimeNs to the
     * nearest $increment months, anchored from the later date.
     *
     * @throws RangeError if the rounded date is out of the valid ISO range.
     */
    private static function roundCalendarMonths(
        int $totalMonths,
        int $remainingDays,
        int $remainingTimeNs,
        PlainDateTime $receiver,
        int $increment,
        string $mode,
        bool $receiverIsLater,
        int $sign = 1,
    ): int {
        $dir = $receiverIsLater ? -1 : 1;

        // floor-count (rounded down to nearest multiple of increment).
        $floorCount = intdiv(num1: $totalMonths, num2: $increment) * $increment;

        $anchorJdn = self::addSignedMonths($receiver, $dir * $floorCount);
        $nextJdn = self::addSignedMonths($receiver, $dir * ($floorCount + $increment));

        $intervalDays = abs($nextJdn - $anchorJdn);

        // Total fractional progress: remaining days + remaining time as fraction of a day.
        $totalRemNs = ($remainingDays * TimeOfDay::NS_PER_DAY) + $remainingTimeNs;
        $progress = $intervalDays > 0
            ? (float) $totalRemNs / ((float) $intervalDays * (float) TimeOfDay::NS_PER_DAY)
            : 0.0;

        $roundUp = CalendarMath::applyCalendarRoundingProgress($totalMonths, $progress, $increment, $mode, $sign);

        $roundedAbsMonths = $roundUp ? $floorCount + $increment : $floorCount;

        // Validate: the rounded result must not exceed the valid PlainDate range.
        self::addSignedMonths($receiver, $dir * $roundedAbsMonths);

        return $roundedAbsMonths;
    }

    /**
     * Calendar-aware rounding for years (NudgeToCalendarUnit, unit=years).
     *
     * @throws RangeError if the rounded date is out of the valid ISO range.
     */
    private static function roundCalendarYears(
        int $years,
        int $totalMonths,
        int $remainingDays,
        int $remainingTimeNs,
        PlainDateTime $receiver,
        int $increment,
        string $mode,
        bool $receiverIsLater,
        int $sign = 1,
    ): int {
        $dir = $receiverIsLater ? -1 : 1;

        $floorCount = intdiv(num1: $years, num2: $increment) * $increment;

        // For year rounding, we go by year increments (12 months each).
        $anchorJdn = self::addSignedMonths($receiver, $dir * $floorCount * 12);
        $nextJdn = self::addSignedMonths($receiver, $dir * ($floorCount + $increment) * 12);

        $intervalDays = abs($nextJdn - $anchorJdn);

        // Compute the total distance from anchor (floorCount years) to actual position.
        $remMonths = $totalMonths - ($floorCount * 12);
        $monthsJdn = self::addSignedMonths($receiver, $dir * (($floorCount * 12) + $remMonths));
        $remDaysFromMonths = abs($monthsJdn - $anchorJdn);
        $totalRemNs = (($remDaysFromMonths + $remainingDays) * TimeOfDay::NS_PER_DAY) + $remainingTimeNs;
        $progress = $intervalDays > 0
            ? (float) $totalRemNs / ((float) $intervalDays * (float) TimeOfDay::NS_PER_DAY)
            : 0.0;

        $roundUp = CalendarMath::applyCalendarRoundingProgress($years, $progress, $increment, $mode, $sign);

        $roundedAbsYears = $roundUp ? $floorCount + $increment : $floorCount;

        // Validate range.
        self::addSignedMonths($receiver, $dir * $roundedAbsYears * 12);

        return $roundedAbsYears;
    }

    /**
     * Adds $signedMonths months to $receiver's date and returns the resulting Julian Day Number.
     *
     * @throws RangeError if the resulting date is outside the valid ISO range.
     */
    private static function addSignedMonths(PlainDateTime $receiver, int $signedMonths): int
    {
        $cal = CalendarFactory::get($receiver->calendarId);
        [$y, $m, $d] = $cal->dateAdd(
            $receiver->isoYear,
            $receiver->isoMonth,
            $receiver->isoDay,
            0,
            $signedMonths,
            0,
            0,
            'constrain',
        );

        $jdn = CalendarMath::toJulianDay($y, $m, $d);
        $minJdn = CalendarMath::toJulianDay(-271_821, 4, 19);
        $maxJdn = CalendarMath::toJulianDay(275_760, 9, 13);
        if ($jdn < $minJdn || $jdn > $maxJdn) {
            throw new RangeError('Rounded PlainDateTime is outside the representable range.');
        }

        return $jdn;
    }
}
