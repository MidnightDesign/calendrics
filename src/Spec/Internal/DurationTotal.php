<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Duration;
use Calendrics\Spec\PlainDate;
use Calendrics\Spec\ZonedDateTime;

/**
 * The engine behind {@see Duration::total()}.
 *
 * Totalling splits into three regimes that share almost no arithmetic, which is
 * why the method is large enough to live on its own:
 *
 *   - **Unanchored time units.** Days and below have fixed lengths, so the whole
 *     answer is one division. No `relativeTo` needed.
 *   - **Zoned time units.** With an IANA anchor a "day" is whatever the zone says
 *     it is, so days are walked one real transition at a time via {@see AnchorMath}.
 *   - **Calendar units.** Years, months and weeks use calendar boundaries and measure
 *     the leftover against the length of the unit that would come next — TC39
 *     RoundDuration's fractional-unit rule.
 *
 * The float expressions deliberately preserve TC39's evaluation order: float
 * addition is not associative, and reordering these terms changes the last ULP
 * that test262's precision fixtures pin down.
 *
 * @internal
 */
final class DurationTotal
{
    /** @param 'years'|'months'|'weeks'|'days'|'hours'|'minutes'|'seconds'|'milliseconds'|'microseconds'|'nanoseconds' $unit */
    public static function compute(Duration $d, string $unit, PlainDate|ZonedDateTime|null $anchor): int|float
    {
        $zdtInfo = $anchor !== null ? RelativeTo::resolveZdt($anchor) : null;
        if (
            $unit === 'years'
            || $unit === 'months'
            || $unit === 'weeks'
            || $d->years !== 0
            || $d->months !== 0
            || $d->weeks !== 0
        ) {
            if ($anchor === null) {
                throw new RangeError('Calendar duration totals require a relativeTo option.');
            }
            return self::calendar($d, $unit, RelativeTo::toPlainDateBag($anchor), $zdtInfo, $anchor->calendarId);
        }
        if ($anchor !== null && !$d->blank) {
            $range = RelativeTo::resolveAnchor($anchor);
            if ($range->zoned ? $range->targetOutOfRange($d) : $range->midnightOutOfRange()) {
                throw new RangeError('relativeTo is outside the representable range after applying duration.');
            }
        }

        if ($zdtInfo !== null) {
            $daysField = (int) $d->days;

            if ($unit === 'days') {
                $subNs =
                    ((float) $d->milliseconds * 1_000_000.0)
                    + ((float) $d->microseconds * 1_000.0)
                    + (float) $d->nanoseconds;
                $timeOnlySec =
                    ((float) $d->hours * 3_600.0)
                    + ((float) $d->minutes * 60.0)
                    + (float) $d->seconds
                    + ($subNs / 1_000_000_000.0);
                // Convert days to actual epoch seconds, then add time seconds.
                $daysSec = AnchorMath::zdtDaysToSec(
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $daysField,
                    $zdtInfo['epochSec'],
                );
                $totalSec = $daysSec + $timeOnlySec;
                // Count fractional days using actual day lengths, starting from the ZDT's real epoch.
                $result = AnchorMath::zdtTotalDays(
                    $zdtInfo['epochSec'],
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $totalSec,
                );
                return self::toIntIfWhole($result);
            }

            // For hours/minutes/seconds/etc: convert days to actual seconds, then total.
            $daysSec = AnchorMath::zdtDaysToSec(
                $zdtInfo['year'],
                $zdtInfo['month'],
                $zdtInfo['day'],
                $zdtInfo['hour'],
                $zdtInfo['minute'],
                $zdtInfo['second'],
                $zdtInfo['tzId'],
                $daysField,
                $zdtInfo['epochSec'],
            );
            // Zoned day lengths are whole seconds within the validated epoch range.
            // Keep the time remainder exact until the selected unit's final conversion.
            [$seconds, $nanoseconds] = DurationTime::parts($d);
            $seconds += (int) $daysSec;
            return self::toIntIfWhole((float) $d->sign * self::exactTotal(abs($seconds), abs($nanoseconds), $unit));
        }

        [$seconds, $nanoseconds] = DurationTime::parts($d);
        $absSec = abs($seconds) + (abs((int) $d->days) * 86_400);
        $subNs = abs($nanoseconds);

        if ($unit === 'days' && $anchor instanceof ZonedDateTime) {
            // Even an exact total needs the next day boundary to define its fraction.
            $sign = $d->sign < 0 ? -1 : 1;
            $anchor->add(new Duration(days: $sign * (intdiv($absSec, num2: 86_400) + 1)));
        }

        $result = (float) $d->sign * self::exactTotal($absSec, $subNs, $unit);

        // Return int when the result is a whole number (matches JS behavior where
        // e.g. 24 hours total('hours') is 24, not 24.0).
        return self::toIntIfWhole($result);
    }

