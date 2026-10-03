<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Duration;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\Internal\Calendar\CalendarProtocol;
use Calendrics\Spec\PlainDateTime;

/**
 * The `since()` / `until()` engine for `PlainDateTime`.
 *
 * With no zone in play, the gap between two plain datetimes is a fixed quantity — the
 * work here is expressing it in the units the caller asked for. Which path runs is
 * decided by `largestUnit`:
 *
 *   - **Time-only** (`hour` and below): every day is exactly 24 h, so the whole gap
 *     collapses into one nanosecond count that is rounded and re-decomposed.
 *   - **Calendar** (`day` and above, the default): the date part is measured with
 *     calendar-aware arithmetic via the calendar protocol's `dateUntil`, after borrowing
 *     a day when the time-of-day fragment runs backwards. ISO and non-ISO calendars
 *     borrow differently, so each computes its own second endpoint before dispatching.
 *
 * A calendar `smallestUnit` rounds by *fractional progress through the current unit*
 * (TC39 NudgeToCalendarUnit), which needs the true length of that unit: the interval
 * between two real calendar anchors reached by adding whole months from the receiver,
 * not a nominal 30 days. A time `smallestUnit` rounds the nanosecond remainder, and an
 * overflow day from that rounding (23:59 → 24:00) can promote the calendar part
 * when the nudged endpoint reaches the receiver's next larger-unit boundary.
 *
 * Throughout, the difference is computed in the positive direction and the sign is
 * applied last; `since()` flips it. Directional rounding modes are mirrored to match,
 * so `floor` keeps meaning "toward −∞" on a negative result.
 *
 * @internal
 */
