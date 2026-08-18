<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal\Calendar;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Internal\CalendarMath;

/**
 * Pure PHP implementation of the Hebrew calendar.
 *
 * The Hebrew calendar is fully algorithmic (no astronomical observation required),
 * making a pure implementation both possible and preferable to ICU, which has
 * known discrepancies in proleptic years and far-future dates.
 *
 * Algorithm based on Reingold & Dershowitz "Calendrical Calculations" and
 * the Rambam's fixed arithmetic calendar rules.
 *
 * @internal
 */
final class PureHebrewCalendar implements CalendarProtocol
{
    /**
     * JDN epoch: the JDN of 1 Tishrei year 1 is computed as EPOCH + hebrewNewYearDay(1).
     * hebrewNewYearDay(1) = 0, so the epoch is the JDN of 1 Tishrei AM 1.
     */
    private const int EPOCH = 347_998;

    /** @var array<int, int> Memoized hebrewDelay1 results, keyed by year. */
    private static array $delay1Cache = [];

    /** @var array<int, int> Memoized hebrewNewYearDay results, keyed by year. */
    private static array $newYearDayCache = [];

    /** @var array<int, int> Memoized daysInHebrewYear results, keyed by year. */
    private static array $daysInYearCache = [];

    /** @var array<int, string> Memoized yearType results, keyed by year. */
    private static array $yearTypeCache = [];

    /** @var array<int, array{0: int, 1: int, 2: int}> Memoized isoToHebrew results, keyed by JDN. */
    private static array $isoToHebrewCache = [];

    /** @var array<int, int> Memoized monthLength results, keyed by (year * 16) + ordinalMonth. */
    private static array $monthLengthCache = [];

    // -------------------------------------------------------------------------
    // Core algorithmic functions
    // -------------------------------------------------------------------------

    /**
     * Computes the tentative day number of 1 Tishrei for the given Hebrew year
     * (before postponement rules).
     */
    private static function hebrewDelay1(int $year): int
    {
        if (array_key_exists($year, self::$delay1Cache)) {
            return self::$delay1Cache[$year];
        }
        $months = (int) floor(((235 * $year) - 234) / 19);
        $parts = 12_084 + (13_753 * $months);
        $day = ($months * 29) + (int) floor($parts / 25_920);
        if (((((3 * ($day + 1)) % 7) + 7) % 7) < 3) {
            $day++;
        }
        return self::$delay1Cache[$year] = $day;
    }

    /**
     * Computes the additional postponement for 1 Tishrei.
     */
    private static function hebrewDelay2(int $year): int
    {
        $last = self::hebrewDelay1($year - 1);
        $present = self::hebrewDelay1($year);
        $next = self::hebrewDelay1($year + 1);
        if (($next - $present) === 356) {
            return 2;
        }
        if (($present - $last) === 382) {
            return 1;
        }
        return 0;
    }

    /**
     * Returns the day number of 1 Tishrei relative to the epoch.
     */
    private static function hebrewNewYearDay(int $year): int
    {
        return self::$newYearDayCache[$year] ??= self::hebrewDelay1($year) + self::hebrewDelay2($year);
    }

    /**
     * Returns the JDN of 1 Tishrei of the given Hebrew year.
     */
    private static function newYearJdn(int $year): int
    {
        return self::EPOCH + self::hebrewNewYearDay($year);
    }

    /**
     * Returns the total number of days in the Hebrew year.
     */
    private static function daysInHebrewYear(int $year): int
    {
        return self::$daysInYearCache[$year] ??= self::hebrewNewYearDay($year + 1) - self::hebrewNewYearDay($year);
    }

    /**
     * Whether the Hebrew year is a leap year (13 months).
     */
    private static function isLeapYear(int $year): bool
    {
        return (((((7 * $year) + 1) % 19) + 19) % 19) < 7;
    }

