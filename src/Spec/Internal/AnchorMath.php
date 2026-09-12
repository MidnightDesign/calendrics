<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Duration;

/**
 * Date arithmetic performed once a `relativeTo` anchor has been resolved.
 *
 * {@see Duration::total()} and {@see Duration::round()} both have to answer two
 * questions about an anchored duration: where does the anchor land after adding
 * N calendar months or years, and how many real seconds does a calendar day span
 * at that point. Neither has a single answer without an anchor — a month is 28 to
 * 31 days, and a day is 23, 24 or 25 hours in a zone that observes DST — which is
 * why this arithmetic lives apart from {@see CalendarMath}'s anchor-free field math.
 *
 * The `zdt*` helpers take a local wall-clock date/time plus an IANA zone id and
 * measure elapsed epoch seconds across real day boundaries, so a duration that
 * spans a DST transition reports the length it actually had rather than a nominal
 * 86 400 seconds per day.
 *
 * @internal
 */
final class AnchorMath
{
    /**
     * Converts a proleptic Gregorian calendar date to an epoch-day count
     * (days since 1970-01-01 = day 0).
     *
     * Works correctly for dates outside the PHP DateTimeImmutable range, including
     * extended years up to ±999999.
     */
    public static function isoDateToEpochDays(int $year, int $month, int $day): int
    {
        return CalendarMath::toJulianDay($year, $month, $day) - 2_440_588;
    }

    /**
     * Adds $months months to $date using TC39 month arithmetic (clamp to last day of month).
     * PHP's modify('+N months') overflows (e.g. Jan 31 + 1 month = Mar 2); TC39 clamps to Feb 29.
     *
     * @param \DateTimeImmutable $date Base date (UTC midnight).
     * @param int $months Signed number of months to add (may be negative).
     */
    public static function addMonthsClamped(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        if ($months === 0) {
            return $date;
        }
        $y = (int) $date->format('Y');
        $m = (int) $date->format('n');
        $d = (int) $date->format('j');

        $m += $months;
        // Normalize month into 1-12 range, carrying into years.
        if ($m > 12) {
            $y += intdiv(num1: $m - 1, num2: 12);
            $m = (($m - 1) % 12) + 1;
        } elseif ($m < 1) {
            // For negative: m-1 makes the -1 offset work for intdiv.
            $y += CalendarMath::floorDiv($m - 1, 12);
            $m = (((($m - 1) % 12) + 12) % 12) + 1;
        }
        // Days in the target month (handles leap years). Computed via CalendarMath
        // rather than a string-built DateTimeImmutable so extended (5-/6-digit) years
        // do not trip "Double timezone specification" parse errors.
        $daysInMonth = CalendarMath::calcDaysInMonth($y, $m);
        $clampedDay = min($d, $daysInMonth);
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            ->setDate($y, $m, $clampedDay)
            ->setTime(0, 0, 0);
    }

    /**
     * Adds $years years to $date using TC39 year arithmetic (clamp Feb 29 to Feb 28 in non-leap years).
     *
     * @param \DateTimeImmutable $date Base date (UTC midnight).
     * @param int $years Signed number of years to add.
     */
    public static function addYearsClamped(\DateTimeImmutable $date, int $years): \DateTimeImmutable
    {
        if ($years === 0) {
            return $date;
        }
        $y = (int) $date->format('Y') + $years;
        $m = (int) $date->format('n');
        $d = (int) $date->format('j');
        // Computed via CalendarMath (not a string-built DateTimeImmutable) so extended
        // (5-/6-digit) years do not trip "Double timezone specification" parse errors.
        $daysInMonth = CalendarMath::calcDaysInMonth($y, $m);
        $clampedDay = min($d, $daysInMonth);
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            ->setDate($y, $m, $clampedDay)
            ->setTime(0, 0, 0);
    }

    /**
     * Throws if the given (extended-year) date lies outside the representable
     * ISO date-time range (epoch-days in [-100 000 000, +100 000 000]).
     *
     * Used to guard the calendar-unit nudge boundaries in the years / months
     * total paths: per TC39 RoundDuration, snapping a near-limit anchor to the
     * next year/month boundary (r2 = start + (n+1) units) can produce a date
     * beyond the ISO limit, which must raise a RangeError.
     */
    public static function assertCalendarBoundaryInRange(\DateTimeImmutable $boundary): void
    {
        $epochDays = self::isoDateToEpochDays(
            (int) $boundary->format('Y'),
            (int) $boundary->format('n'),
            (int) $boundary->format('j'),
        );
        if (abs($epochDays) > 100_000_000) {
            throw new RangeError('Duration with relativeTo exceeds the maximum representable date range.');
        }
    }