    /**
     * Implements total() for calendar units (years/months/weeks) given an ISO PlainDate
     * relativeTo bag. Unknown keys in the bag are silently ignored per TC39.
     *
     * @param 'years'|'months'|'weeks'|'days'|'hours'|'minutes'|'seconds'|'milliseconds'|'microseconds'|'nanoseconds' $unit
     * @param array{year: int, month: int, day: int} $relativeTo
     * @param null|array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int} $zdtInfo Optional ZDT info for DST-aware day lengths.
     */
    private static function calendar(
        Duration $d,
        string $unit,
        array $relativeTo,
        ?array $zdtInfo,
        string $calendarId,
    ): int|float {
        ['year' => $year, 'month' => $month, 'day' => $day] = $relativeTo;

        $tz = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable('now', $tz)
            ->setDate($year, $month, $day)
            ->setTime(0, 0, 0);

        // Compute calendar days: apply years/months/weeks to get endDate, count days.
        // Use TC39-compliant clamped arithmetic to avoid PHP month-overflow (e.g. Jan 31 + 1M = Mar 2 in PHP).
        $calendarDateEnd = AnchorMath::applyYearsMonthsWeeks($d, $start, $calendarId);
        $calendarDays = (int) $start->diff($calendarDateEnd)->format('%r%a');

        // Total days = calendar days from calendar fields + the 'days' field.
        $nsPerDay = 86_400_000_000_000;
        $daysField = (int) $d->days;
        $totalWholeDays = $calendarDays + $daysField;

        // Calendar counting needs exact whole days and a bounded time remainder.
        // The complete time duration can exceed int64 nanoseconds while remaining valid.
        [$timeSeconds, $timeSubNs] = DurationTime::parts($d);
        $timeWholeDays = intdiv($timeSeconds, num2: 86_400);
        $timeRemainderNs = (($timeSeconds % 86_400) * 1_000_000_000) + $timeSubNs;
        $fracNs = ($timeSeconds * 1_000_000_000) + $timeSubNs;
        $calendarWholeDays = $totalWholeDays + $timeWholeDays;
        if ($zdtInfo !== null && ($unit === 'months' || $unit === 'years')) {
            [$calendarWholeDays, $timeRemainderNs] = self::zonedCalendarPosition(
                $zdtInfo,
                $totalWholeDays,
                $timeSeconds,
                $timeSubNs,
                $d->sign < 0 ? -1 : 1,
            );
        }

        // Validate that the effective end (startDate + totalWholeDays + time) is within range.
        $startEpochDay = AnchorMath::isoDateToEpochDays($year, $month, $day);
        $endEpochDay = $startEpochDay + $calendarWholeDays;

        if (
            abs($endEpochDay) > 100_000_000
            || $endEpochDay === 100_000_000 && $timeRemainderNs > 0
            || $endEpochDay === -100_000_000 && $timeRemainderNs < 0
        ) {
            throw new RangeError('Duration with relativeTo exceeds the maximum representable date range.');
        }

        $fracDay = (float) $fracNs / (float) $nsPerDay;

        // For ZDT with IANA timezone: use DST-aware day lengths for days/hours/etc.
        if ($zdtInfo !== null && $unit !== 'months' && $unit !== 'years' && $unit !== 'weeks') {
            // Convert totalWholeDays + fracNs to actual epoch seconds using DST-aware arithmetic.
            $daysActualSec = AnchorMath::zdtDaysToSec(
                $zdtInfo['year'],
                $zdtInfo['month'],
                $zdtInfo['day'],
                $zdtInfo['hour'],
                $zdtInfo['minute'],
                $zdtInfo['second'],
                $zdtInfo['tzId'],
                $totalWholeDays,
            );
            $timeOnlySec = (float) $fracNs / 1_000_000_000.0;
            $totalActualSec = $daysActualSec + $timeOnlySec;

            if ($unit === 'days') {
                $result = AnchorMath::zdtTotalDays(
                    $zdtInfo['epochSec'],
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $totalActualSec,
                );
                return self::toIntIfWhole($result);
            }

            return self::totalTimeSeconds($totalActualSec, $unit);
        }

        return match ($unit) {
            'months' => self::calendarMonths($d, $start, $calendarWholeDays, $timeRemainderNs, $zdtInfo, $calendarId),
            'years' => self::calendarYears($d, $start, $calendarWholeDays, $timeRemainderNs, $zdtInfo, $calendarId),
            'weeks' => self::toIntIfWhole(
                (float) $d->sign
                * self::divideExact(
                    (abs($totalWholeDays + $timeWholeDays) * 86_400)
                    + intdiv(abs($timeRemainderNs), num2: 1_000_000_000),
                    abs($timeRemainderNs) % 1_000_000_000,
                    604_800,
                    0,
                ),
            ),
            'days' => self::toIntIfWhole((float) $totalWholeDays + $fracDay),
            'hours' => self::toIntIfWhole(((float) $totalWholeDays * 24.0) + ((float) $fracNs / 3_600_000_000_000.0)),
            'minutes' => self::toIntIfWhole(((float) $totalWholeDays * 1_440.0) + ((float) $fracNs / 60_000_000_000.0)),
            'seconds' => self::toIntIfWhole(((float) $totalWholeDays * 86_400.0) + ((float) $fracNs / 1_000_000_000.0)),
            'milliseconds' => self::toIntIfWhole(((float) $totalWholeDays * 86_400_000.0)
            + ((float) $fracNs / 1_000_000.0)),
            'microseconds' => self::toIntIfWhole(((float) $totalWholeDays * 86_400_000_000.0)
            + ((float) $fracNs / 1_000.0)),
            'nanoseconds' => self::toIntIfWhole(((float) $totalWholeDays * 86_400_000_000_000.0) + (float) $fracNs),
        };
    }