final class DateTimeDifference
{
    /**
     * Computes the rounded Duration between $temporalDate and $other.
     *
     * TC39 CalendarDateUntil is always called as (temporalDate, other). For
     * "since", the final result is negated.
     *
     * @param string $operation 'since' or 'until'
     * @param array<array-key, mixed>|object $options ['largestUnit' => ..., 'smallestUnit' => ..., 'roundingMode' => ..., 'roundingIncrement' => ...]
     */
    public static function between(
        PlainDateTime $temporalDate,
        PlainDateTime $other,
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
        $largestUnitFixed = false;
        $smallestUnit = null;
        $roundingMode = 'trunc';
        $roundingIncrement = 1;

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
                $largestUnitFixed = $lu !== 'auto';
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
                if ($su === 'auto' || !in_array($su, $validUnits, strict: true)) {
                    throw new RangeError("Invalid smallestUnit value: \"{$su}\".");
                }
                $smallestUnit = $su;
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
            if ($largestUnitFixed) {
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
        $tdNs = CalendarMath::timeToNs(
            $temporalDate->hour,
            $temporalDate->minute,
            $temporalDate->second,
            $temporalDate->millisecond,
            $temporalDate->microsecond,
            $temporalDate->nanosecond,
        );
        $otherNs = CalendarMath::timeToNs(
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
            CalendarMath::timeToNs(
                $later->hour,
                $later->minute,
                $later->second,
                $later->millisecond,
                $later->microsecond,
                $later->nanosecond,
            )
            - CalendarMath::timeToNs(
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
            $timeDiffNs += EpochLimits::NS_PER_DAY;
        }
        // Both $dateDiff and $timeDiffNs are now non-negative.

        $isCalendarLargest = $luRank >= 6; // day or above

        if ($isCalendarLargest) {
            $calId = $temporalDate->calendarId;
            // DifferenceISODateTime borrows at the target in receiver order.
            $adjustedTargetJdn = $otherJdn;
            if (($sign * ($otherNs - $tdNs)) < 0) {
                $adjustedTargetJdn -= $sign;
            }
            if ($normLargest === 'day') {
                $days = $dateDiff;
                [$years, $months, $weeks] = [0, 0, 0];
            } elseif ($normLargest === 'week') {
                $weeks = intdiv(num1: $dateDiff, num2: 7);
                $days = $dateDiff - ($weeks * 7);
                [$years, $months] = [0, 0];
            } else {
                // Day and week are handled above, so only these two reach a calendar.
                $calendarUnit = $normLargest === 'month' ? 'month' : 'year';
                $cal = CalendarFactory::get($calId);
                [$years, $months, $days] = self::absoluteCalendarDiff(
                    $cal,
                    $temporalDate,
                    $adjustedTargetJdn,
                    $calendarUnit,
                );
                $weeks = 0;
            }

            $isSmallestCalendar = in_array($normSmallest, ['year', 'month', 'week', 'day'], strict: true);

            // The receiver (temporalDate) is the later date when sign < 0.
            $receiverIsLater = $sign < 0;

            if ($isSmallestCalendar) {
                // Calendar-unit rounding: zero out time and round the calendar part.
                if ($normSmallest === 'year') {
                    $roundedYears = self::roundCalendarYears(
                        $years,
                        $otherJdn,
                        $otherNs - $tdNs,
                        $temporalDate,
                        $roundingIncrement,
                        $roundingMode,
                        $receiverIsLater,
                        $outputSign,
                    );
                    return new Duration(years: $outputSign * $roundedYears);
                }
                if ($normSmallest === 'month') {
                    // ComputeNudgeWindow keeps years fixed while rounding months.
                    // Borrow at the actual target before asking for the receiver's
                    // calendar difference; reversing endpoints changes month ends.
                    $monthTargetJdn = $otherJdn;
                    if (($sign * ($otherNs - $tdNs)) < 0) {
                        $monthTargetJdn -= $sign;
                    }
                    [$years, $months] = self::absoluteCalendarDiff(
                        CalendarFactory::get($calId),
                        $temporalDate,
                        $monthTargetJdn,
                        $normLargest === 'year' ? 'year' : 'month',
                    );
                    $roundedMonths = self::roundCalendarMonths(
                        $months,
                        $otherJdn,
                        $otherNs - $tdNs,
                        $temporalDate,
                        $roundingIncrement,
                        $roundingMode,
                        $receiverIsLater,
                        $outputSign,
                        $years,
                    );
                    if ($normLargest === 'year') {
                        if ($roundedMonths > $months) {
                            // BubbleRelativeDuration checks the next year once,
                            // even when the month increment spans several years.
                            $roundedJdn = self::addSigned($temporalDate, $sign * $years, $sign * $roundedMonths);
                            $nextYearJdn = self::addSigned($temporalDate, $sign * ($years + 1), 0);
                            if (($sign * ($roundedJdn - $nextYearJdn)) >= 0) {
                                $years++;
                                $roundedMonths = 0;
                            }
                        }
                        return new Duration(years: $outputSign * $years, months: $outputSign * $roundedMonths);
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
                $roundedDate = new DateSpan($years, $months, $weeks, $roundedDays);
                if ($roundedDays > $days) {
                    [$roundedDate] = self::bubbleExpandedDays(
                        $temporalDate,
                        $otherJdn + ($sign * ($roundedDays - $days)),
                        $otherNs - ($sign * $timeDiffNs),
                        $roundedDate,
                        0,
                        $normLargest,
                        $sign,
                    );
                }
                return new Duration(
                    years: $outputSign * $roundedDate->years,
                    months: $outputSign * $roundedDate->months,
                    weeks: $outputSign * $roundedDate->weeks,
                    days: $outputSign * $roundedDate->days,
                );
            }

            // smallestUnit is a time unit but largestUnit is a calendar unit.
            $nsPerSmallest = match ($normSmallest) {
                'hour' => EpochLimits::NS_PER_HOUR,
                'minute' => EpochLimits::NS_PER_MINUTE,
                'second' => EpochLimits::NS_PER_SECOND,
                'millisecond' => EpochLimits::NS_PER_MILLISECOND,
                'microsecond' => EpochLimits::NS_PER_MICROSECOND,
                default => 1,
            };
            /** @psalm-var int<1, 1000> $roundingIncrement */
            $nsIncrement = $nsPerSmallest * $roundingIncrement;
            // For negative output diffs, flip floor/ceil.
            $effTimeMode = $roundingMode;
            if ($outputSign < 0) {
                $effTimeMode = EpochRounding::negateMode($roundingMode);
            }
            // NudgeToDayOrTime rounds the day remainder and time together. Keep
            // whole seconds separate so long spans retain half-even tie parity.
            $timeSeconds = ($days * 86_400) + intdiv($timeDiffNs, EpochLimits::NS_PER_SECOND);
            [$roundedSeconds, $roundedSubNs] = EpochRounding::round(
                $timeSeconds,
                $timeDiffNs % EpochLimits::NS_PER_SECOND,
                $nsIncrement,
                $effTimeMode,
            );
            $roundedDays = intdiv($roundedSeconds, num2: 86_400);
            $overflowDays = $roundedDays - $days;
            $absTimeNs = (($roundedSeconds % 86_400) * EpochLimits::NS_PER_SECOND) + $roundedSubNs;

            $roundedDate = new DateSpan($years, $months, $weeks, $days + $overflowDays);
            if ($overflowDays > 0) {
                [$roundedDate, $absTimeNs] = self::bubbleExpandedDays(
                    $temporalDate,
                    $otherJdn + ($sign * $overflowDays),
                    $otherNs + ($sign * ($absTimeNs - $timeDiffNs)),
                    $roundedDate,
                    $absTimeNs,
                    $normLargest,
                    $sign,
                );
            }

            [$h, $min, $sec, $ms, $us, $ns] = CalendarMath::nsToTime($absTimeNs);

            return new Duration(
                years: $outputSign * $roundedDate->years,
                months: $outputSign * $roundedDate->months,
                weeks: $outputSign * $roundedDate->weeks,
                days: $outputSign * $roundedDate->days,
                hours: $outputSign * $h,
                minutes: $outputSign * $min,
                seconds: $outputSign * $sec,
                milliseconds: $outputSign * $ms,
                microseconds: $outputSign * $us,
                nanoseconds: $outputSign * $ns,
            );
        }

        // Keep long differences exact without forming an over-int64 nanosecond total.
        // Duration rounding already carries seconds and sub-second nanoseconds separately
        // and converts the final fields to the float64 representation required by TC39.
        $seconds = ($dateDiff * 86_400) + intdiv($timeDiffNs, EpochLimits::NS_PER_SECOND);
        $nanoseconds = $timeDiffNs % EpochLimits::NS_PER_SECOND;

        return DurationRounding::round(
            new Duration(seconds: $outputSign * $seconds, nanoseconds: $outputSign * $nanoseconds),
            [
                'largestUnit' => $normLargest,
                'smallestUnit' => $normSmallest,
                'roundingIncrement' => $roundingIncrement,
                'roundingMode' => $roundingMode,
            ],
        );
    }

    /**
     * Rounds days (non-negative) plus remaining time-of-day nanoseconds using the given
     * rounding mode. The time ns acts as fractional progress toward the next day.
     */
    private static function roundDaysWithTime(int $days, int $timeNs, int $increment, string $mode, int $sign = 1): int
    {
        $progress = $timeNs > 0 ? (float) $timeNs / (float) EpochLimits::NS_PER_DAY : 0.0;
        $roundUp = CalendarMath::applyCalendarRoundingProgress($days, $progress, $increment, $mode, $sign);
        $q = intdiv(num1: $days, num2: $increment);
        return $roundUp ? ($q + 1) * $increment : $q * $increment;
    }

    /**
     * BubbleRelativeDuration checks each larger unit once, preserving the existing
     * prefix until the nudged endpoint reaches that receiver-anchored boundary.
     *
     * @return array{DateSpan, int}
     */
    private static function bubbleExpandedDays(
        PlainDateTime $receiver,
        int $nudgedJdn,
        int $nudgedTimeNs,
        DateSpan $span,
        int $timeNs,
        string $largestUnit,
        int $direction,
    ): array {
        // Keep the nudged endpoint exact even when its full epoch cannot fit int64.
        $carry = CalendarMath::floorDiv($nudgedTimeNs, EpochLimits::NS_PER_DAY);
        $nudgedJdn += $carry;
        $nudgedTimeNs -= $carry * EpochLimits::NS_PER_DAY;
        $receiverTimeNs = CalendarMath::timeToNs(
            $receiver->hour,
            $receiver->minute,
            $receiver->second,
            $receiver->millisecond,
            $receiver->microsecond,
            $receiver->nanosecond,
        );
        $units = match ($largestUnit) {
            'year' => ['month', 'year'],
            'month' => ['month'],
            'week' => ['week'],
            default => [],
        };
        foreach ($units as $unit) {
            $next = match ($unit) {
                'year' => new DateSpan(years: $span->years + 1, days: 0),
                'month' => new DateSpan(years: $span->years, months: $span->months + 1, days: 0),
                default => new DateSpan(weeks: $span->weeks + 1, days: 0),
            };
            $boundaryJdn = self::addSigned(
                $receiver,
                $direction * $next->years,
                $direction * $next->months,
                $direction * $next->weeks,
            );
            $comparison = $nudgedJdn <=> $boundaryJdn;
            if ($comparison === 0) {
                $comparison = $nudgedTimeNs <=> $receiverTimeNs;
            }
            if (($direction * $comparison) < 0) {
                break;
            }
            $span = $next;
            $timeNs = 0;
        }
        return [$span, $timeNs];
    }

    /**
     * @param 'month'|'year' $unit
     * @return array{int, int, int}
     */
    private static function absoluteCalendarDiff(
        CalendarProtocol $calendar,
        PlainDateTime $receiver,
        int $targetJdn,
        string $unit,
    ): array {
        [$year, $month, $day] = CalendarMath::fromJulianDay($targetJdn);
        [$years, $months, , $days] = $calendar->dateUntil(
            $receiver->isoYear,
            $receiver->isoMonth,
            $receiver->isoDay,
            $year,
            $month,
            $day,
            $unit,
            receiverIsLater: CalendarMath::toJulianDay($receiver->isoYear, $receiver->isoMonth, $receiver->isoDay)
            > $targetJdn,
        );
        return [abs($years), abs($months), abs($days)];
    }

    /**
     * Calendar-aware rounding for months (NudgeToCalendarUnit, unit=months).
     *
     * Rounds the month field while preserving calendar years in both anchors.
     *
     * @throws RangeError if the rounded date is out of the valid ISO range.
     */
    private static function roundCalendarMonths(
        int $totalMonths,
        int $targetJdn,
        int $timeDifferenceNs,
        PlainDateTime $receiver,
        int $increment,
        string $mode,
        bool $receiverIsLater,
        int $sign = 1,
        int $years = 0,
    ): int {
        $dir = $receiverIsLater ? -1 : 1;

        // floor-count (rounded down to nearest multiple of increment).
        $floorCount = intdiv(num1: $totalMonths, num2: $increment) * $increment;

        $anchorJdn = self::addSigned($receiver, $dir * $years, $dir * $floorCount);
        $nextJdn = self::addSigned($receiver, $dir * $years, $dir * ($floorCount + $increment));

        $intervalDays = abs($nextJdn - $anchorJdn);

        // Measure all progress from the lower increment boundary, including
        // whole months that do not fill the requested increment.
        $remainingDistance = $dir * ($targetJdn - $anchorJdn);
        $remainingTimeNs = $dir * $timeDifferenceNs;
        if ($remainingTimeNs < 0) {
            $remainingDistance--;
            $remainingTimeNs += EpochLimits::NS_PER_DAY;
        }
        $roundUp =
            $remainingDistance >= $intervalDays
            || self::roundCalendarProgress(
                $remainingDistance,
                $remainingTimeNs,
                $intervalDays,
                $mode,
                $sign,
                intdiv($floorCount, $increment),
            );

        $roundedAbsMonths = $roundUp ? $floorCount + $increment : $floorCount;

        // Validate: the rounded result must not exceed the valid PlainDate range.
        self::addSigned($receiver, $dir * $years, $dir * $roundedAbsMonths);

        return $roundedAbsMonths;
    }

    /**
     * Calendar-aware rounding for years (NudgeToCalendarUnit, unit=years).
     *
     * @throws RangeError if the rounded date is out of the valid ISO range.
     */
    private static function roundCalendarYears(
        int $years,
        int $targetJdn,
        int $timeDifferenceNs,
        PlainDateTime $receiver,
        int $increment,
        string $mode,
        bool $receiverIsLater,
        int $sign = 1,
    ): int {
        $dir = $receiverIsLater ? -1 : 1;

        $floorCount = intdiv(num1: $years, num2: $increment) * $increment;

        // Calendar years can contain leap months, so do not convert them to months.
        $anchorJdn = self::addSigned($receiver, $dir * $floorCount, 0);
        $nextJdn = self::addSigned($receiver, $dir * ($floorCount + $increment), 0);

        $intervalDays = abs($nextJdn - $anchorJdn);

        // Measure the actual endpoint, keeping its sub-day part exact.
        $remainingDays = $dir * ($targetJdn - $anchorJdn);
        $remainingTimeNs = $dir * $timeDifferenceNs;
        if ($remainingTimeNs < 0) {
            $remainingDays--;
            $remainingTimeNs += EpochLimits::NS_PER_DAY;
        }
        $roundUp = self::roundCalendarProgress(
            $remainingDays,
            $remainingTimeNs,
            $intervalDays,
            $mode,
            $sign,
            intdiv($floorCount, $increment),
        );

        $roundedAbsYears = $roundUp ? $floorCount + $increment : $floorCount;

        // Validate range.
        self::addSigned($receiver, $dir * $roundedAbsYears, 0);

        return $roundedAbsYears;
    }

    /**
     * Compare whole days and sub-day nanoseconds separately so a one-nanosecond
     * difference from the midpoint survives even a multi-year rounding window.
     */
    private static function roundCalendarProgress(
        int $days,
        int $timeNs,
        int $intervalDays,
        string $mode,
        int $sign,
        int $floorMultiple,
    ): bool {
        $halfDays = intdiv(num1: $intervalDays, num2: 2);
        $halfTimeNs = ($intervalDays % 2) * intdiv(num1: EpochLimits::NS_PER_DAY, num2: 2);
        $comparison = $days <=> $halfDays;
        if ($comparison === 0) {
            $comparison = $timeNs <=> $halfTimeNs;
        }
        // The mode helper needs only zero, below-half, tie, or above-half.
        $progress = match (true) {
            $days === 0 && $timeNs === 0 => 0.0,
            $comparison < 0 => 0.25,
            $comparison === 0 => 0.5,
            default => 0.75,
        };
        return CalendarMath::applyRoundingProgress($progress, $mode, $sign, $floorMultiple);
    }

    /**
     * Adds calendar years, months, and weeks to the receiver and returns its Julian Day Number.
     *
     * @throws RangeError if the resulting date is outside the valid ISO range.
     */
    private static function addSigned(
        PlainDateTime $receiver,
        int $signedYears,
        int $signedMonths,
        int $signedWeeks = 0,
    ): int {
        $cal = CalendarFactory::get($receiver->calendarId);
        [$y, $m, $d] = $cal->dateAdd(
            $receiver->isoYear,
            $receiver->isoMonth,
            $receiver->isoDay,
            $signedYears,
            $signedMonths,
            $signedWeeks,
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
