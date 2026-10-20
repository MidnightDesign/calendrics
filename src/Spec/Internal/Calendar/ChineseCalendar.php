<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal\Calendar;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Internal\CalendarMath;

/**
 * Chinese/Dangi calendar with corrections applied at the ISO conversion boundary.
 * All projections and arithmetic consume the same resolved date; ICU state never
 * doubles as a partially corrected date or a cache key.
 *
 * @internal
 */
final class ChineseCalendar implements CalendarProtocol
{
    use CalendarDateDifference;

    private const int MS_PER_DAY = 86_400_000;
    private const int FIELD_EXTENDED_YEAR = 19;
    private const int FIELD_IS_LEAP_MONTH = 22;
    private const int CACHE_CAP = 1024;

    /** ICU 76.1 discrepancies, indexed by related Gregorian year. */
    private const DAYS_IN_YEAR = [2026 => 354, 2027 => 354, 2029 => 355, 2030 => 354];
    private const LEAP_MONTH = [1987 => 5];

    private readonly \IntlCalendar $intlCal;
    private readonly int $yearOffset;

    /** @var array<int, ChineseCalendarFields> */
    private array $fieldsCache = [];
    /** @var array<int, int> */
    private array $leapMonthCache = [];

    public function __construct(
        private readonly string $calendarId,
    ) {
        $this->intlCal = IntlCalendarFactory::forCalendarId('UTC', $calendarId);
        $this->yearOffset = $calendarId === 'chinese' ? 2637 : 2333;
    }

    #[\Override]
    public function year(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return $this->fields($isoYear, $isoMonth, $isoDay)->year;
    }

    #[\Override]
    public function month(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return $this->fields($isoYear, $isoMonth, $isoDay)->month;
    }

    #[\Override]
    public function day(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return $this->fields($isoYear, $isoMonth, $isoDay)->day;
    }

    #[\Override]
    public function monthCode(int $isoYear, int $isoMonth, int $isoDay): string
    {
        return $this->fields($isoYear, $isoMonth, $isoDay)->monthCode;
    }