    /**
     * Year type: 'deficient', 'regular', or 'complete'.
     */
    private static function yearType(int $year): string
    {
        if (array_key_exists($year, self::$yearTypeCache)) {
            return self::$yearTypeCache[$year];
        }
        $d = self::daysInHebrewYear($year);
        return self::$yearTypeCache[$year] = match ($d % 10) {
            3 => 'deficient', // 353 or 383
            4 => 'regular', // 354 or 384
            5 => 'complete', // 355 or 385
            default => throw new \RuntimeException("Unexpected Hebrew year length: {$d}"),
        };
    }

    /**
     * Returns the number of days in the given Hebrew month (1-based ordinal).
     *
     * Month ordinals:
     *   Non-leap: 1=Tishrei, 2=Cheshvan, 3=Kislev, 4=Tevet, 5=Shevat,
     *             6=Adar, 7=Nisan, 8=Iyar, 9=Sivan, 10=Tammuz, 11=Av, 12=Elul
     *   Leap:     1=Tishrei, 2=Cheshvan, 3=Kislev, 4=Tevet, 5=Shevat,
     *             6=Adar I, 7=Adar II, 8=Nisan, 9=Iyar, 10=Sivan,
     *             11=Tammuz, 12=Av, 13=Elul
     */
    private static function monthLength(int $year, int $ordinalMonth): int
    {
        $key = ($year * 16) + $ordinalMonth;
        if (array_key_exists($key, self::$monthLengthCache)) {
            return self::$monthLengthCache[$key];
        }
        $type = self::yearType($year);
        $isLeap = self::isLeapYear($year);
        $totalMonths = $isLeap ? 13 : 12;

        if ($ordinalMonth < 1 || $ordinalMonth > $totalMonths) {
            throw new RangeError("Month ordinal {$ordinalMonth} out of range for Hebrew year {$year}.");
        }

        // Map ordinal to logical month identity
        if ($isLeap) {
            $v = match ($ordinalMonth) {
                1 => 30, // Tishrei
                2 => $type === 'complete' ? 30 : 29, // Cheshvan
                3 => $type === 'deficient' ? 29 : 30, // Kislev
                4 => 29, // Tevet
                5 => 30, // Shevat
                6 => 30, // Adar I
                7 => 29, // Adar II
                8 => 30, // Nisan
                9 => 29, // Iyar
                10 => 30, // Sivan
                11 => 29, // Tammuz
                12 => 30, // Av
                // Elul (ordinal 13) and any value beyond the validated range.
                default => 29,
            };
            return self::$monthLengthCache[$key] = $v;
        }

        $v = match ($ordinalMonth) {
            1 => 30, // Tishrei
            2 => $type === 'complete' ? 30 : 29, // Cheshvan
            3 => $type === 'deficient' ? 29 : 30, // Kislev
            4 => 29, // Tevet
            5 => 30, // Shevat
            6 => 29, // Adar
            7 => 30, // Nisan
            8 => 29, // Iyar
            9 => 30, // Sivan
            10 => 29, // Tammuz
            11 => 30, // Av
            12 => 29, // Elul
            default => throw new RangeError("Invalid ordinal month {$ordinalMonth} for non-leap Hebrew year."),
        };
        return self::$monthLengthCache[$key] = $v;
    }

    /**
     * Converts an ordinal month to a TC39 monthCode.
     */
    private static function ordinalToMonthCode(int $ordinal, bool $isLeap): string
    {
        if (!$isLeap) {
            // Non-leap: ordinal 1-5 -> M01-M05, 6-12 -> M06-M12
            return sprintf('M%02d', $ordinal);
        }
        // Leap: ordinal 1-5 -> M01-M05, 6 -> M05L, 7-13 -> M06-M12
        if ($ordinal <= 5) {
            return sprintf('M%02d', $ordinal);
        }
        if ($ordinal === 6) {
            return 'M05L';
        }
        return sprintf('M%02d', $ordinal - 1);
    }

