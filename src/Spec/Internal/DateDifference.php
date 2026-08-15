<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;
use Temporal\Spec\Duration;
use Temporal\Spec\Internal\Calendar\CalendarFactory;
use Temporal\Spec\PlainDate;

/**
 * The `since()` / `until()` engine for `PlainDate`.
 *
 * The gap between two dates is a fixed number of days — the work here is expressing
 * it in the units the caller asked for. Weeks and days are pure day-count arithmetic
 * on Julian Day Numbers. Months and years are calendrical: the date part is measured
 * via the calendar protocol's `dateUntil` for non-ISO calendars, or the ISO breakdown
 * in {@see calendarDiff()}, always in the spec's (receiver, other) order.
 *
 * A calendar `smallestUnit` rounds by *fractional progress through the current unit*
 * (TC39 NudgeToCalendarUnit), which needs the true length of that unit: the interval
 * between two real calendar anchors reached by adding whole months or years from the
 * receiver, not a nominal 30 or 365 days.
 *
 * Throughout, the difference is computed in the `until` direction and negated last
 * for `since`; directional rounding modes are mirrored up front to match, so `floor`
 * keeps meaning "toward −∞" in the user-facing direction.
 *
 * @internal
 */
final class DateDifference
{
    /**
     * Computes the rounded Duration between $temporalDate and $other.
     *
     * $temporalDate and $other define the raw difference; $temporalDate is the
     * receiver (used as the anchor for calendar-aware rounding, per the TC39
     * NudgeToCalendarUnit spec). For "since", the final result is negated.
     *
     * @param string $operation 'since' or 'until'
     * @param array<array-key, mixed>|object|null $options ['largestUnit' => ..., 'smallestUnit' => ..., 'roundingMode' => ..., 'roundingIncrement' => ...]
     */
    public static function between(
        PlainDate $temporalDate,
        PlainDate $other,
        string $operation,
        array|object|null $options,
    ): Duration {
        /** @var list<string> $validUnits */
        static $validUnits = ['auto', 'day', 'days', 'week', 'weeks', 'month', 'months', 'year', 'years'];
        /** @var array<string, int> $unitRank */
        static $unitRank = [
            'year' => 4,
            'years' => 4,
            'month' => 3,
            'months' => 3,
            'week' => 2,
            'weeks' => 2,
            'day' => 1,
            'days' => 1,
            'auto' => 1,
        ];

        $largestUnit = 'day';
        $largestUnitExplicit = false; // whether largestUnit was explicitly specified
        $smallestUnit = null; // null = not specified
        $roundingMode = 'trunc';
        $roundingIncrement = 1;

        if ($options !== null) {
            $opts = Options::normalizeOptions($options, [
                'largestUnit',
                'roundingIncrement',
                'roundingMode',
                'smallestUnit',
            ]);

            // largestUnit
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

            // roundingIncrement (parsed early so validation order matches spec)
            if (array_key_exists('roundingIncrement', $opts)) {
                /** @var mixed $ri */
                $ri = $opts['roundingIncrement'];
                if ($ri !== null) {
                    $roundingIncrement = CalendarMath::validateRoundingIncrement($ri);
                }
            }

            // roundingMode
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

            // smallestUnit
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

        // Default smallestUnit is 'day' (per TC39 spec for PlainDate).
        if ($smallestUnit === null) {
            $smallestUnit = 'day';
        }

        $suRank = $unitRank[$smallestUnit];
        $luRank = $unitRank[$largestUnit];

        if ($suRank > $luRank) {
            if ($largestUnitExplicit) {
                // Both explicitly set and smallestUnit > largestUnit: throw per spec.
                throw new RangeError(
                    "smallestUnit \"{$smallestUnit}\" cannot be larger than largestUnit \"{$largestUnit}\".",
                );
            }
            // Only smallestUnit was explicitly set; bump largestUnit up to match.
            $largestUnit = $smallestUnit;
        }

        // Normalize to canonical singular forms.
        $normLargest = match ($largestUnit) {
            'days', 'auto' => 'day',
            'weeks' => 'week',
            'months' => 'month',
            'years' => 'year',
            default => $largestUnit,
        };
        $normSmallest = match ($smallestUnit) {
            'days', 'auto' => 'day',
            'weeks' => 'week',
            'months' => 'month',
            'years' => 'year',
            default => $smallestUnit,
        };

        // TC39 step 6: CalendarDateUntil(temporalDate, other) — always in
        // (this, other) order, NOT (smaller, larger). The sign and leap-month
        // asymmetry are handled inside dateUntil.
        //
        // For day/week units the calendar doesn't matter, so we just use JDN.
        $tdJdn = CalendarMath::toJulianDay($temporalDate->isoYear, $temporalDate->isoMonth, $temporalDate->isoDay);
        $otherJdn = CalendarMath::toJulianDay($other->isoYear, $other->isoMonth, $other->isoDay);
        $totalDays = $otherJdn - $tdJdn; // positive when other > temporalDate

        // TC39 spec: GetDifferenceSettings negates the rounding mode for "since",
        // because the spec internally computes "until" and negates the resulting
        // duration. Negating the mode here makes ceil/floor behave correctly in
        // the user-facing direction. (Symmetric modes like halfExpand are unaffected.)
        if ($operation === 'since') {
            $roundingMode = self::negateRoundingMode($roundingMode);
        }

        // Weeks and days: purely mathematical (no calendar-awareness for months/years).
        if ($normLargest === 'day') {
            $d = self::roundDays($totalDays, $roundingIncrement, $roundingMode);
            return $operation === 'since' ? new Duration(days: -$d) : new Duration(days: $d);
        }

        if ($normLargest === 'week') {
            if ($normSmallest === 'week') {
                $weekIncrement = $roundingIncrement * 7;
                $roundedDays = self::roundDays($totalDays, $weekIncrement, $roundingMode);
                $w = intdiv(num1: $roundedDays, num2: 7);
                return $operation === 'since' ? new Duration(weeks: -$w) : new Duration(weeks: $w);
            }
            $rawWeeks = intdiv(num1: $totalDays, num2: 7);
            $rawDays = $totalDays - ($rawWeeks * 7);
            $roundedDays = self::roundDays($rawDays, $roundingIncrement, $roundingMode);
            return $operation === 'since'
                ? new Duration(weeks: -$rawWeeks, days: -$roundedDays)
                : new Duration(weeks: $rawWeeks, days: $roundedDays);
        }

        // Calendar units (months/years): compute via calendar protocol.
        // TC39 calls CalendarDateUntil(temporalDate, other) — preserving order.
        $calendarId = $temporalDate->calendarId;
        if ($calendarId !== 'iso8601') {
            $cal = CalendarFactory::get($calendarId);
            [$years, $months, , $days] = $cal->dateUntil(
                $temporalDate->isoYear,
                $temporalDate->isoMonth,
                $temporalDate->isoDay,
                $other->isoYear,
                $other->isoMonth,
                $other->isoDay,
                $normLargest,
            );
        } else {
            // ISO calendar: symmetric, so order doesn't matter for magnitudes.
            // Keep the (earlier, later) convention for the existing calendarDiff.
            $jdn1 = $tdJdn;
            $jdn2 = $otherJdn;
            $iY1 = $temporalDate->isoYear;
            $iM1 = $temporalDate->isoMonth;
            $iD1 = $temporalDate->isoDay;
            $iY2 = $other->isoYear;
            $iM2 = $other->isoMonth;
            $iD2 = $other->isoDay;
            $receiverIsLater = $jdn1 > $jdn2;
            if ($receiverIsLater) {
                [$iY1, $iM1, $iD1, $iY2, $iM2, $iD2] = [$iY2, $iM2, $iD2, $iY1, $iM1, $iD1];
            }
            [$years, $months, $days] = self::calendarDiff($iY1, $iM1, $iD1, $iY2, $iM2, $iD2, $receiverIsLater);
            if ($jdn1 > $jdn2) {
                // Going backward: negate to match the (temporalDate→other) direction
                $years = -$years;
                $months = -$months;
                $days = -$days;
            }
        }

        // TC39 spec: round BEFORE since negation (steps 8-10). The rounding mode
        // for "since" was already negated above, before the day/week branches.
        $sinceSign = $operation === 'since' ? -1 : 1;

        $sign = $totalDays <=> 0;
        $receiverIsLater = $sign < 0;

        if ($normLargest === 'month') {
            $totalMonths = ($years * 12) + $months;
            if ($normSmallest === 'month') {
                $rounded = self::roundCalendarMonths(
                    $totalMonths,
                    $days,
                    $temporalDate,
                    $roundingIncrement,
                    $roundingMode,
                    $receiverIsLater,
                );
                return new Duration(months: $sinceSign * $rounded);
            }
            $roundedDays = self::roundDays($days, $roundingIncrement, $roundingMode);
            return new Duration(months: $sinceSign * $totalMonths, days: $sinceSign * $roundedDays);
        }

        // normLargest === 'year'
        if ($normSmallest === 'year') {
            $totalMonths = ($years * 12) + $months;
            $rounded = self::roundCalendarYears(
                $years,
                $totalMonths,
                $days,
                $temporalDate,
                $roundingIncrement,
                $roundingMode,
                $receiverIsLater,
            );
            return new Duration(years: $sinceSign * $rounded);
        }
        if ($normSmallest === 'month') {
            $totalMonths = ($years * 12) + $months;
            $roundedMonths = self::roundCalendarMonths(
                $totalMonths,
                $days,
                $temporalDate,
                $roundingIncrement,
                $roundingMode,
                $receiverIsLater,
            );
            $roundedYears = intdiv(num1: $roundedMonths, num2: 12);
            $roundedMonths -= $roundedYears * 12;
            return new Duration(years: $sinceSign * $roundedYears, months: $sinceSign * $roundedMonths);
        }
        // smallestUnit=day: return years + months + rounded days.
        $roundedDays = self::roundDays($days, $roundingIncrement, $roundingMode);
        return new Duration(years: $sinceSign * $years, months: $sinceSign * $months, days: $sinceSign * $roundedDays);
    }

    /**
     * Mirrors a directed rounding mode (floor/ceil, halfFloor/halfCeil) across zero;
     * symmetric modes pass through unchanged.
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
     * Rounds totalDays (possibly negative) to the nearest multiple of $increment
     * using the given rounding mode.
     *
     * For directed modes (floor/ceil, halfFloor/halfCeil), the mode is negated
     * for negative values to maintain correct directional semantics.
     */
    private static function roundDays(int $totalDays, int $increment, string $mode): int
    {
        if ($increment === 1 && $mode === 'trunc') {
            return $totalDays;
        }
        $sign = $totalDays >= 0 ? 1 : -1;
        $absDays = abs($totalDays);
        $effectiveMode = $mode;
        if ($sign < 0) {
            $effectiveMode = self::negateRoundingMode($mode);
        }
        return $sign * EpochRounding::roundAsIfPositive($absDays, $increment, $effectiveMode);
    }

    /**
     * Calendar-aware rounding for months (NudgeToCalendarUnit, unit=months).
     *
     * Rounds $totalMonths (signed) + $remainingDays to the nearest $increment months,
     * anchored from the receiver date (per TC39 spec).
     *
     * $receiverIsLater: true when the receiver is the LATER of the two dates (since()
     * semantics), false when it is the EARLIER (until() semantics).  This controls the
     * direction in which the anchor is computed from the receiver.
     *
     * @throws RangeError if the rounded date is out of the valid ISO range.
     */
    private static function roundCalendarMonths(
        int $totalMonths,
        int $remainingDays,
        PlainDate $receiver,
        int $increment,
        string $mode,
        bool $receiverIsLater,
    ): int {
        $sign = $totalMonths >= 0 ? 1 : -1;
        if ($totalMonths === 0 && $remainingDays !== 0) {
            $sign = $remainingDays >= 0 ? 1 : -1;
        }
        $absTotalMonths = abs($totalMonths);
        $absRemDays = abs($remainingDays);

        // floor-count (rounded down to nearest multiple of increment).
        $floorCount = intdiv(num1: $absTotalMonths, num2: $increment) * $increment;

        // Anchor: receiver going toward "other" by floorCount months.
        // When receiver is the later date (since): go backward → receiver − sign*floorCount months.
        // When receiver is the earlier date (until): go forward → receiver + sign*floorCount months.
        // Equivalently, the direction multiplier is -sign for since and +sign for until,
        // which simplifies to: direction = receiverIsLater ? -1 : 1.
        $dir = $receiverIsLater ? -$sign : $sign;
        $anchorJdn = self::addSigned($receiver, 0, $dir * $floorCount);

        // Next boundary: one increment further in the same direction.
        $nextJdn = self::addSigned($receiver, 0, $dir * ($floorCount + $increment));

        // Interval size in days (absolute value of the interval).
        $intervalDays = abs($nextJdn - $anchorJdn);

        // Remaining distance from anchor toward target = |remainingDays| from calendarDiff.
        $progress = $intervalDays > 0 ? $absRemDays / $intervalDays : 0.0;

        // Apply rounding (for negative diffs, flip floor/ceil per spec §11.5.12).
        $roundUp = CalendarMath::applyRoundingProgress($progress, $mode, $sign, intdiv($floorCount, $increment));

        $roundedAbsMonths = $roundUp ? $floorCount + $increment : $floorCount;

        // Validate: the rounded result must not exceed the valid PlainDate range.
        self::addSigned($receiver, 0, $dir * $roundedAbsMonths); // throws if out of range

        return $sign * $roundedAbsMonths;
    }

    /**
     * Calendar-aware rounding for years (NudgeToCalendarUnit, unit=years).
     *
     * $receiverIsLater: true when the receiver is the LATER of the two dates (since()
     * semantics), false when it is the EARLIER (until() semantics).
     *
     * @throws RangeError if the rounded date is out of the valid ISO range.
     */
    private static function roundCalendarYears(
        int $years,
        int $totalMonths,
        int $remainingDays,
        PlainDate $receiver,
        int $increment,
        string $mode,
        bool $receiverIsLater,
    ): int {
        if ($years !== 0) {
            $sign = $years >= 0 ? 1 : -1;
        } elseif ($totalMonths !== 0) {
            $sign = $totalMonths >= 0 ? 1 : -1;
        } else {
            $sign = $remainingDays >= 0 ? 1 : -1;
        }
        $absYears = abs($years);

        $floorCount = intdiv(num1: $absYears, num2: $increment) * $increment;

        // Anchor: receiver going toward "other" by floorCount years.
        // When receiver is later (since): go backward → -sign direction.
        // When receiver is earlier (until): go forward → +sign direction.
        $dir = $receiverIsLater ? -$sign : $sign;
        $anchorJdn = self::addSigned($receiver, $dir * $floorCount, 0);
        $nextJdn = self::addSigned($receiver, $dir * ($floorCount + $increment), 0);

        $intervalDays = abs($nextJdn - $anchorJdn);

        // Compute the target JDN: from anchor, go further in the same direction
        // (toward next boundary) by the remaining months+days.
        $absRemMonths = abs($totalMonths) - ($floorCount * 12);
        $subAnchorJdn = self::addSigned(
            self::dateAtJulianDay($anchorJdn, $receiver->calendarId),
            0,
            $dir * $absRemMonths,
        );
        $targetJdn = $subAnchorJdn + ($dir * abs($remainingDays));
        $absRemDistance = abs($targetJdn - $anchorJdn);

        $progress = $intervalDays > 0 ? $absRemDistance / $intervalDays : 0.0;
        $roundUp = CalendarMath::applyRoundingProgress($progress, $mode, $sign, intdiv($floorCount, $increment));

        $roundedAbsYears = $roundUp ? $floorCount + $increment : $floorCount;

        // Validate: the rounded result must not exceed the valid PlainDate range.
        self::addSigned($receiver, $dir * $roundedAbsYears, 0); // throws if out of range

        return $sign * $roundedAbsYears;
    }

    /**
     * Adds $signedYears years and $signedMonths months to $date with constrain overflow.
     *
     * Returns the Julian Day Number of the resulting date. Years and months go through
     * the calendar protocol separately because they are not interchangeable in
     * calendars with leap months (a year is not always 12 months).
     *
     * @throws RangeError if the resulting date is outside the valid ISO range.
     */
    private static function addSigned(PlainDate $date, int $signedYears, int $signedMonths): int
    {
        $cal = CalendarFactory::get($date->calendarId);
        [$y, $m, $d] = $cal->dateAdd(
            $date->isoYear,
            $date->isoMonth,
            $date->isoDay,
            $signedYears,
            $signedMonths,
            0,
            0,
            'constrain',
        );

        $minJdn = CalendarMath::toJulianDay(-271_821, 4, 19);
        $maxJdn = CalendarMath::toJulianDay(275_760, 9, 13);
        $jdn = CalendarMath::toJulianDay($y, $m, $d);
        if ($jdn < $minJdn || $jdn > $maxJdn) {
            throw new RangeError('PlainDate rounding result is outside the representable range.');
        }
        return $jdn;
    }

    /**
     * Constructs a PlainDate at the given Julian Day Number (used as a rounding anchor).
     */
    private static function dateAtJulianDay(int $jdn, string $calendarId): PlainDate
    {
        [$y, $m, $d] = CalendarMath::fromJulianDay($jdn);
        return new PlainDate($y, $m, $d, $calendarId);
    }

    /**
     * Returns [years, months, remainingDays] between two dates.
     *
     * Caller must pass dates in (earlier, later) order: (y1,m1,d1) ≤ (y2,m2,d2).
     *
     * $receiverIsY2: true when the caller's receiver corresponds to y2 (the later
     * argument), false when it corresponds to y1 (the earlier argument).  The anchor
     * for the day-remainder calculation is always derived from the RECEIVER's date so
     * that since() and until() produce receiver-relative results as required by the spec.
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
        $years = $y2 - $y1;
        $months = $m2 - $m1;

        if ($months < 0) {
            $years--;
            $months += 12;
        }

        // Borrow one month if d2 hasn't reached the start day (d1).
        // Compare d2 against the ORIGINAL d1 (not clamped to maxDay) to correctly
        // handle leap-day cases (e.g. Feb 29 2020 → Feb 28 2021: d2=28 < d1=29, borrow).
        if ($d2 < $d1) {
            if ($months > 0) {
                $months--;
            } else {
                $years--;
                $months = 11;
            }
        }

        if ($receiverIsY2) {
            // Anchor from y2 (receiver) going backward.
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
            // Anchor from y1 (receiver) going forward.
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
}
