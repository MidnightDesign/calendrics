<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;
use Temporal\Spec\Duration;
use Temporal\Spec\Internal\Calendar\CalendarFactory;
use Temporal\Spec\PlainDateTime;

/**
 * Adding a `Duration` to a `PlainDateTime`.
 *
 * Without a time zone every day is exactly 86 400 seconds, so unlike the zoned engine
 * the time part can be collapsed into whole days up front. The collapse is done one
 * unit at a time — hours to days, then the remainder carried into minutes, and so on —
 * because a Duration's individual fields may each be near int64 range and a single
 * combined multiplication would overflow. The extracted days join the calendar part
 * (years, months, weeks, days), which the calendar applies to the date; the sub-day
 * remainder is then re-added to the wall-clock time, carrying at most one more day.
 *
 * @internal
 */
final class DateTimeArithmetic
{
    /**
     * Adds ($sign × $dur) to $dt.
     *
     * @param int $sign 1 for `add()`, -1 for `subtract()`.
     * @param array<array-key, mixed>|object $options Options bag; `overflow` is read.
     * @throws RangeError if the result leaves the representable range, or if `overflow` is
     *                    `'reject'` and the calendar part lands on a day the month lacks.
     */
    public static function add(PlainDateTime $dt, int $sign, Duration $dur, array|object $options): PlainDateTime
    {
        // GetOptionsObject + GetTemporalOverflowOption: omitted ([]) and a bag without
        // 'overflow' default to 'constrain'; an explicit null / non-object primitive /
        // Symbol sentinel => TypeError; an 'overflow' value is coerced/validated (an
        // explicit `overflow => null` value => RangeError).
        $overflow = Options::overflowFromValue($options);

        $years = $sign * (int) $dur->years;
        $months = $sign * (int) $dur->months;
        $days = $sign * (((int) $dur->weeks * 7) + (int) $dur->days);

        // Balance time units to nanoseconds, then extract whole days.
        $hours = $sign * (int) $dur->hours;
        $minutes = $sign * (int) $dur->minutes;
        $seconds = $sign * (int) $dur->seconds;
        $ms = $sign * (int) $dur->milliseconds;
        $us = $sign * (int) $dur->microseconds;
        $ns = $sign * (int) $dur->nanoseconds;

        // Balance time units using the same step-by-step carry approach as PlainDate,
        // to avoid int64 overflow with large Duration field values.
        // Each step extracts whole days and passes the remainder to the next smaller unit.

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

        // carry + nanoseconds → full days + remainder ns
        $totalNs = ($usRem * 1_000) + $ns;
        $nsDays = intdiv(num1: $totalNs, num2: 86_400_000_000_000);
        $nsRem = $totalNs % 86_400_000_000_000;

        $days += $hDays + $mDays + $sDays + $msDays + $usDays + $nsDays;

        // Reconstruct time-of-day from the accumulated remainders.
        // $nsRem is the total sub-day nanoseconds; it may be negative when the
        // duration is negative. Normalize to [0, NS_PER_DAY) using floor-div.
        $currentTimeNs = TimeOfDay::toNs(
            $dt->hour,
            $dt->minute,
            $dt->second,
            $dt->millisecond,
            $dt->microsecond,
            $dt->nanosecond,
        );
        $newTimeNs = $currentTimeNs + $nsRem;

        // Carry overflow days from the time component.
        if ($newTimeNs < 0) {
            $overflowDays = (int) floor($newTimeNs / TimeOfDay::NS_PER_DAY);
            $newTimeNs -= $overflowDays * TimeOfDay::NS_PER_DAY;
        } else {
            $overflowDays = intdiv(num1: $newTimeNs, num2: TimeOfDay::NS_PER_DAY);
            $newTimeNs %= TimeOfDay::NS_PER_DAY;
        }

        $days += $overflowDays;

        // Delegate date arithmetic to the calendar protocol.
        $cal = CalendarFactory::get($dt->calendarId);
        [$newYear, $newMonth, $newDay] = $cal->dateAdd(
            $dt->isoYear,
            $dt->isoMonth,
            $dt->isoDay,
            $years,
            $months,
            0,
            $days,
            $overflow,
        );

        $minJdn = CalendarMath::toJulianDay(-271_821, 4, 19);
        $maxJdn = CalendarMath::toJulianDay(275_760, 9, 13);
        $jdn = CalendarMath::toJulianDay($newYear, $newMonth, $newDay);
        if ($jdn < $minJdn || $jdn > $maxJdn) {
            throw new RangeError('PlainDateTime arithmetic result is outside the representable range.');
        }

        [$h, $min, $sec, $msR, $usR, $nsR] = TimeOfDay::decompose($newTimeNs);

        return new PlainDateTime($newYear, $newMonth, $newDay, $h, $min, $sec, $msR, $usR, $nsR, $dt->calendarId);
    }
}
