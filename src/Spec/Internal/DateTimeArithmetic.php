<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Duration;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\PlainDateTime;

/**
 * The `add()` / `subtract()` engine for `PlainDateTime`.
 *
 * A plain datetime has no zone, so unlike the zoned flavor there is only one notion of
 * a day here — exactly 24 hours — and the whole computation is: balance the duration's
 * time units into whole days plus a sub-day remainder, add the remainder to the wall
 * clock (carrying any overflow day), then hand years/months/days to the calendar
 * protocol for date arithmetic.
 *
 * Exact whole seconds and subsecond nanoseconds are split before extracting days,
 * so large duration fields never need the full nanosecond total in one int64.
 *
 * @internal
 */
final class DateTimeArithmetic
{
    /**
     * Adds $sign × $dur to $dt.
     *
     * @param int $sign +1 for add(), −1 for subtract()
     * @param array<array-key, mixed>|object $options
     * @throws RangeError if the result is outside the representable range.
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

        // Split before applying the operation sign so large Number fields and
        // PHP_INT_MIN never pass through a narrowing cast or overflowing negation.
        [$timeSeconds, $subNs] = DurationTime::parts($dur);
        $days += $sign * intdiv($timeSeconds, num2: 86_400);
        $nsRem = $sign * ((($timeSeconds % 86_400) * EpochLimits::NS_PER_SECOND) + $subNs);

        // Reconstruct time-of-day from the accumulated remainders.
        // $nsRem is the total sub-day nanoseconds; it may be negative when the
        // duration is negative. Normalise to [0, NS_PER_DAY) using floor-div.
        $currentTimeNs = CalendarMath::timeToNs(
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
            $overflowDays = (int) floor($newTimeNs / EpochLimits::NS_PER_DAY);
            $newTimeNs -= $overflowDays * EpochLimits::NS_PER_DAY;
        } else {
            $overflowDays = intdiv(num1: $newTimeNs, num2: EpochLimits::NS_PER_DAY);
            $newTimeNs %= EpochLimits::NS_PER_DAY;
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

        [$h, $min, $sec, $msR, $usR, $nsR] = CalendarMath::nsToTime($newTimeNs);

        return new PlainDateTime($newYear, $newMonth, $newDay, $h, $min, $sec, $msR, $usR, $nsR, $dt->calendarId);
    }
}
