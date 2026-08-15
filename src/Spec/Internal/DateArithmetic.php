<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;
use Temporal\Spec\Duration;
use Temporal\Spec\Internal\Calendar\CalendarFactory;
use Temporal\Spec\PlainDate;

/**
 * The `add()` / `subtract()` engine for `PlainDate`.
 *
 * A date has no wall clock, so a duration's sub-day time units can only matter in
 * bulk: they are balanced into whole days — walking unit by unit (hours → minutes →
 * … → nanoseconds), extracting full days at each step so that huge individual fields
 * (each up to ~2⁵³) never need the full nanosecond total in one int64 — and any
 * fractional remainder is simply discarded. Years and months then go to the calendar
 * protocol for calendrical arithmetic; weeks and days are pure day counts.
 *
 * `overflow` governs only the calendar step (clamping a day that the landing month
 * doesn't have); a result outside the representable PlainDate range always throws,
 * regardless of the option.
 *
 * @internal
 */
final class DateArithmetic
{
    /**
     * Adds $sign × $dur to $date.
     *
     * @param int $sign +1 for add(), −1 for subtract()
     * @param array<array-key, mixed>|object $options
     * @throws RangeError if the result is outside the representable range.
     */
    public static function add(PlainDate $date, int $sign, Duration $dur, array|object $options): PlainDate
    {
        // GetOptionsObject + GetTemporalOverflowOption: omitted ([]) and a bag without
        // 'overflow' default to 'constrain'; an explicit null / non-object primitive /
        // Symbol sentinel => TypeError; an 'overflow' value is coerced/validated (an
        // explicit `overflow => null` value => RangeError).
        $overflow = Options::overflowFromValue($options);

        $years = $sign * (int) $dur->years;
        $months = $sign * (int) $dur->months;
        $days = $sign * (((int) $dur->weeks * 7) + (int) $dur->days);

        // Balance sub-day time units (hours → days, etc.) using cascade arithmetic.
        // Each step: extract full days, carry remainder to the next smaller unit.
        $hours = $sign * (int) $dur->hours;
        $minutes = $sign * (int) $dur->minutes;
        $seconds = $sign * (int) $dur->seconds;
        $ms = $sign * (int) $dur->milliseconds;
        $us = $sign * (int) $dur->microseconds;
        $ns = $sign * (int) $dur->nanoseconds;

        // hours → full days + remainder hours
        $hDays = intdiv(num1: $hours, num2: 24);
        $hRem = $hours % 24;

        // carry + minutes → full days + remainder minutes
        $totalMin = ($hRem * 60) + $minutes;
        $mDays = intdiv(num1: $totalMin, num2: 1_440);
        $mRem = $totalMin % 1_440;

        // carry + seconds → full days + remainder seconds
        $totalSec = ($mRem * 60) + $seconds;
        $sDays = intdiv(num1: $totalSec, num2: 86_400);
        $sRem = $totalSec % 86_400;

        // carry + milliseconds → full days + remainder ms
        $totalMs = ($sRem * 1_000) + $ms;
        $msDays = intdiv(num1: $totalMs, num2: 86_400_000);
        $msRem = $totalMs % 86_400_000;

        // carry + microseconds → full days + remainder μs
        $totalUs = ($msRem * 1_000) + $us;
        $usDays = intdiv(num1: $totalUs, num2: 86_400_000_000);
        $usRem = $totalUs % 86_400_000_000;

        // carry + nanoseconds → full days
        $totalNs = ($usRem * 1_000) + $ns;
        $nsDays = intdiv(num1: $totalNs, num2: 86_400_000_000_000);

        $days += $hDays + $mDays + $sDays + $msDays + $usDays + $nsDays;

        // Delegate to the calendar protocol for date arithmetic.
        $cal = CalendarFactory::get($date->calendarId);
        [$newYear, $newMonth, $newDay] = $cal->dateAdd(
            $date->isoYear,
            $date->isoMonth,
            $date->isoDay,
            $years,
            $months,
            0,
            $days,
            $overflow,
        );

        // Arithmetic that crosses the valid PlainDate range always throws, regardless of overflow.
        $minJdn = CalendarMath::toJulianDay(-271_821, 4, 19);
        $maxJdn = CalendarMath::toJulianDay(275_760, 9, 13);
        $jdn = CalendarMath::toJulianDay($newYear, $newMonth, $newDay);
        if ($jdn < $minJdn || $jdn > $maxJdn) {
            throw new RangeError('PlainDate arithmetic result is outside the representable range.');
        }

        return new PlainDate($newYear, $newMonth, $newDay, $date->calendarId);
    }
}
