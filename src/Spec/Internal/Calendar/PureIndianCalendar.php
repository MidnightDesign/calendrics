<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal\Calendar;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Internal\CalendarMath;

/**
 * Pure PHP implementation of the Indian National (Saka) calendar.
 *
 * The Indian calendar has a fixed relationship to the proleptic Gregorian calendar:
 *   - Saka year Y begins on March 22 (or March 21 in Gregorian leap years) of ISO year Y+78
 *   - Month 1 (Chaitra) has 30 days (31 in leap years)
 *   - Months 2-6 (Vaishakha through Bhadra) have 31 days each
 *   - Months 7-12 (Ashwin through Phalguna) have 30 days each
 *
 * A pure implementation avoids ICU overflow for extreme proleptic dates.
 *
 * @internal
 */
final class PureIndianCalendar implements CalendarProtocol
{
    /** Saka year = ISO year - YEAR_OFFSET */
    private const int YEAR_OFFSET = 78;

    /**
     * Returns month lengths for the Indian calendar.
     *
     * @return array<int, int> Month number (1-12) to days
     */
    private static function monthLengths(bool $isLeap): array
    {
        return [
            1 => $isLeap ? 31 : 30,
            2 => 31,
            3 => 31,
            4 => 31,
            5 => 31,
            6 => 31,
            7 => 30,
            8 => 30,
            9 => 30,
            10 => 30,
            11 => 30,
            12 => 30,
        ];
    }

    /**
     * Whether the given Saka year is a leap year (determined by ISO leap year rules
     * applied to the corresponding Gregorian year).
     */
    private static function isIndianLeapYear(int $sakaYear): bool
    {
        $isoYear = $sakaYear + self::YEAR_OFFSET;
        return CalendarMath::isLeapYear($isoYear);
    }