    /**
     * Converts a TC39 monthCode to an ordinal month for the given year.
     */
    private static function monthCodeToOrdinal(string $monthCode, int $calYear): int
    {
        $isLeap = self::isLeapYear($calYear);

        if ($monthCode === 'M05L') {
            if (!$isLeap) {
                throw new RangeError(
                    "monthCode \"M05L\" is only valid in Hebrew leap years; year {$calYear} is not a leap year.",
                );
            }
            return 6;
        }

        $m = null;
        if (preg_match('/^M(\d{2})$/', $monthCode, $m) !== 1) {
            throw new RangeError("Invalid monthCode \"{$monthCode}\" for hebrew calendar.");
        }
        $num = (int) $m[1];
        if ($num < 1 || $num > 12) {
            throw new RangeError("monthCode \"{$monthCode}\" is out of range for hebrew calendar.");
        }

        if ($num <= 5) {
            return $num;
        }
        // M06-M12: in non-leap -> ordinal 6-12; in leap -> ordinal 7-13
        return $isLeap ? $num + 1 : $num;
    }

    /**
     * Converts ISO date to Hebrew year, ordinal month, day.
     *
     * @return array{0: int, 1: int, 2: int} [year, ordinalMonth, day]
     */
    private static function isoToHebrew(int $isoYear, int $isoMonth, int $isoDay): array
    {
        $jdn = CalendarMath::toJulianDay($isoYear, $isoMonth, $isoDay);
        if (array_key_exists($jdn, self::$isoToHebrewCache)) {
            return self::$isoToHebrewCache[$jdn];
        }

        // Estimate the Hebrew year (could be off by 1).
        $approxYear = (int) floor((float) ($jdn - self::EPOCH) / 365.25) + 1;

        // Adjust to find the correct year.
        while (self::newYearJdn($approxYear + 1) <= $jdn) {
            $approxYear++;
        }
        while (self::newYearJdn($approxYear) > $jdn) {
            $approxYear--;
        }

        $year = $approxYear;
        $dayOfYear = $jdn - self::newYearJdn($year) + 1;

        // Find the month and day from dayOfYear.
        $totalMonths = self::isLeapYear($year) ? 13 : 12;
        $remaining = $dayOfYear;
        $month = 1;
        for ($m = 1; $m <= $totalMonths; $m++) {
            $dim = self::monthLength($year, $m);
            if ($remaining <= $dim) {
                $month = $m;
                break;
            }
            $remaining -= $dim;
        }

        return self::$isoToHebrewCache[$jdn] = [$year, $month, $remaining];
    }

    /**
     * Converts Hebrew year, ordinal month, day to ISO date.
     *
     * @return array{0: int, 1: int, 2: int} [isoYear, isoMonth, isoDay]
     */
    private static function hebrewToIso(int $year, int $ordinalMonth, int $day): array
    {
        $jdn = self::newYearJdn($year);

        // Add days for complete months before ordinalMonth.
        for ($m = 1; $m < $ordinalMonth; $m++) {
            $jdn += self::monthLength($year, $m);
        }

        $jdn += $day - 1; // day 1 = the first day

        return CalendarMath::fromJulianDay($jdn);
    }

    // -------------------------------------------------------------------------
    // CalendarProtocol implementation
    // -------------------------------------------------------------------------

    #[\Override]
    public function year(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return self::isoToHebrew($isoYear, $isoMonth, $isoDay)[0];
    }

    #[\Override]
    public function month(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return self::isoToHebrew($isoYear, $isoMonth, $isoDay)[1];
    }

    #[\Override]
    public function day(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return self::isoToHebrew($isoYear, $isoMonth, $isoDay)[2];
    }

    #[\Override]
    public function era(int $isoYear, int $isoMonth, int $isoDay): string
    {
        return 'am';
    }