    /**
     * Computes the epoch seconds for a given local date/time in an IANA timezone.
     * Uses 'compatible' disambiguation (earlier for overlaps, post-transition for gaps).
     */
    public static function localToEpochSec(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        string $tzId,
    ): int {
        // Compute wall seconds (seconds since epoch if interpreted as UTC).
        // gmmktime() normalizes out-of-range fields instead of rejecting them, so for
        // valid components it never returns false on a 64-bit platform. Callers must
        // pass already-validated date/time fields: invalid fields would silently roll
        // over (e.g. month 13 -> next January), not throw. The false branch is thus
        // unreachable here and the assert exists only to narrow gmmktime()'s int|false
        // return for static analysis. On 32-bit builds gmmktime() can return false for
        // years outside 1901-2038, where the assert would surface as an error rather
        // than a clean result -- see the platform note in README.md.
        $wallSec = gmmktime($hour, $minute, $second, $month, $day, $year);
        assert($wallSec !== false);
        return TimeZoneHelper::wallSecToEpochSec($wallSec, $tzId);
    }

    /**
     * Computes the length (in seconds) of one calendar day starting from the given
     * local date/time in an IANA timezone. This is the epoch difference between
     * the same local time tomorrow and today.
     */
    public static function zdtDayLengthSec(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        string $tzId,
    ): int {
        $todayEpoch = self::localToEpochSec($year, $month, $day, $hour, $minute, $second, $tzId);
        // Add 1 calendar day to the local date.
        $dt = new \DateTimeImmutable(sprintf(
            '%04d-%02d-%02dT%02d:%02d:%02d',
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second,
        ));
        $next = $dt->modify('+1 day');
        $tomorrowEpoch = self::localToEpochSec(
            (int) $next->format('Y'),
            (int) $next->format('n'),
            (int) $next->format('j'),
            $hour,
            $minute,
            $second,
            $tzId,
        );
        return $tomorrowEpoch - $todayEpoch;
    }

    /**
     * For total('days') with ZDT: computes the fractional day count for a given
     * number of total seconds starting from the ZDT's actual epoch.
     * Uses the ZDT's real epoch for the start point (preserving ambiguous-time
     * offset) and 'compatible' disambiguation for subsequent day boundaries.
     *
     * @param int $startEpochSec The ZDT's actual epoch seconds.
     */
    public static function zdtTotalDays(
        int $startEpochSec,
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        string $tzId,
        float $totalSec,
    ): float {
        $sign = $totalSec >= 0 ? 1 : -1;
        $remaining = abs($totalSec);
        $wholeDays = 0;

        $curEpoch = $startEpochSec;
        $curYear = $year;
        $curMonth = $month;
        $curDay = $day;

        while (true) {
            $dt = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $curYear, $curMonth, $curDay));
            $next = $dt->modify($sign > 0 ? '+1 day' : '-1 day');
            $nextYear = (int) $next->format('Y');
            $nextMonth = (int) $next->format('n');
            $nextDay = (int) $next->format('j');
            $nextEpoch = self::localToEpochSec($nextYear, $nextMonth, $nextDay, $hour, $minute, $second, $tzId);
            $dayLengthSec = (float) abs($nextEpoch - $curEpoch);

            if ($remaining < $dayLengthSec) {
                $frac = $dayLengthSec > 0 ? $remaining / $dayLengthSec : 0.0;
                return (float) $sign * ((float) $wholeDays + $frac);
            }