    /**
     * Returns the ISO date of the first day of the given Saka year (1 Chaitra).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function sakaNewYearIso(int $sakaYear): array
    {
        $isoYear = $sakaYear + self::YEAR_OFFSET;
        $startDay = CalendarMath::isLeapYear($isoYear) ? 21 : 22;
        return [$isoYear, 3, $startDay]; // March 21 or 22
    }

    /**
     * Converts ISO date to Saka year, month, day.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function isoToSaka(int $isoYear, int $isoMonth, int $isoDay): array
    {
        $jdn = CalendarMath::toJulianDay($isoYear, $isoMonth, $isoDay);

        // Approximate the Saka year.
        $sakaYear = $isoYear - self::YEAR_OFFSET;

        // Check if the date is before 1 Chaitra of this year.
        [$nyIsoY, $nyIsoM, $nyIsoD] = self::sakaNewYearIso($sakaYear);
        $nyJdn = CalendarMath::toJulianDay($nyIsoY, $nyIsoM, $nyIsoD);

        if ($jdn < $nyJdn) {
            $sakaYear--;
            [$nyIsoY, $nyIsoM, $nyIsoD] = self::sakaNewYearIso($sakaYear);
            $nyJdn = CalendarMath::toJulianDay($nyIsoY, $nyIsoM, $nyIsoD);
        }

        $dayOfYear = $jdn - $nyJdn + 1;
        $isLeap = self::isIndianLeapYear($sakaYear);
        $lengths = self::monthLengths($isLeap);

        $month = 1;
        $remaining = $dayOfYear;
        for ($m = 1; $m <= 12; $m++) {
            if ($remaining <= $lengths[$m]) {
                $month = $m;
                break;
            }
            $remaining -= $lengths[$m];
        }

        return [$sakaYear, $month, $remaining];
    }

    /**
     * Converts Saka year, month, day to ISO date.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function sakaToIso(int $sakaYear, int $month, int $day): array
    {
        [$nyIsoY, $nyIsoM, $nyIsoD] = self::sakaNewYearIso($sakaYear);
        $jdn = CalendarMath::toJulianDay($nyIsoY, $nyIsoM, $nyIsoD);

        $isLeap = self::isIndianLeapYear($sakaYear);
        $lengths = self::monthLengths($isLeap);

        for ($m = 1; $m < $month; $m++) {
            $jdn += $lengths[$m];
        }
        $jdn += $day - 1;

        return CalendarMath::fromJulianDay($jdn);
    }

    // -------------------------------------------------------------------------
    // CalendarProtocol implementation
    // -------------------------------------------------------------------------

    #[\Override]
    public function year(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return self::isoToSaka($isoYear, $isoMonth, $isoDay)[0];
    }

    #[\Override]
    public function month(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return self::isoToSaka($isoYear, $isoMonth, $isoDay)[1];
    }

    #[\Override]
    public function day(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return self::isoToSaka($isoYear, $isoMonth, $isoDay)[2];
    }

    #[\Override]
    public function era(int $isoYear, int $isoMonth, int $isoDay): string
    {
        return 'shaka';
    }

    #[\Override]
    public function eraYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return self::isoToSaka($isoYear, $isoMonth, $isoDay)[0];
    }

    #[\Override]
    public function monthCode(int $isoYear, int $isoMonth, int $isoDay): string
    {
        return sprintf('M%02d', self::isoToSaka($isoYear, $isoMonth, $isoDay)[1]);
    }

    #[\Override]
    public function dayOfYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        $jdn = CalendarMath::toJulianDay($isoYear, $isoMonth, $isoDay);
        [$sakaYear] = self::isoToSaka($isoYear, $isoMonth, $isoDay);
        [$nyIsoY, $nyIsoM, $nyIsoD] = self::sakaNewYearIso($sakaYear);
        $nyJdn = CalendarMath::toJulianDay($nyIsoY, $nyIsoM, $nyIsoD);
        return $jdn - $nyJdn + 1;
    }

    #[\Override]
    public function daysInMonth(int $isoYear, int $isoMonth, int $isoDay): int
    {
        [$sakaYear, $month] = self::isoToSaka($isoYear, $isoMonth, $isoDay);
        $lengths = self::monthLengths(self::isIndianLeapYear($sakaYear));
        return $lengths[$month];
    }

    #[\Override]
    public function daysInYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        [$sakaYear] = self::isoToSaka($isoYear, $isoMonth, $isoDay);
        return self::isIndianLeapYear($sakaYear) ? 366 : 365;
    }

    #[\Override]
    public function monthsInYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return 12;
    }

    #[\Override]
    public function inLeapYear(int $isoYear, int $isoMonth, int $isoDay): bool
    {
        [$sakaYear] = self::isoToSaka($isoYear, $isoMonth, $isoDay);
        return self::isIndianLeapYear($sakaYear);
    }

    #[\Override]
    public function calendarToIso(int $calYear, int $calMonth, int $calDay, string $overflow): array
    {
        if ($calMonth > 12) {
            if ($overflow === 'reject') {
                throw new RangeError("Month {$calMonth} exceeds maximum 12 for this calendar year.");
            }
            $calMonth = 12;
        }

        $lengths = self::monthLengths(self::isIndianLeapYear($calYear));
        $maxDay = $lengths[$calMonth];

        if ($overflow === 'reject' && $calDay > $maxDay) {
            throw new RangeError("Day {$calDay} exceeds maximum {$maxDay} for this calendar month.");
        }
        $calDay = min($calDay, $maxDay);

        return self::sakaToIso($calYear, $calMonth, $calDay);
    }

    #[\Override]
    public function calendarToIsoFromMonthCode(int $calYear, string $monthCode, int $calDay, string $overflow): array
    {
        $month = CalendarMath::monthCodeToMonth($monthCode);

        $lengths = self::monthLengths(self::isIndianLeapYear($calYear));
        $maxDay = $lengths[$month];

        if ($overflow === 'reject' && $calDay > $maxDay) {
            throw new RangeError("Day {$calDay} exceeds maximum {$maxDay} for this calendar month.");
        }
        $calDay = min($calDay, $maxDay);

        return self::sakaToIso($calYear, $month, $calDay);
    }

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
        [$calYear, $calMonth, $calDay] = self::isoToSaka($isoYear, $isoMonth, $isoDay);
        $originalCalDay = $calDay;

        if ($years !== 0 || $months !== 0) {
            // Use (month - 1) in the 0-based form so normalization is a single
            // floor-division step. This avoids Psalm incorrectly narrowing the
            // bounds of the while-loop condition.
            $calYear += $years;
            $zeroBasedMonth = $calMonth - 1 + $months;
            $yearDelta = CalendarMath::floorDiv($zeroBasedMonth, 12);
            $calYear += $yearDelta;
            $calMonth = $zeroBasedMonth - ($yearDelta * 12) + 1;

            $lengths = self::monthLengths(self::isIndianLeapYear($calYear));
            $maxDay = $lengths[$calMonth];

            if ($overflow === 'reject' && $originalCalDay > $maxDay) {
                throw new RangeError(
                    "Day {$originalCalDay} exceeds maximum {$maxDay} for the resulting calendar month.",
                );
            }
            $calDay = min($originalCalDay, $maxDay);
        }

        [$isoY, $isoM, $isoD] = self::sakaToIso($calYear, $calMonth, $calDay);
        $jdn = CalendarMath::toJulianDay($isoY, $isoM, $isoD);
        $jdn += ($weeks * 7) + $days;

        return CalendarMath::fromJulianDay($jdn);
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
        $dayOrWeek = CalendarMath::dayOrWeekDateUntil($isoY1, $isoM1, $isoD1, $isoY2, $isoM2, $isoD2, $largestUnit);
        if ($dayOrWeek !== null) {
            return $dayOrWeek;
        }

        $jdn1 = CalendarMath::toJulianDay($isoY1, $isoM1, $isoD1);
        $jdn2 = CalendarMath::toJulianDay($isoY2, $isoM2, $isoD2);

        if ($jdn1 === $jdn2) {
            return [0, 0, 0, 0];
        }

        $sign = $jdn2 > $jdn1 ? 1 : -1;
        [$calY1] = self::isoToSaka($isoY1, $isoM1, $isoD1);
        [$calY2] = self::isoToSaka($isoY2, $isoM2, $isoD2);

        $years = 0;
        $months = 0;

        if ($largestUnit === 'year') {
            $yearDiff = abs($calY2 - $calY1);
            $years = max(0, $yearDiff - 1);
            while ($this->trialDoesNotSurpass($isoY1, $isoM1, $isoD1, $sign * ($years + 1), 0, $jdn2, $sign)) {
                $years++;
            }
            while ($this->trialDoesNotSurpass(
                $isoY1,
                $isoM1,
                $isoD1,
                $sign * $years,
                $sign * ($months + 1),
                $jdn2,
                $sign,
            )) {
                $months++;
            }
        }

        if ($largestUnit === 'month') {
            $yearDiff = abs($calY2 - $calY1);
            if ($yearDiff > 1) {
                $months = max(0, (($yearDiff - 1) * 12) - 2);
            }
            while ($this->trialDoesNotSurpass($isoY1, $isoM1, $isoD1, 0, $sign * ($months + 1), $jdn2, $sign)) {
                $months++;
            }
        }

        [$intIsoY, $intIsoM, $intIsoD] = $this->dateAdd(
            $isoY1,
            $isoM1,
            $isoD1,
            $sign * $years,
            $sign * $months,
            0,
            0,
            'constrain',
        );
        $days = $jdn2 - CalendarMath::toJulianDay($intIsoY, $intIsoM, $intIsoD);

        return [$sign * $years, $sign * $months, 0, $days];
    }

    private function trialDoesNotSurpass(
        int $isoY1,
        int $isoM1,
        int $isoD1,
        int $years,
        int $months,
        int $targetJdn,
        int $sign,
    ): bool {
        [$tY, $tM, $tD] = $this->dateAdd($isoY1, $isoM1, $isoD1, $years, $months, 0, 0, 'constrain');
        $trialJdn = CalendarMath::toJulianDay($tY, $tM, $tD);

        [, , $origCalDay] = self::isoToSaka($isoY1, $isoM1, $isoD1);
        [, , $trialCalDay] = self::isoToSaka($tY, $tM, $tD);

        if ($trialCalDay < $origCalDay) {
            return $sign > 0 ? $trialJdn < $targetJdn : $trialJdn > $targetJdn;
        }

        return $sign > 0 ? $trialJdn <= $targetJdn : $trialJdn >= $targetJdn;
    }

    #[\Override]
    public function monthCodeToMonth(string $monthCode, int $calYear, string $overflow = 'reject'): int
    {
        // Indian has no leap months, so every valid month code (M01–M12) exists
        // in every year; overflow is irrelevant here and the shared ISO parse
        // (which raises RangeError on anything else) is exactly correct.
        unset($overflow);

        return CalendarMath::monthCodeToMonth($monthCode);
    }

    #[\Override]
    public function resolveEra(string $era, int $eraYear): int
    {
        if ($era !== 'shaka') {
            throw new RangeError("Invalid era \"{$era}\" for calendar \"indian\".");
        }
        return $eraYear;
    }
}