    #[\Override]
    public function eraYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return self::isoToHebrew($isoYear, $isoMonth, $isoDay)[0];
    }

    #[\Override]
    public function monthCode(int $isoYear, int $isoMonth, int $isoDay): string
    {
        [$year, $month] = self::isoToHebrew($isoYear, $isoMonth, $isoDay);
        return self::ordinalToMonthCode($month, self::isLeapYear($year));
    }

    #[\Override]
    public function dayOfYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        $jdn = CalendarMath::toJulianDay($isoYear, $isoMonth, $isoDay);
        [$year] = self::isoToHebrew($isoYear, $isoMonth, $isoDay);
        return $jdn - self::newYearJdn($year) + 1;
    }

    #[\Override]
    public function daysInMonth(int $isoYear, int $isoMonth, int $isoDay): int
    {
        [$year, $month] = self::isoToHebrew($isoYear, $isoMonth, $isoDay);
        return self::monthLength($year, $month);
    }

    #[\Override]
    public function daysInYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        [$year] = self::isoToHebrew($isoYear, $isoMonth, $isoDay);
        return self::daysInHebrewYear($year);
    }

    #[\Override]
    public function monthsInYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        [$year] = self::isoToHebrew($isoYear, $isoMonth, $isoDay);
        return self::isLeapYear($year) ? 13 : 12;
    }

    #[\Override]
    public function inLeapYear(int $isoYear, int $isoMonth, int $isoDay): bool
    {
        [$year] = self::isoToHebrew($isoYear, $isoMonth, $isoDay);
        return self::isLeapYear($year);
    }

    #[\Override]
    public function calendarToIso(int $calYear, int $calMonth, int $calDay, string $overflow): array
    {
        $totalMonths = self::isLeapYear($calYear) ? 13 : 12;

        if ($overflow === 'reject') {
            if ($calMonth > $totalMonths) {
                throw new RangeError("Month {$calMonth} exceeds maximum {$totalMonths} for this calendar year.");
            }
        } elseif ($calMonth > $totalMonths) {
            $calMonth = $totalMonths;
        }

        $maxDay = self::monthLength($calYear, $calMonth);
        if ($overflow === 'reject' && $calDay > $maxDay) {
            throw new RangeError("Day {$calDay} exceeds maximum {$maxDay} for this calendar month.");
        }
        $calDay = min($calDay, $maxDay);

        return self::hebrewToIso($calYear, $calMonth, $calDay);
    }

    #[\Override]
    public function calendarToIsoFromMonthCode(int $calYear, string $monthCode, int $calDay, string $overflow): array
    {
        $isLeapCode = str_ends_with($monthCode, 'L');

        if ($isLeapCode && $monthCode !== 'M05L') {
            throw new RangeError("monthCode \"{$monthCode}\" is not valid for the hebrew calendar.");
        }

        try {
            $ordinal = self::monthCodeToOrdinal($monthCode, $calYear);
        } catch (RangeError $e) {
            if ($overflow === 'constrain' && $monthCode === 'M05L') {
                // M05L (Adar I) in non-leap year constrains to M06 (Adar).
                $ordinal = self::monthCodeToOrdinal('M06', $calYear);
            } else {
                throw $e;
            }
        }

        $maxDay = self::monthLength($calYear, $ordinal);
        if ($overflow === 'reject' && $calDay > $maxDay) {
            throw new RangeError("Day {$calDay} exceeds maximum {$maxDay} for this calendar month.");
        }
        $calDay = min($calDay, $maxDay);

        return self::hebrewToIso($calYear, $ordinal, $calDay);
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
        [$calYear, $calMonth, $calDay] = self::isoToHebrew($isoYear, $isoMonth, $isoDay);
        $originalCalDay = $calDay;

        if ($years !== 0 || $months !== 0) {
            if ($years !== 0) {
                // Preserve monthCode across year addition.
                $mc = self::ordinalToMonthCode($calMonth, self::isLeapYear($calYear));
                $calYear += $years;
                $isNewLeap = self::isLeapYear($calYear);
                if ($mc === 'M05L' && !$isNewLeap) {
                    if ($overflow === 'reject') {
                        throw new RangeError("monthCode \"M05L\" does not exist in Hebrew year {$calYear}.");
                    }
                    // Constrain M05L -> M06 (Adar)
                    $calMonth = self::monthCodeToOrdinal('M06', $calYear);
                } else {
                    $calMonth = self::monthCodeToOrdinal($mc, $calYear);
                }
            }

            $calMonth += $months;

            // Handle month overflow/underflow.
            while ($calMonth < 1) {
                $calYear--;
                $calMonth += self::isLeapYear($calYear) ? 13 : 12;
            }
            while (true) {
                $monthsInYear = self::isLeapYear($calYear) ? 13 : 12;
                if ($calMonth <= $monthsInYear) {
                    break;
                }
                $calMonth -= $monthsInYear;
                $calYear++;
            }

            // Constrain day.
            $newMaxDay = self::monthLength($calYear, $calMonth);
            if ($overflow === 'reject' && $originalCalDay > $newMaxDay) {
                throw new RangeError(
                    "Day {$originalCalDay} exceeds maximum {$newMaxDay} for the resulting calendar month.",
                );
            }
            $calDay = min($originalCalDay, $newMaxDay);
        }

        // Convert to JDN for day/week arithmetic.
        [$isoY, $isoM, $isoD] = self::hebrewToIso($calYear, $calMonth, $calDay);
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

        [$calY1] = self::isoToHebrew($isoY1, $isoM1, $isoD1);
        [$calY2] = self::isoToHebrew($isoY2, $isoM2, $isoD2);

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
                $months = max(0, (($yearDiff - 1) * 12) - 14);
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

        if ($months === 0) {
            // Year-only trial: check constraining.
            [, , $origCalDay] = self::isoToHebrew($isoY1, $isoM1, $isoD1);
            [, , $trialCalDay] = self::isoToHebrew($tY, $tM, $tD);
            $dayConstrained = $trialCalDay < $origCalDay;

            // Check monthCode constraining.
            $origMonthCode = $this->monthCode($isoY1, $isoM1, $isoD1);
            $trialMonthCode = $this->monthCode($tY, $tM, $tD);
            $monthConstrained = $origMonthCode !== $trialMonthCode;

            if ($monthConstrained) {
                $origOrd = self::isoToHebrew($isoY1, $isoM1, $isoD1)[1];
                $trialOrd = self::isoToHebrew($tY, $tM, $tD)[1];
                $constrainedOrdEarlier = $trialOrd < $origOrd;

                if ($dayConstrained) {
                    return $sign > 0 ? $trialJdn < $targetJdn : $trialJdn > $targetJdn;
                }
                if ($sign > 0) {
                    return $constrainedOrdEarlier ? $trialJdn < $targetJdn : $trialJdn <= $targetJdn;
                }
                return $constrainedOrdEarlier ? $trialJdn >= $targetJdn : $trialJdn > $targetJdn;
            }

            if ($dayConstrained) {
                return $sign > 0 ? $trialJdn < $targetJdn : $trialJdn > $targetJdn;
            }

            return $sign > 0 ? $trialJdn <= $targetJdn : $trialJdn >= $targetJdn;
        }

        // Month trials: check if day was constrained and adjust.
        $origHebrew = self::isoToHebrew($isoY1, $isoM1, $isoD1);
        $trialHebrew = self::isoToHebrew($tY, $tM, $tD);
        $origCalDay = $origHebrew[2];
        $trialCalDay = $trialHebrew[2];

        if ($trialCalDay < $origCalDay) {
            $trialJdn += $origCalDay - $trialCalDay;
        }

        return $sign > 0 ? $trialJdn <= $targetJdn : $trialJdn >= $targetJdn;
    }

    #[\Override]
    public function monthCodeToMonth(string $monthCode, int $calYear, string $overflow = 'reject'): int
    {
        // ConstrainMonthCode: M05L (Adar I) is the only Hebrew leap month code and
        // exists only in leap years. In a non-leap year it constrains to M06 (Adar)
        // under 'constrain', or throws under 'reject'.
        if ($monthCode === 'M05L' && $overflow === 'constrain' && !self::isLeapYear($calYear)) {
            return self::monthCodeToOrdinal('M06', $calYear);
        }

        return self::monthCodeToOrdinal($monthCode, $calYear);
    }

    #[\Override]
    public function resolveEra(string $era, int $eraYear): int
    {
        if ($era !== 'am') {
            throw new RangeError("Invalid era \"{$era}\" for calendar \"hebrew\".");
        }
        return $eraYear;
    }
}
