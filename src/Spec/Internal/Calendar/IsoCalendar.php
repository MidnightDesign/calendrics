<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal\Calendar;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Internal\CalendarMath;

/**
 * ISO 8601 calendar implementation.
 *
 * For ISO, calendar fields are identical to the stored ISO fields. This class
 * consolidates all ISO-specific calendar logic previously scattered across
 * CalendarMath and the Temporal types.
 *
 * @internal
 */
final class IsoCalendar implements CalendarProtocol
{
    // -------------------------------------------------------------------------
    // ISO -> Calendar field projection (identity for ISO)
    // -------------------------------------------------------------------------

    #[\Override]
    public function year(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return $isoYear;
    }

    #[\Override]
    public function month(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return $isoMonth;
    }

    #[\Override]
    public function day(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return $isoDay;
    }

    #[\Override]
    public function era(int $isoYear, int $isoMonth, int $isoDay): ?string
    {
        return null;
    }

    #[\Override]
    public function eraYear(int $isoYear, int $isoMonth, int $isoDay): ?int
    {
        return null;
    }

    #[\Override]
    public function monthCode(int $isoYear, int $isoMonth, int $isoDay): string
    {
        return sprintf('M%02d', $isoMonth);
    }

    #[\Override]
    public function dayOfYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return CalendarMath::calcDayOfYear($isoYear, $isoMonth, $isoDay);
    }

    #[\Override]
    public function daysInMonth(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return CalendarMath::calcDaysInMonth($isoYear, $isoMonth);
    }

    #[\Override]
    public function daysInYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return CalendarMath::isLeapYear($isoYear) ? 366 : 365;
    }

    #[\Override]
    public function monthsInYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return 12;
    }

    #[\Override]
    public function inLeapYear(int $isoYear, int $isoMonth, int $isoDay): bool
    {
        return CalendarMath::isLeapYear($isoYear);
    }

    // -------------------------------------------------------------------------
    // Calendar -> ISO field resolution (identity for ISO)
    // -------------------------------------------------------------------------

    #[\Override]
    public function calendarToIso(int $calYear, int $calMonth, int $calDay, string $overflow): array
    {
        return self::regulateIsoDate($calYear, $calMonth, $calDay, $overflow);
    }

    #[\Override]
    public function calendarToIsoFromMonthCode(int $calYear, string $monthCode, int $calDay, string $overflow): array
    {
        $month = CalendarMath::monthCodeToMonth($monthCode);

        return self::regulateIsoDate($calYear, $month, $calDay, $overflow);
    }

    // -------------------------------------------------------------------------
    // Calendar-aware arithmetic
    // -------------------------------------------------------------------------

    #[\Override]
    public function dateAdd(
        int $isoYear,
        int $isoMonth,
        int $isoDay,
        int $years,
        int $months,
        int $weeks,
        int $days,
        string $overflow,
    ): array {
        [$newYear, $newMonth] = self::normalizeMonth($isoYear + $years, $isoMonth + $months);

        // Clamp or reject day within new month.
        $newDay = $isoDay;
        $maxDay = CalendarMath::calcDaysInMonth($newYear, $newMonth);
        if ($newDay > $maxDay) {
            if ($overflow === 'constrain') {
                $newDay = $maxDay;
            } else {
                throw new RangeError("Day {$newDay} is out of range for {$newYear}-{$newMonth}.");
            }
        }

        // Add weeks and days via Julian Day Number.
        $totalDays = ($weeks * 7) + $days;
        if ($totalDays !== 0) {
            $jdn = CalendarMath::toJulianDay($newYear, $newMonth, $newDay) + $totalDays;
            [$newYear, $newMonth, $newDay] = CalendarMath::fromJulianDay($jdn);
        }

        return [$newYear, $newMonth, $newDay];
    }

    #[\Override]
    public function dateUntil(
        int $isoY1,
        int $isoM1,
        int $isoD1,
        int $isoY2,
        int $isoM2,
        int $isoD2,
        string $largestUnit,
        bool $receiverIsLater = false,
    ): array {
        // Year/month decomposition.
        $sign =
            $isoY2 > $isoY1 || $isoY2 === $isoY1 && ($isoM2 > $isoM1 || $isoM2 === $isoM1 && $isoD2 >= $isoD1) ? 1 : -1;

        if ($sign < 0) {
            [$isoY1, $isoM1, $isoD1, $isoY2, $isoM2, $isoD2] = [$isoY2, $isoM2, $isoD2, $isoY1, $isoM1, $isoD1];
        }

        $years = $isoY2 - $isoY1;
        $months = $isoM2 - $isoM1;

        if ($months < 0) {
            $years--;
            $months += 12;
        }

        // Borrow a month when date2's day has not reached date1's. The comparison uses
        // the original day, not one clamped to the month's length, so Feb 29 2020 → Feb
        // 28 2021 borrows (28 < 29) and comes out as 11 months rather than a whole year.
        if ($isoD2 < $isoD1) {
            if ($months > 0) {
                $months--;
            } else {
                $years--;
                $months = 11;
            }
        }

        if ($largestUnit === 'month') {
            $months += $years * 12;
            $years = 0;
        }

        // Compute the remaining days by anchoring the years+months span at the receiver
        // and measuring what is left over to the other endpoint. Date1 is the earlier
        // endpoint after the swap above, so the receiver is date2 exactly when it is the
        // later of the two.
        if ($receiverIsLater) {
            [$anchorYear, $anchorMonth] = self::normalizeMonth($isoY2 - $years, $isoM2 - $months);
            $anchorDay = min($isoD2, CalendarMath::calcDaysInMonth($anchorYear, $anchorMonth));
            $days =
                CalendarMath::toJulianDay($anchorYear, $anchorMonth, $anchorDay)
                - CalendarMath::toJulianDay($isoY1, $isoM1, $isoD1);
        } else {
            [$anchorYear, $anchorMonth] = self::normalizeMonth($isoY1 + $years, $isoM1 + $months);
            $anchorDay = min($isoD1, CalendarMath::calcDaysInMonth($anchorYear, $anchorMonth));
            $days =
                CalendarMath::toJulianDay($isoY2, $isoM2, $isoD2)
                - CalendarMath::toJulianDay($anchorYear, $anchorMonth, $anchorDay);
        }

        return [$sign * $years, $sign * $months, 0, $sign * $days];
    }

    // -------------------------------------------------------------------------
    // Month code utilities
    // -------------------------------------------------------------------------

    #[\Override]
    public function monthCodeToMonth(string $monthCode, int $calYear, string $overflow = 'reject'): int
    {
        // ISO has no leap months, so every valid month code exists in every year;
        // overflow is irrelevant here.
        unset($overflow);

        return CalendarMath::monthCodeToMonth($monthCode);
    }

    #[\Override]
    public function resolveEra(string $era, int $eraYear): ?int
    {
        // ISO calendar has no eras.
        return null;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Carries an out-of-range month into the year, so that any integer month names the
     * same point on the timeline as its normalized 1-12 form ("month 0" is the previous
     * December, "month 13" the next January).
     *
     * @return array{0: int, 1: int<1, 12>} [year, month]
     */
    private static function normalizeMonth(int $year, int $month): array
    {
        $zeroBased = $month - 1;

        return [$year + CalendarMath::floorDiv($zeroBased, 12), ((($zeroBased % 12) + 12) % 12) + 1];
    }

    /**
     * Validates and optionally constrains an ISO date.
     *
     * @return array{0: int, 1: int, 2: int} [isoYear, isoMonth, isoDay]
     */
    private static function regulateIsoDate(int $year, int $month, int $day, string $overflow): array
    {
        if ($overflow === 'constrain') {
            $month = max(1, min(12, $month));
            $day = max(1, min(CalendarMath::calcDaysInMonth($year, $month), $day));
        } else {
            if ($month < 1 || $month > 12) {
                throw new RangeError("Month {$month} is out of range 1–12.");
            }
            $maxDay = CalendarMath::calcDaysInMonth($year, $month);
            if ($day < 1 || $day > $maxDay) {
                throw new RangeError("Day {$day} is out of range 1–{$maxDay} for {$year}-{$month}.");
            }
        }

        return [$year, $month, $day];
    }
}