    /**
     * Express the actual zoned endpoint as local calendar days and an elapsed remainder.
     * A time-only 24 hours can cross a 23- or 25-hour day, so fixed-day division cannot do this.
     *
     * @param array{epochSec:int,subNs:int,tzId:string,year:int,month:int,day:int,hour:int,minute:int,second:int} $anchor
     * @return array{int, int}
     */
    private static function zonedCalendarPosition(
        array $anchor,
        int $dateDays,
        int $timeSeconds,
        int $timeSubNs,
        int $sign,
    ): array {
        $dateSeconds = (int) AnchorMath::zdtDaysToSec(
            $anchor['year'],
            $anchor['month'],
            $anchor['day'],
            $anchor['hour'],
            $anchor['minute'],
            $anchor['second'],
            $anchor['tzId'],
            $dateDays,
            $anchor['epochSec'],
        );
        $deltaSeconds = $dateSeconds + $timeSeconds;
        $subNs = $anchor['subNs'] + $timeSubNs;
        $carry = CalendarMath::floorDiv($subNs, EpochLimits::NS_PER_SECOND);
        $targetSeconds = $anchor['epochSec'] + $deltaSeconds + $carry;
        $subNs -= $carry * EpochLimits::NS_PER_SECOND;
        if (
            $targetSeconds < -EpochLimits::MAX_EPOCH_SECONDS
            || $targetSeconds > EpochLimits::MAX_EPOCH_SECONDS
            || $targetSeconds === EpochLimits::MAX_EPOCH_SECONDS && $subNs > 0
        ) {
            throw new RangeError('Duration with relativeTo exceeds the maximum representable date range.');
        }
        $local = new \DateTimeImmutable(sprintf(
            '@%d',
            $targetSeconds,
        ))->setTimezone(new \DateTimeZone(TimeZoneHelper::normalizeTimezoneId($anchor['tzId'])));
        $wholeDays =
            AnchorMath::isoDateToEpochDays(
                (int) $local->format('Y'),
                (int) $local->format('m'),
                (int) $local->format('d'),
            ) - AnchorMath::isoDateToEpochDays($anchor['year'], $anchor['month'], $anchor['day']);
        while (true) {
            $boundarySeconds = (int) AnchorMath::zdtDaysToSec(
                $anchor['year'],
                $anchor['month'],
                $anchor['day'],
                $anchor['hour'],
                $anchor['minute'],
                $anchor['second'],
                $anchor['tzId'],
                $wholeDays,
                $anchor['epochSec'],
            );
            $remainder = (($deltaSeconds - $boundarySeconds) * EpochLimits::NS_PER_SECOND) + $timeSubNs;
            if (($sign * $remainder) >= 0) {
                return [$wholeDays, $remainder];
            }
            $wholeDays -= $sign;
        }
    }

