<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;
use Temporal\Spec\Duration;
use Temporal\Spec\Internal\Calendar\CalendarFactory;
use Temporal\Spec\ZonedDateTime;

/**
 * Adding a `Duration` to a `ZonedDateTime`.
 *
 * The two halves of a Duration move a zoned instant in incompatible ways, and TC39's
 * AddZonedDateTime keeps them in a fixed order for that reason. Calendar units (years,
 * months, weeks, days) are *wall-clock* quantities: adding one day means the same clock
 * time tomorrow, which is 23 or 25 hours later across a DST boundary. Time units are
 * *elapsed* quantities: adding 24 hours means 86 400 real seconds, landing on a different
 * clock time when a transition intervenes.
 *
 * So the calendar part is applied to the local date and re-resolved through the zone to
 * an intermediate instant, and only then are the time units added to that instant as
 * nanoseconds. Reversing the order, or collapsing days into hours, gives the wrong answer
 * on exactly the days people notice.
 *
 * @internal
 */
final class ZonedArithmetic
{
    /**
     * Adds ($sign × $dur) to $zdt.
     *
     * @param int $sign 1 for `add()`, -1 for `subtract()`.
     * @param array<array-key, mixed>|object|null $options Options bag; `overflow` is read.
     * @throws RangeError if the result leaves the representable range, or if `overflow` is
     *                    `'reject'` and the calendar part lands on a day the month lacks.
     */
    public static function add(ZonedDateTime $zdt, int $sign, Duration $dur, array|object|null $options): ZonedDateTime
    {
        // An instant past int64 nanoseconds is carried as (seconds, sub-ns) behind a
        // sentinel. The calendar path goes through localComponents(), which reads those
        // carried parts, but the pure-time path adds to the nanosecond field directly and
        // has nothing to add to when the field is a bare clamped sentinel.
        if ($zdt->isClampedEpoch()) {
            $isBlank =
                $dur->years === 0
                && $dur->months === 0
                && $dur->weeks === 0
                && $dur->days === 0
                && $dur->hours === 0
                && $dur->minutes === 0
                && $dur->seconds === 0
                && $dur->milliseconds === 0
                && $dur->microseconds === 0
                && $dur->nanoseconds === 0;
            $hasCalendar = $dur->years !== 0 || $dur->months !== 0 || $dur->weeks !== 0 || $dur->days !== 0;
            if (!$isBlank && !$hasCalendar) {
                throw new RangeError('ZonedDateTime arithmetic result is outside the representable range.');
            }
        }

        $overflow = Options::overflowFromBag($options);

        $years = $sign * (int) $dur->years;
        $months = $sign * (int) $dur->months;
        $weeks = $sign * (int) $dur->weeks;
        $days = $sign * (int) $dur->days;
        $hours = $sign * (int) $dur->hours;
        $minutes = $sign * (int) $dur->minutes;
        $seconds = $sign * (int) $dur->seconds;
        $ms = $sign * (int) $dur->milliseconds;
        $us = $sign * (int) $dur->microseconds;
        $ns = $sign * (int) $dur->nanoseconds;

        if ($years !== 0 || $months !== 0 || $weeks !== 0 || $days !== 0) {
            return self::addCalendarThenTime(
                $zdt,
                $years,
                $months,
                $weeks,
                $days,
                ($hours * 3_600_000_000_000)
                + ($minutes * 60_000_000_000)
                + ($seconds * EpochLimits::NS_PER_SECOND)
                + ($ms * EpochLimits::NS_PER_MILLISECOND)
                + ($us * EpochLimits::NS_PER_MICROSECOND)
                + $ns,
                $overflow,
            );
        }

        return self::addTimeOnly($zdt, $hours, $minutes, $seconds, $ms, $us, $ns);
    }

    /**
     * Applies the calendar part to the local date, re-resolves through the zone, then adds
     * the time part to the resulting instant.
     */
    private static function addCalendarThenTime(
        ZonedDateTime $zdt,
        int $years,
        int $months,
        int $weeks,
        int $days,
        int $timeNs,
        string $overflow,
    ): ZonedDateTime {
        $lc = $zdt->localComponents();

        if ($zdt->calendarId !== 'iso8601') {
            [$newYear, $newMonth, $newDay] = CalendarFactory::get($zdt->calendarId)->dateAdd(
                $lc['year'],
                $lc['month'],
                $lc['day'],
                $years,
                $months,
                $weeks,
                $days,
                $overflow,
            );
        } else {
            [$newYear, $newMonth, $newDay] = self::addIsoDate(
                $lc['year'],
                $lc['month'],
                $lc['day'],
                $years,
                $months,
                ($weeks * 7) + $days,
                $overflow,
            );
        }

        if ($timeNs === 0) {
            return ZonedFields::fromLocal(
                $newYear,
                $newMonth,
                $newDay,
                $lc['hour'],
                $lc['minute'],
                $lc['second'],
                $lc['millisecond'],
                $lc['microsecond'],
                $lc['nanosecond'],
                $zdt->timeZoneId,
                $zdt->calendarId,
                'compatible',
            );
        }

        // Resolve the new local date, keeping the original clock time, to an intermediate
        // instant — this is the step that makes a day mean a day and not 24 hours.
        $epochDays = CalendarMath::toJulianDay($newYear, $newMonth, $newDay) - 2_440_588;
        $wallSec = ($epochDays * 86_400) + ($lc['hour'] * 3600) + ($lc['minute'] * 60) + $lc['second'];
        $intermediateEpochSec = TimeZoneHelper::wallSecToEpochSec(
            $wallSec,
            ZoneOffsets::canonicalize($zdt->timeZoneId),
            'compatible',
        );
        $intermediateSubNs =
            ($lc['millisecond'] * EpochLimits::NS_PER_MILLISECOND)
            + ($lc['microsecond'] * EpochLimits::NS_PER_MICROSECOND)
            + $lc['nanosecond'];

        $totalSubNs = $intermediateSubNs + $timeNs;
        $overflowSec = CalendarMath::floorDiv($totalSubNs, EpochLimits::NS_PER_SECOND);

        return ZonedDateTime::fromEpochParts(
            $intermediateEpochSec + $overflowSec,
            $totalSubNs - ($overflowSec * EpochLimits::NS_PER_SECOND),
            $zdt->timeZoneId,
            $zdt->calendarId,
        );
    }