            $remaining -= $dayLengthSec;
            $wholeDays++;
            $curEpoch = $nextEpoch;
            $curYear = $nextYear;
            $curMonth = $nextMonth;
            $curDay = $nextDay;
        }
    }

    /**
     * For total('hours'/'minutes'/etc) with ZDT: computes the total seconds
     * for a duration that has days, by adding days as calendar days to the ZDT
     * and measuring the actual epoch difference.
     *
     * @param int|null $knownStartEpoch When provided, used as the start epoch instead of
     *        re-resolving from wall time. This preserves the correct offset for ambiguous times.
     */
    public static function zdtDaysToSec(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        string $tzId,
        int $days,
        ?int $knownStartEpoch = null,
    ): float {
        if ($days === 0) {
            return 0.0;
        }
        $startEpoch = $knownStartEpoch ?? self::localToEpochSec($year, $month, $day, $hour, $minute, $second, $tzId);
        $dt = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        $end = $dt->modify(sprintf('%+d days', $days));
        $endEpoch = self::localToEpochSec(
            (int) $end->format('Y'),
            (int) $end->format('n'),
            (int) $end->format('j'),
            $hour,
            $minute,
            $second,
            $tzId,
        );
        return (float) ($endEpoch - $startEpoch);
    }

    /**
     * For round() with ZDT: balances time nanoseconds into whole days + remaining ns,
     * using actual day lengths. Returns [wholeDays, remainingNs].
     *
     * @param int $direction 1 for positive durations (forward), -1 for negative (backward).
     * @param int|null $knownStartEpoch Use the ZDT's actual epoch for the initial date to
     *        preserve the correct offset for ambiguous times.
     * @return array{int, int}
     */
    public static function zdtBalanceTimeToDays(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        string $tzId,
        int $absTimeNs,
        int $absDays,
        int $direction = 1,
        ?int $knownStartEpoch = null,
    ): array {
        // Start from the date after adding absDays calendar days in the given direction.
        $dt = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        if ($absDays > 0) {
            $dtAfterDays = $dt->modify(sprintf('%+d days', $direction * $absDays));
        } else {
            $dtAfterDays = $dt;
        }
        $curYear = (int) $dtAfterDays->format('Y');
        $curMonth = (int) $dtAfterDays->format('n');
        $curDay = (int) $dtAfterDays->format('j');

        $totalDays = $absDays;
        $remaining = $absTimeNs;

        $step = $direction >= 0 ? '+1 day' : '-1 day';
        // Use the known epoch only for the first iteration when no days were added.
        $useKnownEpoch = $knownStartEpoch !== null && $absDays === 0;

        while (true) {
            $curEpoch = $useKnownEpoch && $knownStartEpoch !== null
                ? $knownStartEpoch
                : self::localToEpochSec($curYear, $curMonth, $curDay, $hour, $minute, $second, $tzId);
            $useKnownEpoch = false;
            $nextDt = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $curYear, $curMonth, $curDay))->modify($step);
            $nextYear = (int) $nextDt->format('Y');
            $nextMonth = (int) $nextDt->format('n');
            $nextDay = (int) $nextDt->format('j');
            $nextEpoch = self::localToEpochSec($nextYear, $nextMonth, $nextDay, $hour, $minute, $second, $tzId);
            $dayLengthNs = abs($nextEpoch - $curEpoch) * 1_000_000_000;

            if ($remaining < $dayLengthNs) {
                return [$totalDays, $remaining];
            }

            $remaining -= $dayLengthNs;
            $totalDays++;
            $curYear = $nextYear;
            $curMonth = $nextMonth;
            $curDay = $nextDay;
        }
    }

    /**
     * Applies $d's calendar fields (years, months, weeks) and day field to a start
     * date, returning [endDate, calendarDays] where calendarDays is the signed
     * day-count between start and end.
     *
     * @param \DateTimeImmutable $startDate UTC midnight on the start date.
     * @return array{0: \DateTimeImmutable, 1: int}
     * @throws RangeError if the resulting date falls outside the representable range.
     */
    public static function applyCalendarToDate(Duration $d, \DateTimeImmutable $startDate): array
    {
        $endDate = $startDate;
        // Apply years, months, weeks with TC39-compliant clamped arithmetic.
        $applySign = $d->sign;
        if ((int) $d->years !== 0) {
            $endDate = self::addYearsClamped($endDate, $applySign * abs((int) $d->years));
        }
        if ((int) $d->months !== 0) {
            $endDate = self::addMonthsClamped($endDate, $applySign * abs((int) $d->months));
        }
        if ((int) $d->weeks !== 0) {
            $awDays = $applySign * abs((int) $d->weeks) * 7;
            $endDate = $endDate->modify(sprintf('%+d days', $awDays));
        }
        // Apply days.
        $calDays = (int) $d->days;
        if ($calDays !== 0) {
            $absD = abs($calDays);
            $endDate = $calDays > 0 ? $endDate->modify("+{$absD} days") : $endDate->modify("-{$absD} days");
        }
        $calendarDays = (int) $startDate->diff($endDate)->format('%r%a');
        // Validate: epoch-day range ±100 000 000 (matches Temporal spec PlainDate limits).
        if (abs($calendarDays) > 100_000_000) {
            throw new RangeError('Duration applied to relativeTo produces a date outside the representable range.');
        }
        return [$endDate, $calendarDays];
    }

    /**
     * Computes the signed total nanoseconds $d represents when anchored to a
     * relativeTo value. Used for Duration::compare() with calendar units.
     *
     * @param mixed $rt Validated relativeTo value.
     * @throws RangeError if the total overflows the 64-bit nanosecond range.
     */
    public static function totalNsFromRelativeTo(Duration $d, mixed $rt): int
    {
        $bag = RelativeTo::toPlainDateBag($rt);
        $tz = new \DateTimeZone('UTC');
        $startDate = new \DateTimeImmutable('now', $tz)
            ->setDate($bag['year'], $bag['month'], $bag['day'])
            ->setTime(0, 0, 0);

        // applyCalendarToDate throws RangeError if totalDays > ±100M.
        [, $calendarDays] = self::applyCalendarToDate($d, $startDate);

        $timeNs =
            ((int) $d->hours * 3_600_000_000_000)
            + ((int) $d->minutes * 60_000_000_000)
            + ((int) $d->seconds * 1_000_000_000)
            + ((int) $d->milliseconds * 1_000_000)
            + ((int) $d->microseconds * 1_000)
            + (int) $d->nanoseconds;

        // For ZDT with IANA timezone: use actual epoch seconds for calendar days.
        $zdtInfo = RelativeTo::resolveZdt($rt);
        if ($zdtInfo !== null) {
            $actualDaysSec = (int) self::zdtDaysToSec(
                $zdtInfo['year'],
                $zdtInfo['month'],
                $zdtInfo['day'],
                $zdtInfo['hour'],
                $zdtInfo['minute'],
                $zdtInfo['second'],
                $zdtInfo['tzId'],
                $calendarDays,
            );
            $dayNs = $actualDaysSec * 1_000_000_000;
            $totalNsF = (float) $dayNs + (float) $timeNs;
            if ($totalNsF > (float) PHP_INT_MAX || $totalNsF < (float) PHP_INT_MIN) {
                throw new RangeError('Duration nanosecond total overflows the 64-bit range.');
            }
            return $dayNs + $timeNs;
        }

        $nsPerDay = 86_400_000_000_000;
        // Guard against int64 overflow when combining calendar days and time nanoseconds.
        $totalNsF = ((float) $calendarDays * (float) $nsPerDay) + (float) $timeNs;
        if ($totalNsF > (float) PHP_INT_MAX || $totalNsF < (float) PHP_INT_MIN) {
            throw new RangeError('Duration nanosecond total overflows the 64-bit range.');
        }

        return ($calendarDays * $nsPerDay) + $timeNs;
    }

    /**
     * Computes the total epoch-second offset for a duration added to a ZDT.
     * Days are added as calendar days (DST-aware), time fields as seconds.
     *
     * @param array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int} $zdtInfo
     */
    public static function durationToEpochOffsetSec(Duration $d, array $zdtInfo): float
    {
        // Pass the ZDT's actual epoch so that sub-minute offsets (e.g. Pacific/Niue
        // -11:19:40 vs -11:20:00) are preserved instead of being re-resolved via
        // compatible disambiguation.
        $daysSec = self::zdtDaysToSec(
            $zdtInfo['year'],
            $zdtInfo['month'],
            $zdtInfo['day'],
            $zdtInfo['hour'],
            $zdtInfo['minute'],
            $zdtInfo['second'],
            $zdtInfo['tzId'],
            (int) $d->days,
            $zdtInfo['epochSec'],
        );
        $timeSec =
            ((float) $d->hours * 3_600.0)
            + ((float) $d->minutes * 60.0)
            + (float) $d->seconds
            + (
                (
                    ((float) $d->milliseconds * 1_000_000.0)
                    + ((float) $d->microseconds * 1_000.0)
                    + (float) $d->nanoseconds
                )
                / 1_000_000_000.0
            );
        return $daysSec + $timeSec;
    }
}