    /**
     * Counts fractional months from $start spanning $wholeDays days + $fracNs nanoseconds.
     * Implements TC39 RoundDuration for unit = "months".
     *
     * @param null|array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int} $zdtInfo Optional ZDT info for DST-aware day lengths.
     */
    private static function calendarMonths(
        Duration $d,
        \DateTimeImmutable $start,
        int $wholeDays,
        int $fracNs,
        ?array $zdtInfo,
        string $calendarId,
    ): int|float {
        $absWholeDays = abs($wholeDays);
        $dir = $d->sign < 0 ? '-' : '+';
        $sign = $d->sign < 0 ? -1 : 1;
        $end = $start->modify("{$dir}{$absWholeDays} days");

        $months = 0;
        if ($calendarId === 'iso8601') {
            // Start one month below the ISO coordinate difference. The existing
            // anchor checks finish the count without skipping a constrained boundary.
            $monthDifference =
                (((int) $end->format('Y') - (int) $start->format('Y')) * 12) + (int) $end->format('n')
                - (int) $start->format('n');
            $months = max(0, abs($monthDifference) - 1);
        }
        $current = $start;
        if ($months > 0) {
            $current = AnchorMath::addMonthsClamped($start, $sign * $months, $calendarId);
        }
        while (true) {
            $next = AnchorMath::addMonthsClamped($start, $sign * ($months + 1), $calendarId);
            if ($sign > 0 ? $next > $end : $next < $end) {
                break;
            }
            $months++;
            $current = $next;
        }

        // DateInterval::$days is int|false, false only for an interval not built by
        // diff(); these always are.
        $remainingDays = intval($current->diff($end)->days);
        // Use start-anchored r2 to match TC39 spec (daysUntil(r1, r2) where
        // r2 = start + (months+1) months, not current + 1 month).
        $r2 = AnchorMath::addMonthsClamped($start, $sign * ($months + 1), $calendarId);
        // The r2 boundary may fall beyond the representable ISO date-time range
        // when the anchor sits near the limit; per TC39 RoundDuration this is a
        // RangeError.
        AnchorMath::assertCalendarBoundaryInRange($r2);
        $daysInNextMonth = intval($current->diff($r2)->days);

        if ($zdtInfo !== null) {
            $currentDays = (int) $start->diff($current)->format('%r%a');
            $nextDays = (int) $start->diff($r2)->format('%r%a');
            return self::toIntIfWhole(
                (float) $sign
                * self::zonedCalendarTotal($zdtInfo, $months, $currentDays, $nextDays, $wholeDays, $fracNs),
            );
        }
        // months + (remainingDays + |fracNs| / nsPerDay) / daysInNextMonth, as one exact
        // quotient. Summing the three terms as floats rounds three times, which lands a
        // full ulp out on values like 1 + 11/31.
        $absFracNs = $sign * $fracNs;
        $result =
            (float) $sign
            * self::divideExact(
                ((($months * $daysInNextMonth) + $remainingDays) * 86_400)
                + intdiv(num1: $absFracNs, num2: 1_000_000_000),
                $absFracNs % 1_000_000_000,
                $daysInNextMonth * 86_400,
                0,
            );

        return self::toIntIfWhole($result);
    }