    #[\Override]
    public function dayOfYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return $this->fields($isoYear, $isoMonth, $isoDay)->dayOfYear;
    }

    #[\Override]
    public function daysInMonth(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return $this->fields($isoYear, $isoMonth, $isoDay)->daysInMonth;
    }

    #[\Override]
    public function daysInYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return $this->fields($isoYear, $isoMonth, $isoDay)->daysInYear;
    }

    #[\Override]
    public function monthsInYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        return $this->fields($isoYear, $isoMonth, $isoDay)->monthsInYear;
    }

    #[\Override]
    public function inLeapYear(int $isoYear, int $isoMonth, int $isoDay): bool
    {
        return $this->monthsInYear($isoYear, $isoMonth, $isoDay) === 13;
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
    public function resolveEra(string $era, int $eraYear): ?int
    {
        return null;
    }

    private function fields(int $isoYear, int $isoMonth, int $isoDay): ChineseCalendarFields
    {
        $jdn = CalendarMath::toJulianDay($isoYear, $isoMonth, $isoDay);
        if (array_key_exists($jdn, $this->fieldsCache)) {
            return $this->fieldsCache[$jdn];
        }
        $this->intlCal->setTime((float) (($jdn - 2_440_588) * self::MS_PER_DAY));
        // Resolve fields before getActualMaximum: ICU otherwise retains stale maxima.
        $day = $this->intlCal->get(\IntlCalendar::FIELD_DAY_OF_MONTH);
        $year = $this->intlCal->get(self::FIELD_EXTENDED_YEAR) - $this->yearOffset;
        $icuMonth = $this->intlCal->get(\IntlCalendar::FIELD_MONTH);
        $isLeap = $this->intlCal->get(self::FIELD_IS_LEAP_MONTH) !== 0;
        $daysInMonth = $this->intlCal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
        $daysInYear = $this->intlCal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_YEAR);
        $dayOfYear = $this->intlCal->get(\IntlCalendar::FIELD_DAY_OF_YEAR);

        if ($this->calendarId === 'chinese') {
            $corrected = ChineseCalendarCorrections::fromIso($isoYear, $isoMonth, $isoDay);
            if ($corrected !== null) {
                $year = $corrected['year'];
                $icuMonth = $corrected['month'] - 1;
                $isLeap = false;
                $day = $corrected['day'];
                $daysInMonth = $corrected['daysInMonth'];
                $dayOfYear = $corrected['month'] === 1 ? $day : self::DAYS_IN_YEAR[$year] - $daysInMonth + $day;
            }
            $daysInYear = self::DAYS_IN_YEAR[$year] ?? $daysInYear;
            $yearStart = ChineseCalendarCorrections::yearStart($year);
            if ($yearStart !== null) {
                $dayOfYear = $jdn - $yearStart + 1;
            }
            $correctLeap = self::LEAP_MONTH[$year] ?? null;
            if ($correctLeap !== null && $icuMonth === ($correctLeap + 1)) {
                if (!$isLeap) {
                    $icuMonth = $correctLeap;
                }
                $isLeap = !$isLeap;
            }
        }

        $leapMonth = $this->leapMonth($year);
        $month = $icuMonth + 1 + (int) ($isLeap || $leapMonth >= 0 && $icuMonth > $leapMonth);
        $fields = new ChineseCalendarFields(
            $year,
            $month,
            $day,
            sprintf('M%02d%s', $icuMonth + 1, $isLeap ? 'L' : ''),
            $dayOfYear,
            $daysInMonth,
            $daysInYear,
            $leapMonth >= 0 ? 13 : 12,
        );
        if (count($this->fieldsCache) >= self::CACHE_CAP) {
            $this->fieldsCache = [];
        }
        return $this->fieldsCache[$jdn] = $fields;
    }

    #[\Override]
    public function calendarToIso(int $calYear, int $calMonth, int $calDay, string $overflow): array
    {
        $leapMonth = $this->leapMonth($calYear);
        $maxMonths = $leapMonth >= 0 ? 13 : 12;
        if ($calMonth > $maxMonths) {
            if ($overflow === 'reject') {
                throw new RangeError("Month {$calMonth} exceeds maximum {$maxMonths} for this calendar year.");
            }
            $calMonth = $maxMonths;
        }
        if ($this->calendarId === 'chinese') {
            $corrected = ChineseCalendarCorrections::toIso($calYear, $calMonth, $calDay, $overflow);
            if ($corrected !== null) {
                return $corrected;
            }
        }

        $icuMonth = $calMonth - 1;
        $isLeap = false;
        if ($leapMonth >= 0 && $calMonth > ($leapMonth + 1)) {
            $icuMonth--;
            $isLeap = $calMonth === ($leapMonth + 2);
        }
        // Invert the same month identity correction used by fields().
        $correctLeap = $this->calendarId === 'chinese' ? self::LEAP_MONTH[$calYear] ?? null : null;
        if ($correctLeap !== null) {
            [$icuMonth, $isLeap] = match (true) {
                $icuMonth === $correctLeap && $isLeap => [$correctLeap + 1, false],
                $icuMonth === ($correctLeap + 1) && !$isLeap => [$icuMonth, true],
                default => [$icuMonth, $isLeap],
            };
        }

        $this->intlCal->clear();
        $this->intlCal->set(self::FIELD_EXTENDED_YEAR, $calYear + $this->yearOffset);
        $this->intlCal->set(\IntlCalendar::FIELD_MONTH, $icuMonth);
        $this->intlCal->set(self::FIELD_IS_LEAP_MONTH, $isLeap ? 1 : 0);
        $this->intlCal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $_ = $this->intlCal->get(\IntlCalendar::FIELD_DAY_OF_MONTH);
        $maxDay = $this->intlCal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
        if ($overflow === 'reject' && $calDay > $maxDay) {
            throw new RangeError("Day {$calDay} exceeds maximum {$maxDay} for this calendar month.");
        }
        $this->intlCal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, min($calDay, $maxDay));
        $jdn = (int) floor($this->intlCal->getTime() / (float) self::MS_PER_DAY) + 2_440_588;
        return CalendarMath::fromJulianDay($jdn);
    }

    #[\Override]
    public function calendarToIsoFromMonthCode(int $calYear, string $monthCode, int $calDay, string $overflow): array
    {
        $month = $this->resolveMonthCode($monthCode, $calYear, $overflow);
        return $this->calendarToIso($calYear, $month, $calDay, $overflow);
    }

    #[\Override]
    public function monthCodeToMonth(string $monthCode, int $calYear, string $overflow = 'reject'): int
    {
        // This projection is also used before the caller resolves leap-code overflow.
        $base = $this->monthCodeNumber($monthCode);
        if (str_ends_with($monthCode, 'L')) {
            return $base + 1;
        }
        $leap = $this->leapMonth($calYear);
        return $base + (int) ($leap >= 0 && ($base - 1) > $leap);
    }

    private function resolveMonthCode(string $monthCode, int $calYear, string $overflow): int
    {
        $base = $this->monthCodeNumber($monthCode);
        if (str_ends_with($monthCode, 'L') && $this->leapMonth($calYear) !== ($base - 1)) {
            if ($overflow === 'reject') {
                throw new RangeError("monthCode \"{$monthCode}\" does not exist in this calendar year.");
            }
            $monthCode = substr($monthCode, offset: 0, length: -1);
        }
        return $this->monthCodeToMonth($monthCode, $calYear, $overflow);
    }

    private function monthCodeNumber(string $monthCode): int
    {
        $baseCode = str_ends_with($monthCode, 'L') ? substr($monthCode, offset: 0, length: -1) : $monthCode;
        $base = (int) substr($baseCode, offset: 1);
        if ($base < 1 || $base > 12) {
            throw new RangeError("monthCode \"{$monthCode}\" is out of range for calendar \"{$this->calendarId}\".");
        }
        return $base;
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
        $jdn = CalendarMath::toJulianDay($isoYear, $isoMonth, $isoDay);
        if ($years !== 0 || $months !== 0) {
            $fields = $this->fields($isoYear, $isoMonth, $isoDay);
            $year = $fields->year + $years;
            $month = $years === 0 ? $fields->month : $this->resolveMonthCode($fields->monthCode, $year, $overflow);
            $month += $months;
            while ($month < 1) {
                $year--;
                $month += $this->leapMonth($year) >= 0 ? 13 : 12;
            }
            while (true) {
                $monthsInYear = $this->leapMonth($year) >= 0 ? 13 : 12;
                if ($month <= $monthsInYear) {
                    break;
                }
                $month -= $monthsInYear;
                $year++;
            }
            $iso = $this->calendarToIso($year, $month, $fields->day, $overflow);
            $jdn = CalendarMath::toJulianDay(...$iso);
        }
        return CalendarMath::fromJulianDay($jdn + ($weeks * 7) + $days);
    }

    #[\Override]
    private function hasLeapMonths(): bool
    {
        return true;
    }

    #[\Override]
    private function totalMonthsInYearsDirectional(int $isoY, int $isoM, int $isoD, int $yearCount, int $sign): int
    {
        $total = 0;
        $year = $this->year($isoY, $isoM, $isoD);
        for ($i = 0; $i < $yearCount; $i++) {
            $total += $this->leapMonth($year) >= 0 ? 13 : 12;
            $year += $sign;
        }
        return $total;
    }

    /** Zero-based regular month followed by a leap copy, or -1 for a common year. */
    private function leapMonth(int $calYear): int
    {
        if (array_key_exists($calYear, $this->leapMonthCache)) {
            return $this->leapMonthCache[$calYear];
        }
        if ($this->calendarId === 'chinese' && array_key_exists($calYear, self::LEAP_MONTH)) {
            return self::LEAP_MONTH[$calYear];
        }
        // Scan an independent calendar so a year lookup cannot invalidate a date.
        $calendar = clone $this->intlCal;
        $result = -1;
        for ($month = 0; $month < 12; $month++) {
            $calendar->clear();
            $calendar->set(self::FIELD_EXTENDED_YEAR, $calYear + $this->yearOffset);
            $calendar->set(\IntlCalendar::FIELD_MONTH, $month);
            $calendar->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 15);
            $_ = $calendar->get(\IntlCalendar::FIELD_MONTH);
            $calendar->set(
                \IntlCalendar::FIELD_DAY_OF_MONTH,
                $calendar->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH),
            );
            $calendar->add(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
            if ($calendar->get(self::FIELD_IS_LEAP_MONTH) === 1) {
                $result = $month;
                break;
            }
        }
        if (count($this->leapMonthCache) >= self::CACHE_CAP) {
            $this->leapMonthCache = [];
        }
        return $this->leapMonthCache[$calYear] = $result;
    }
}
