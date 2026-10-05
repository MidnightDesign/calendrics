<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Duration;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\PlainDate;

/**
 * The `add()` / `subtract()` engine for `PlainDate`.
 *
 * A date has no wall clock, so its duration time fields contribute only whole days.
 * Exact seconds and a subsecond remainder are split before extracting those days;
 * the remaining fraction is discarded toward zero. Years and months then go to the
 * calendar protocol, while weeks and days contribute pure day counts.
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

        // Split exact time fields before extracting whole days. Sub-day time is
        // discarded toward zero, including for negative durations.
        [$timeSeconds] = DurationTime::parts($dur);
        $days += $sign * intdiv($timeSeconds, num2: 86_400);

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