    /**
     * Counts fractional years from $start spanning $wholeDays days + $fracNs nanoseconds.
     * Implements TC39 RoundDuration for unit = "years".
     */
    /**
     * @param array{epochSec:int,subNs:int,tzId:string,year:int,month:int,day:int,hour:int,minute:int,second:int}|null $zdtInfo
     */
    private static function calendarYears(
        Duration $d,
        \DateTimeImmutable $start,
        int $wholeDays,
        int $fracNs,
        ?array $zdtInfo,
        string $calendarId,
    ): int|float {
        $absWholeDays = abs($wholeDays);
        $dir = $d->sign < 0 ? '-' : '+';
        $sign = $d->sign < 0 ? -1 : 1;
        $end = $start->modify("{$dir}{$absWholeDays} days");

        $years = 0;
        if ($calendarId === 'iso8601') {
            // As for months, keep one whole unit for the original-anchor checks.
            $years = max(0, abs((int) $end->format('Y') - (int) $start->format('Y')) - 1);
        }
        $current = $start;
        if ($years > 0) {
            $current = AnchorMath::addYearsClamped($start, $sign * $years, $calendarId);
        }
        while (true) {
            $next = AnchorMath::addYearsClamped($start, $sign * ($years + 1), $calendarId);
            if ($sign > 0 ? $next > $end : $next < $end) {
                break;
            }
            $years++;
            $current = $next;
        }

        // DateInterval::$days is int|false, false only for an interval not built by
        // diff(); these always are.
        $remainingDays = intval($current->diff($end)->days);
        // Use start-anchored r2 to match TC39 spec (daysUntil(r1, r2) where
        // r2 = start + (years+1) years, not current + 1 year).
        $r2 = AnchorMath::addYearsClamped($start, $sign * ($years + 1), $calendarId);
        // The r2 boundary may fall beyond the representable ISO date-time range
        // when the anchor sits near the limit; per TC39 RoundDuration this is a
        // RangeError.
        AnchorMath::assertCalendarBoundaryInRange($r2);
        $daysInNextYear = intval($current->diff($r2)->days);
        if ($zdtInfo !== null) {
            $currentDays = (int) $start->diff($current)->format('%r%a');
            $nextDays = (int) $start->diff($r2)->format('%r%a');
            return self::toIntIfWhole(
                (float) $sign
                * self::zonedCalendarTotal($zdtInfo, $years, $currentDays, $nextDays, $wholeDays, $fracNs),
            );
        }

        // Include whole years in the exact numerator before the single Number conversion.
        // Separately rounding the day and sub-day fractions can change the final result by one ulp.
        $absFracNs = abs($fracNs);
        return self::toIntIfWhole(
            (float) $sign
            * self::divideExact(
                ((($years * $daysInNextYear) + $remainingDays) * 86_400) + intdiv($absFracNs, num2: 1_000_000_000),
                $absFracNs % 1_000_000_000,
                $daysInNextYear * 86_400,
                0,
            ),
        );
    }

    /**
     * @param array{epochSec:int,subNs:int,tzId:string,year:int,month:int,day:int,hour:int,minute:int,second:int} $anchor
     */
    private static function zonedCalendarTotal(
        array $anchor,
        int $wholeUnits,
        int $lowerDays,
        int $upperDays,
        int $targetDays,
        int $remainderNs,
    ): float {
        $positions = [];
        foreach ([$lowerDays, $upperDays, $targetDays] as $days) {
            $positions[] = (int) AnchorMath::zdtDaysToSec(
                $anchor['year'],
                $anchor['month'],
                $anchor['day'],
                $anchor['hour'],
                $anchor['minute'],
                $anchor['second'],
                $anchor['tzId'],
                $days,
                $anchor['epochSec'],
            );
        }
        [$lower, $upper, $target] = $positions;
        $denominator = abs($upper - $lower);
        return self::divideExact(
            ($wholeUnits * $denominator) + abs($target - $lower) + intdiv(abs($remainderNs), num2: 1_000_000_000),
            abs($remainderNs) % 1_000_000_000,
            $denominator,
            0,
        );
    }