    /**
     * Adds years, months and whole days to an ISO date.
     *
     * Years and months land first, with the day clamped or rejected against the resulting
     * month's length; the day count is then applied through the Julian day number, which
     * keeps month and year carries correct without a normalization loop.
     *
     * @return array{0: int, 1: int, 2: int} [year, month, day]
     * @throws RangeError if $overflow is `'reject'` and the day exceeds the new month.
     */
    private static function addIsoDate(
        int $year,
        int $month,
        int $day,
        int $addYears,
        int $addMonths,
        int $addDays,
        string $overflow,
    ): array {
        $newYear = $year + $addYears;
        $newMonth = $month + $addMonths;

        if ($newMonth > 12) {
            $newYear += intdiv(num1: $newMonth - 1, num2: 12);
            $newMonth = (($newMonth - 1) % 12) + 1;
        } elseif ($newMonth < 1) {
            $newYear += intdiv(num1: $newMonth - 12, num2: 12);
            $newMonth = (((($newMonth - 1) % 12) + 12) % 12) + 1;
        }

        $newDay = $day;
        $maxDay = CalendarMath::calcDaysInMonth($newYear, $newMonth);
        if ($newDay > $maxDay) {
            if ($overflow !== 'constrain') {
                throw new RangeError("Day {$newDay} is out of range for {$newYear}-{$newMonth}.");
            }
            $newDay = $maxDay;
        }

        return CalendarMath::fromJulianDay(CalendarMath::toJulianDay($newYear, $newMonth, $newDay) + $addDays);
    }

    /**
     * Adds a pure-time Duration by elapsed nanoseconds.
     *
     * The units are carried into whole days one step at a time rather than multiplied out,
     * because a Duration is allowed to hold field values whose combined nanosecond count
     * overflows int64 even though the resulting instant is perfectly representable.
     */
    private static function addTimeOnly(
        ZonedDateTime $zdt,
        int $hours,
        int $minutes,
        int $seconds,
        int $ms,
        int $us,
        int $ns,
    ): ZonedDateTime {
        $hDays = intdiv(num1: $hours, num2: 24);
        $hRem = $hours % 24;

        $totalMin = ($hRem * 60) + $minutes;
        $mDays = intdiv(num1: $totalMin, num2: 1_440);
        $mRem = $totalMin % 1_440;

        $totalSec = ($mRem * 60) + $seconds;
        $sDays = intdiv(num1: $totalSec, num2: 86_400);
        $sRem = $totalSec % 86_400;

        $totalMs = ($sRem * 1_000) + $ms;
        $msDays = intdiv(num1: $totalMs, num2: 86_400_000);
        $msRem = $totalMs % 86_400_000;

        $totalUs = ($msRem * 1_000) + $us;
        $usDays = intdiv(num1: $totalUs, num2: 86_400_000_000);
        $usRem = $totalUs % 86_400_000_000;

        $totalNsRem = ($usRem * 1_000) + $ns;
        $nsDays = intdiv(num1: $totalNsRem, num2: 86_400_000_000_000);
        $nsRem = $totalNsRem % 86_400_000_000_000;

        $totalDays = $hDays + $mDays + $sDays + $msDays + $usDays + $nsDays;

        [$epochSec, $subNsOrig] = $zdt->epochParts();

        $newEpochSec = $epochSec + ($totalDays * 86_400);
        $newSubNs = $subNsOrig + $nsRem;

        if ($newSubNs >= EpochLimits::NS_PER_SECOND) {
            $carry = intdiv(num1: $newSubNs, num2: EpochLimits::NS_PER_SECOND);
            $newEpochSec += $carry;
            $newSubNs -= $carry * EpochLimits::NS_PER_SECOND;
        } elseif ($newSubNs < 0) {
            $carry = (int) ceil(-$newSubNs / EpochLimits::NS_PER_SECOND);
            $newEpochSec -= $carry;
            $newSubNs += $carry * EpochLimits::NS_PER_SECOND;
        }

        return ZonedDateTime::fromEpochParts($newEpochSec, $newSubNs, $zdt->timeZoneId, $zdt->calendarId);
    }
}