    private static function toIntIfWhole(float $result): int|float
    {
        // Past int64 the cast wraps rather than saturating, and every float that large is
        // a whole number anyway, so there is nothing to gain by narrowing it.
        if (abs($result) >= 9.223_372_036_854_776e18) {
            return $result;
        }
        return fmod(num1: $result, num2: 1.0) === 0.0 ? (int) $result : $result;
    }

    /**
     * The non-negative exact value ($sec + $subNs / 1e9) seconds, expressed in $unit and
     * narrowed to the nearest double exactly once.
     *
     * The quotient is assembled as a decimal string and handed to a single strtod, which
     * is correctly rounded. Every unit length is a power of ten times a small factor
     * (60, 3600, 86400), so the power of ten is a decimal-point shift and only the small
     * factor needs dividing out — done digit by digit, which keeps the value exact
     * however far past int64 it runs.
     *
     * @param 'days'|'hours'|'minutes'|'seconds'|'milliseconds'|'microseconds'|'nanoseconds' $unit
     */
    private static function exactTotal(int $sec, int $subNs, string $unit): float
    {
        // [seconds per unit, decimal places to shift the point right] for each unit.
        [$divisor, $shift] = match ($unit) {
            'days' => [86_400, 0],
            'hours' => [3_600, 0],
            'minutes' => [60, 0],
            'seconds' => [1, 0],
            'milliseconds' => [1, 3],
            'microseconds' => [1, 6],
            'nanoseconds' => [1, 9],
        };

        return self::divideExact($sec, $subNs, $divisor, $shift);
    }

    /**
     * The non-negative exact value ($whole + $frac / 1e9) divided by $divisor, scaled by
     * 10^$shift, narrowed to the nearest double exactly once. $frac must be in [0, 1e9).
     *
     * The quotient's decimal expansion is produced digit by digit and handed to a single
     * strtod, which is correctly rounded. Building the value in float64 instead would
     * round at every step, and the intermediate does not fit int64 anyway: a whole-second
     * count near MaxTimeDuration expressed in nanoseconds needs 25 digits.
     *
     * The expansion is carried far enough past the point that truncating it cannot change
     * which double it rounds to — the remaining tail is smaller than any ulp in range.
     */
    private static function divideExact(int $whole, int $frac, int $divisor, int $shift): float
    {
        // Exact digits of ($whole + $frac / 1e9) × 1e9, i.e. the value with the point nine
        // places from the right.
        $digits = $whole . str_pad(string: (string) $frac, length: 9, pad_string: '0', pad_type: STR_PAD_LEFT);
        $pointFromRight = 9 - $shift;

        if ($divisor > 1) {
            $extra = 40;
            $length = strlen($digits);
            $quotient = '';
            $remainder = 0;
            for ($i = 0, $n = $length + $extra; $i < $n; $i++) {
                $remainder = ($remainder * 10) + ($i < $length ? (int) $digits[$i] : 0);
                $quotient .= intdiv(num1: $remainder, num2: $divisor);
                $remainder %= $divisor;
            }
            $digits = $quotient;
            $pointFromRight += $extra;
        }

        if ($pointFromRight <= 0) {
            return floatval($digits . str_repeat('0', -$pointFromRight));
        }
        $digits = str_pad(string: $digits, length: $pointFromRight + 1, pad_string: '0', pad_type: STR_PAD_LEFT);
        return floatval(sprintf(
            '%s.%s',
            substr(string: $digits, offset: 0, length: -$pointFromRight),
            substr(string: $digits, offset: -$pointFromRight),
        ));
    }

    /** @param 'hours'|'minutes'|'seconds'|'milliseconds'|'microseconds'|'nanoseconds' $unit */
    private static function totalTimeSeconds(float $seconds, string $unit): int|float
    {
        $result = match ($unit) {
            'hours' => $seconds / 3_600.0,
            'minutes' => $seconds / 60.0,
            'seconds' => $seconds,
            'milliseconds' => $seconds * 1_000.0,
            'microseconds' => $seconds * 1_000_000.0,
            'nanoseconds' => $seconds * 1_000_000_000.0,
        };
        return self::toIntIfWhole($result);
    }
}
