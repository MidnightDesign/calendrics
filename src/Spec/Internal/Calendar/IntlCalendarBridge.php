<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal\Calendar;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Internal\CalendarMath;

/**
 * Non-ISO calendar implementation backed by PHP's IntlCalendar (ICU).
 *
 * Converts between ISO 8601 fields and calendar-specific fields using ICU's
 * calendar support. The conversion path is:
 *   ISO fields -> JDN -> epoch ms -> IntlCalendar -> calendar fields
 *
 * @internal
 */
final class IntlCalendarBridge implements CalendarProtocol
{
    use CalendarDateDifference;

    /** Milliseconds per day. */
    private const int MS_PER_DAY = 86_400_000;

    /** ICU field ID for EXTENDED_YEAR (not defined as a PHP constant). */
    private const int FIELD_EXTENDED_YEAR = 19;

    private readonly \IntlCalendar $intlCal;

    /** Calendars whose year/month/day share the ISO 8601 proleptic Gregorian structure. */
    private readonly bool $isGregorianBased;
    /** Calendars with 13 months (coptic/ethiopic family). */
    private readonly bool $isCopticLike;
    /**
     * Per-iso-date caches for pure-function calendar projections. Keyed by
     * packed int (isoYear * 512) + (isoMonth * 32) + isoDay — same layout as
     * {@see CalendarMath::toJulianDay()}, avoids string formatting per lookup.
     * Capped to bound memory in long-running processes; capped arrays are
     * cleared when they exceed the threshold.
     *
     * @var array<int, int>
     */
    private array $yearCache = [];
    /** @var array<int, int> */
    private array $monthCache = [];
    /** @var array<int, int> */
    private array $dayCache = [];
    /** @var array<int, string> */
    private array $monthCodeCache = [];
    /** @var array<int, int> */
    private array $dayOfYearCache = [];
    /** @var array<int, int> */
    private array $daysInMonthCache = [];
    /** @var array<int, int> */
    private array $daysInYearCache = [];
    /** @var array<int, int> */
    private array $monthsInYearCache = [];
    /** @var array<int, bool> */
    private array $inLeapYearCache = [];
    /**
     * Max day of the calendar month, keyed by packed int (calYear * 32) +
     * calMonth. Populated opportunistically from ICU after setCalendarFields;
     * depends only on the calendar year/month (calendar day doesn't shift the
     * maximum).
     *
     * @var array<int, int>
     */
    private array $maxCalDayCache = [];
    /**
     * Memoized calendarToIsoFromMonthCode successes, keyed by
     * "calYear:monthCode:calDay:overflow". Only successful returns are cached;
     * exception paths re-run the computation.
     *
     * @var array<string, array{int, int<1, 12>, int<1, 31>}>
     */
    private array $calendarToIsoFromMonthCodeCache = [];

    private const FIELD_CACHE_CAP = 1024;

    /** JDN that $intlCal was last set to via setIsoDate, or null if set via another path. */
    private ?int $lastSetJdn = null;

    /** ISO year last passed to setIsoDate — valid iff $lastSetJdn !== null. */
    private int $lastSetIsoYear = 0;
    private int $lastSetIsoMonth = 0;
    private int $lastSetIsoDay = 0;

    public function __construct(
        private readonly string $calendarId,
    ) {
        $cal = IntlCalendarFactory::forCalendarId('UTC', $calendarId);
        $this->intlCal = $cal;
        $this->isGregorianBased = match ($calendarId) {
            'gregory', 'japanese', 'buddhist', 'roc' => true,
            default => false,
        };
        $this->isCopticLike = match ($calendarId) {
            'coptic', 'ethiopic', 'ethioaa' => true,
            default => false,
        };
    }

    // -------------------------------------------------------------------------
    // ISO -> Calendar field projection
    // -------------------------------------------------------------------------

    /** Offset from ICU EXTENDED_YEAR to TC39 signed year for ROC. */
    private const ROC_YEAR_OFFSET = 1911;

    #[\Override]
    public function year(int $isoYear, int $isoMonth, int $isoDay): int
    {
        // Gregorian-based calendars: compute directly from ISO year (proleptic, no ICU roundtrip).
        return match ($this->calendarId) {
            'gregory', 'japanese' => $isoYear,
            'buddhist' => $isoYear + 543,
            'roc' => $isoYear - self::ROC_YEAR_OFFSET,
            default => $this->yearFromIcu($isoYear, $isoMonth, $isoDay),
        };
    }

    private function yearFromIcu(int $isoYear, int $isoMonth, int $isoDay): int
    {
        $key = ($isoYear * 512) + ($isoMonth * 32) + $isoDay;
        if (array_key_exists($key, $this->yearCache)) {
            return $this->yearCache[$key];
        }
        $this->setIsoDate($isoYear, $isoMonth, $isoDay);

        $v = match (true) {
            $this->calendarId === 'coptic',
            $this->calendarId === 'ethiopic',
                => $this->intlCal->get(self::FIELD_EXTENDED_YEAR),
            default => $this->intlCal->get(\IntlCalendar::FIELD_YEAR),
        };
        if (count($this->yearCache) >= self::FIELD_CACHE_CAP) {
            $this->yearCache = [];
        }
        return $this->yearCache[$key] = $v;
    }

    #[\Override]
    public function month(int $isoYear, int $isoMonth, int $isoDay): int
    {
        // Gregorian-based calendars share ISO month structure.
        if ($this->isGregorianBased) {
            return $isoMonth;
        }
        $key = ($isoYear * 512) + ($isoMonth * 32) + $isoDay;
        if (array_key_exists($key, $this->monthCache)) {
            return $this->monthCache[$key];
        }
        $this->setIsoDate($isoYear, $isoMonth, $isoDay);

        $v = $this->intlCal->get(\IntlCalendar::FIELD_MONTH) + 1;
        if (count($this->monthCache) >= self::FIELD_CACHE_CAP) {
            $this->monthCache = [];
        }
        return $this->monthCache[$key] = $v;
    }

    #[\Override]
    public function day(int $isoYear, int $isoMonth, int $isoDay): int
    {
        // Gregorian-based calendars share ISO day structure.
        if ($this->isGregorianBased) {
            return $isoDay;
        }
        $key = ($isoYear * 512) + ($isoMonth * 32) + $isoDay;
        if (array_key_exists($key, $this->dayCache)) {
            return $this->dayCache[$key];
        }
        $this->setIsoDate($isoYear, $isoMonth, $isoDay);

        $v = $this->intlCal->get(\IntlCalendar::FIELD_DAY_OF_MONTH);
        if (count($this->dayCache) >= self::FIELD_CACHE_CAP) {
            $this->dayCache = [];
        }
        return $this->dayCache[$key] = $v;
    }

    #[\Override]
    public function era(int $isoYear, int $isoMonth, int $isoDay): ?string
    {
        // Constant-era calendars: no ICU state needed.
        $constant = match ($this->calendarId) {
            'gregory' => $isoYear >= 1 ? 'ce' : 'bce',
            'buddhist' => 'be',
            'roc' => $isoYear >= 1912 ? 'roc' : 'broc',
            'coptic' => 'am',
            'ethioaa' => 'aa',
            'persian' => 'ap',
            default => '__icu__',
        };
        if ($constant !== '__icu__') {
            return $constant;
        }
        if ($this->calendarId === 'japanese') {
            return $this->japaneseEraFromIso($isoYear, $isoMonth, $isoDay);
        }
        // Calendars whose era depends on the date: ensure ICU state is fresh.
        $this->setIsoDate($isoYear, $isoMonth, $isoDay);
        return match ($this->calendarId) {
            'ethiopic' => $this->intlCal->get(\IntlCalendar::FIELD_ERA) === 1 ? 'am' : 'aa',
            'islamic-civil', 'islamic-tbla', 'islamic-umalqura' => $this->intlCal->get(\IntlCalendar::FIELD_YEAR) >= 1
                ? 'ah'
                : 'bh',
            default => null,
        };
    }

    #[\Override]
    public function eraYear(int $isoYear, int $isoMonth, int $isoDay): ?int
    {
        // Gregorian-based calendars: compute directly from ISO year.
        if ($this->calendarId === 'gregory') {
            return $isoYear >= 1 ? $isoYear : 1 - $isoYear;
        }
        if ($this->calendarId === 'buddhist') {
            return $isoYear + 543;
        }
        if ($this->calendarId === 'roc') {
            return $isoYear >= 1912 ? $isoYear - self::ROC_YEAR_OFFSET : 1912 - $isoYear;
        }
        if ($this->calendarId === 'japanese') {
            return $this->japaneseEraYearFromIso($isoYear, $isoMonth, $isoDay);
        }

        $this->setIsoDate($isoYear, $isoMonth, $isoDay);

        return match ($this->calendarId) {
            'coptic' => $this->intlCal->get(self::FIELD_EXTENDED_YEAR),
            'ethiopic' => $this->intlCal->get(\IntlCalendar::FIELD_ERA) === 1
                ? $this->intlCal->get(self::FIELD_EXTENDED_YEAR)
                : $this->intlCal->get(\IntlCalendar::FIELD_YEAR),
            'ethioaa' => $this->intlCal->get(\IntlCalendar::FIELD_YEAR),
            'persian' => $this->intlCal->get(\IntlCalendar::FIELD_YEAR),
            'islamic-civil', 'islamic-tbla', 'islamic-umalqura' => (function () {
                $year = $this->intlCal->get(\IntlCalendar::FIELD_YEAR);
                return $year >= 1 ? $year : 1 - $year;
            })(),
            default => null,
        };
    }

    #[\Override]
    public function monthCode(int $isoYear, int $isoMonth, int $isoDay): string
    {
        // Gregorian-based calendars: month code matches ISO month.
        if ($this->isGregorianBased) {
            return sprintf('M%02d', $isoMonth);
        }
        $key = ($isoYear * 512) + ($isoMonth * 32) + $isoDay;
        if (array_key_exists($key, $this->monthCodeCache)) {
            return $this->monthCodeCache[$key];
        }
        $this->setIsoDate($isoYear, $isoMonth, $isoDay);

        $v = sprintf('M%02d', $this->intlCal->get(\IntlCalendar::FIELD_MONTH) + 1);
        if (count($this->monthCodeCache) >= self::FIELD_CACHE_CAP) {
            $this->monthCodeCache = [];
        }
        return $this->monthCodeCache[$key] = $v;
    }

    #[\Override]
    public function dayOfYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        // Gregorian-based calendars: compute directly.
        if ($this->isGregorianBased) {
            return CalendarMath::isoDayOfYear($isoYear, $isoMonth, $isoDay);
        }
        $key = ($isoYear * 512) + ($isoMonth * 32) + $isoDay;
        if (array_key_exists($key, $this->dayOfYearCache)) {
            return $this->dayOfYearCache[$key];
        }
        $this->setIsoDate($isoYear, $isoMonth, $isoDay);

        $v = $this->intlCal->get(\IntlCalendar::FIELD_DAY_OF_YEAR);
        if (count($this->dayOfYearCache) >= self::FIELD_CACHE_CAP) {
            $this->dayOfYearCache = [];
        }
        return $this->dayOfYearCache[$key] = $v;
    }

    #[\Override]
    public function daysInMonth(int $isoYear, int $isoMonth, int $isoDay): int
    {
        // Gregorian-based calendars: compute directly.
        if ($this->isGregorianBased) {
            return CalendarMath::calcDaysInMonth($isoYear, $isoMonth);
        }
        $key = ($isoYear * 512) + ($isoMonth * 32) + $isoDay;
        if (array_key_exists($key, $this->daysInMonthCache)) {
            return $this->daysInMonthCache[$key];
        }
        $this->setIsoDate($isoYear, $isoMonth, $isoDay);

        $v = $this->intlCal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
        if (count($this->daysInMonthCache) >= self::FIELD_CACHE_CAP) {
            $this->daysInMonthCache = [];
        }
        return $this->daysInMonthCache[$key] = $v;
    }

    #[\Override]
    public function daysInYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        // Gregorian-based calendars: compute directly.
        if ($this->isGregorianBased) {
            return CalendarMath::isLeapYear($isoYear) ? 366 : 365;
        }
        $key = ($isoYear * 512) + ($isoMonth * 32) + $isoDay;
        if (array_key_exists($key, $this->daysInYearCache)) {
            return $this->daysInYearCache[$key];
        }
        $this->setIsoDate($isoYear, $isoMonth, $isoDay);

        $v = $this->intlCal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_YEAR);
        if (count($this->daysInYearCache) >= self::FIELD_CACHE_CAP) {
            $this->daysInYearCache = [];
        }
        return $this->daysInYearCache[$key] = $v;
    }

    #[\Override]
    public function monthsInYear(int $isoYear, int $isoMonth, int $isoDay): int
    {
        // Gregorian-based calendars always have 12 months.
        if ($this->isGregorianBased) {
            return 12;
        }
        $key = ($isoYear * 512) + ($isoMonth * 32) + $isoDay;
        if (array_key_exists($key, $this->monthsInYearCache)) {
            return $this->monthsInYearCache[$key];
        }
        $this->setIsoDate($isoYear, $isoMonth, $isoDay);

        $v = $this->intlCal->getActualMaximum(\IntlCalendar::FIELD_MONTH) + 1;
        if (count($this->monthsInYearCache) >= self::FIELD_CACHE_CAP) {
            $this->monthsInYearCache = [];
        }
        return $this->monthsInYearCache[$key] = $v;
    }

    #[\Override]
    public function inLeapYear(int $isoYear, int $isoMonth, int $isoDay): bool
    {
        // 'indian' is handled by PureIndianCalendar; IntlCalendarBridge never sees it.
        if ($this->isGregorianBased) {
            return CalendarMath::isLeapYear($isoYear);
        }
        $key = ($isoYear * 512) + ($isoMonth * 32) + $isoDay;
        if (array_key_exists($key, $this->inLeapYearCache)) {
            return $this->inLeapYearCache[$key];
        }
        $this->setIsoDate($isoYear, $isoMonth, $isoDay);

        $v = match ($this->calendarId) {
            'coptic',
            'ethiopic',
            'ethioaa',
            'persian',
                => $this->intlCal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_YEAR) > 365,
            default => $this->intlCal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_YEAR) > 354, // Islamic variants: leap year has 355 days, non-leap 354
        };
        if (count($this->inLeapYearCache) >= self::FIELD_CACHE_CAP) {
            $this->inLeapYearCache = [];
        }
        return $this->inLeapYearCache[$key] = $v;
    }

    // -------------------------------------------------------------------------
    // Calendar -> ISO field resolution
    // -------------------------------------------------------------------------

    #[\Override]
    public function calendarToIso(int $calYear, int $calMonth, int $calDay, string $overflow): array
    {
        // For Gregorian-based calendars, pre-compute max day and handle overflow before
        // setting fields (JDN + set DAY_OF_MONTH would silently wrap to next month).
        $isoYear = match ($this->calendarId) {
            'gregory', 'japanese' => $calYear,
            'buddhist' => $calYear - 543,
            'roc' => $calYear + self::ROC_YEAR_OFFSET,
            default => null,
        };
        if ($isoYear !== null) {
            // Constrain month to 1-12 for Gregorian-based calendars.
            if ($calMonth > 12) {
                if ($overflow === 'reject') {
                    throw new RangeError("Month {$calMonth} exceeds maximum 12 for this calendar year.");
                }
                $calMonth = 12;
            }
            $this->gregorianMaxDay = CalendarMath::calcDaysInMonth($isoYear, $calMonth);
            $clampedDay = min($calDay, $this->gregorianMaxDay);
            $jdn = CalendarMath::toJulianDay($isoYear, $calMonth, $clampedDay);
            $epochMs = ($jdn - 2_440_588) * self::MS_PER_DAY;
            $this->intlCal->setTime((float) $epochMs);
            return $this->resolveAndConstrain($calDay, $overflow);
        }

        // Non-Gregorian calendars: validate/constrain month range.
        if ($overflow === 'reject') {
            $maxMonths = $this->calendarMonthsInYear();
            if ($calMonth > $maxMonths) {
                throw new RangeError("Month {$calMonth} exceeds maximum {$maxMonths} for this calendar year.");
            }
        } elseif ($calMonth > 12) {
            // For non-Gregorian constrain, check if month exceeds max.
            $maxMonths = $this->calendarMonthsInYear();
            if ($calMonth > $maxMonths) {
                $calMonth = $maxMonths;
            }
        }
        $this->setCalendarFields($calYear, $calMonth, $calDay);
        return $this->resolveAndConstrain($calDay, $overflow);
    }

    #[\Override]
    public function calendarToIsoFromMonthCode(int $calYear, string $monthCode, int $calDay, string $overflow): array
    {
        $cacheKey = "{$calYear}:{$monthCode}:{$calDay}:{$overflow}";
        if (array_key_exists($cacheKey, $this->calendarToIsoFromMonthCodeCache)) {
            return $this->calendarToIsoFromMonthCodeCache[$cacheKey];
        }
        $result = $this->calendarToIsoFromMonthCodeUncached($calYear, $monthCode, $calDay, $overflow);
        if (count($this->calendarToIsoFromMonthCodeCache) >= self::FIELD_CACHE_CAP) {
            $this->calendarToIsoFromMonthCodeCache = [];
        }
        return $this->calendarToIsoFromMonthCodeCache[$cacheKey] = $result;
    }

    /**
     * @return array{int, int<1, 12>, int<1, 31>}
     */
    private function calendarToIsoFromMonthCodeUncached(
        int $calYear,
        string $monthCode,
        int $calDay,
        string $overflow,
    ): array {
        $this->setCalendarFieldsFromMonthCode($calYear, $monthCode, $calDay);
        return $this->resolveAndConstrain($calDay, $overflow);
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
        // Gregorian-based calendars use the proleptic ISO month/day structure,
        // including dates before ICU's historical Julian/Gregorian cutover.
        if ($this->isGregorianBased) {
            $totalMonths = $isoMonth + $months - 1;
            $yearAdd = CalendarMath::floorDiv($totalMonths, 12);
            $calMonth = $totalMonths - ($yearAdd * 12) + 1;
            $finalIsoYear = $isoYear + $years + $yearAdd;
            $newMaxDay = CalendarMath::calcDaysInMonth($finalIsoYear, $calMonth);
            if ($overflow === 'reject' && $isoDay > $newMaxDay) {
                throw new RangeError("Day {$isoDay} exceeds maximum {$newMaxDay} for the resulting calendar month.");
            }
            $finalDay = $isoDay > $newMaxDay ? $newMaxDay : $isoDay;
            $jdn = CalendarMath::toJulianDay($finalIsoYear, $calMonth, $finalDay) + ($weeks * 7) + $days;
            return CalendarMath::fromJulianDay($jdn);
        }

        if ($years !== 0 || $months !== 0) {
            $originalCalDay = $this->day($isoYear, $isoMonth, $isoDay);
            $calYear = $this->year($isoYear, $isoMonth, $isoDay);
            $calMonth = $this->month($isoYear, $isoMonth, $isoDay);
            $calYear += $years;
            $calMonth += $months;

            $monthsInYear = $this->isCopticLike ? 13 : 12;
            $yearCarry = CalendarMath::floorDiv($calMonth - 1, $monthsInYear);
            $calYear += $yearCarry;
            $calMonth -= $yearCarry * $monthsInYear;
            // Resolve new date with day constraining. When maxCalDayCache
            // already knows the month's max, we can clamp in advance and avoid
            // the "set original day, discover overflow, reset to max" double
            // setCalendarFields dance.
            $maxKey = ($calYear * 32) + $calMonth;
            if (array_key_exists($maxKey, $this->maxCalDayCache)) {
                $newMaxDay = $this->maxCalDayCache[$maxKey];
                if ($overflow === 'reject' && $originalCalDay > $newMaxDay) {
                    throw new RangeError(
                        "Day {$originalCalDay} exceeds maximum {$newMaxDay} for the resulting calendar month.",
                    );
                }
                $finalCalDay = $originalCalDay > $newMaxDay ? $newMaxDay : $originalCalDay;
                $this->setCalendarFields($calYear, $calMonth, $finalCalDay);
            } else {
                $this->setCalendarFields($calYear, $calMonth, $originalCalDay);
                $newMaxDay = $this->intlCal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
                if (count($this->maxCalDayCache) >= self::FIELD_CACHE_CAP) {
                    $this->maxCalDayCache = [];
                }
                $this->maxCalDayCache[$maxKey] = $newMaxDay;
                if ($overflow === 'reject' && $originalCalDay > $newMaxDay) {
                    throw new RangeError(
                        "Day {$originalCalDay} exceeds maximum {$newMaxDay} for the resulting calendar month.",
                    );
                }
                if ($originalCalDay > $newMaxDay) {
                    $this->setCalendarFields($calYear, $calMonth, $newMaxDay);
                }
            }
            // State was set by setCalendarFields; trailing getTime reads that.
            $epochMs = $this->intlCal->getTime();
            $jdn = (int) floor($epochMs / (float) self::MS_PER_DAY) + 2_440_588;
        } else {
            // No year/month change: the starting ISO date is the current JDN —
            // compute it directly without touching ICU.
            $jdn = CalendarMath::toJulianDay($isoYear, $isoMonth, $isoDay);
        }

        return CalendarMath::fromJulianDay($jdn + ($weeks * 7) + $days);
    }

    /**
     * Estimates total months between two points by summing months-in-year
     * across intermediate years, walking in the given direction.
     */
    #[\Override]
    private function totalMonthsInYearsDirectional(int $isoY, int $isoM, int $isoD, int $yearCount, int $sign): int
    {
        unset($isoY, $isoM, $isoD, $sign);
        return $yearCount * ($this->isCopticLike ? 13 : 12);
    }

    #[\Override]
    private function hasLeapMonths(): bool
    {
        return false;
    }

    /** These calendars have the same month count in every year. */
    private function calendarMonthsInYear(): int
    {
        return match ($this->calendarId) {
            'coptic', 'ethiopic', 'ethioaa' => 13,
            default => 12,
        };
    }

    // -------------------------------------------------------------------------
    // Month code utilities
    // -------------------------------------------------------------------------

    #[\Override]
    public function monthCodeToMonth(string $monthCode, int $calYear, string $overflow = 'reject'): int
    {
        return $this->defaultMonthCodeToMonth($monthCode);
    }

    /**
     * monthCode → ordinal month for standard calendars.
     * Coptic/Ethiopic/Ethioaa allow M01-M13; others M01-M12.
     */
    private function defaultMonthCodeToMonth(string $monthCode): int
    {
        $maxMonth = $this->isCopticLike ? 13 : 12;
        $m = null;
        if (preg_match('/^M(\d{2})$/', $monthCode, $m) !== 1) {
            throw new RangeError("Invalid monthCode \"{$monthCode}\" for calendar \"{$this->calendarId}\".");
        }
        $month = (int) $m[1];
        if ($month < 1 || $month > $maxMonth) {
            throw new RangeError("monthCode \"{$monthCode}\" is out of range for calendar \"{$this->calendarId}\".");
        }
        return $month;
    }

    // -------------------------------------------------------------------------
    // Era resolution
    // -------------------------------------------------------------------------

    /** Japanese era TC39 names to ICU era indices and start years. */
    private const JAPANESE_ERA_TO_START = [
        'reiwa' => 2019,
        'heisei' => 1989,
        'showa' => 1926,
        'taisho' => 1912,
        'meiji' => 1868,
    ];

    /**
     * Valid TC39 era strings per calendar (canonical forms only).
     *
     * @var array<string, list<string>>
     */
    private const VALID_ERAS = [
        'gregory' => ['ce', 'bce'],
        'japanese' => ['reiwa', 'heisei', 'showa', 'taisho', 'meiji', 'ce', 'bce'],
        'buddhist' => ['be'],
        'roc' => ['minguo', 'roc', 'before-roc', 'broc'],
        'coptic' => ['era0', 'era1', 'am'],
        'ethiopic' => ['era0', 'era1', 'am', 'aa'],
        'ethioaa' => ['era0', 'aa'],
        'islamic-civil' => ['ah', 'bh'],
        'islamic-tbla' => ['ah', 'bh'],
        'islamic-umalqura' => ['ah', 'bh'],
        'persian' => ['ap'],
    ];

    /**
     * Era alias map: alternate/deprecated era names → canonical era name.
     *
     * @var array<string, string>
     */
    private const ERA_ALIASES = [
        'ad' => 'ce',
        'bc' => 'bce',
    ];

    #[\Override]
    public function resolveEra(string $era, int $eraYear): int
    {
        // Canonicalize era aliases (e.g. 'ad' → 'ce', 'bc' → 'bce').
        $era = self::ERA_ALIASES[$era] ?? $era;

        $validEras = self::VALID_ERAS[$this->calendarId] ?? [];
        if (!in_array($era, $validEras, strict: true)) {
            throw new RangeError("Invalid era \"{$era}\" for calendar \"{$this->calendarId}\".");
        }

        return match ($this->calendarId) {
            'gregory' => $era === 'bce' ? 1 - $eraYear : $eraYear,
            'japanese' => $this->resolveJapaneseEra($era, $eraYear),
            'buddhist' => $eraYear,
            'roc' => $era === 'before-roc' || $era === 'broc' ? 1 - $eraYear : $eraYear,
            'coptic' => $era === 'era0' ? 1 - $eraYear : $eraYear,
            'ethiopic' => $this->resolveEthiopicEra($era, $eraYear),
            'ethioaa' => $eraYear,
            'persian' => $eraYear,
            default => $this->resolveIslamicEra($era, $eraYear),
        };
    }

    private function resolveJapaneseEra(string $era, int $eraYear): int
    {
        if ($era === 'bce') {
            return 1 - $eraYear;
        }
        if ($era === 'ce') {
            return $eraYear;
        }
        $startYear = self::JAPANESE_ERA_TO_START[$era] ?? throw new RangeError("Unknown Japanese era \"{$era}\".");
        return $startYear + $eraYear - 1;
    }

    private function resolveEthiopicEra(string $era, int $eraYear): int
    {
        // 'aa' and 'era0' are the Amete Alem era; 'am' and 'era1' are Amete Mihret.
        // For ethiopic calendar, year property = FIELD_YEAR in the current era.
        // era0/aa: year = eraYear offset by 5500 from era1
        // era1/am: year = eraYear
        if ($era === 'era0' || $era === 'aa') {
            // Amete Alem year to Amete Mihret: year = eraYear - 5500
            return $eraYear - 5500;
        }
        return $eraYear;
    }

    private function resolveIslamicEra(string $era, int $eraYear): int
    {
        if ($era === 'bh') {
            return 1 - $eraYear;
        }
        return $eraYear;
    }

    // -------------------------------------------------------------------------
    // Internal: set the IntlCalendar from calendar-specific fields
    // -------------------------------------------------------------------------

    /**
     * Sets the IntlCalendar from calendar-specific year, ordinal month, and day.
     *
     * These calendars map ordinal months directly to ICU month slots.
     */
    private function setCalendarFields(int $calYear, int $calMonth, int $calDay): void
    {
        $this->gregorianMaxDay = null;
        $this->lastSetJdn = null;

        // For calendars using Gregorian months (same 1-12 months as ISO, just
        // a year offset), compute ISO date directly to avoid ICU's Julian cutover.
        // Set to day 1 of the target month, then set DAY_OF_MONTH to the requested day.
        // Day overflow is handled by resolveAndConstrain (for calendarToIso) or
        // is intentional (for dateAdd/dateUntil arithmetic).
        $isoYear = match ($this->calendarId) {
            'japanese' => $calYear,
            'buddhist' => $calYear - 543,
            'roc' => $calYear + self::ROC_YEAR_OFFSET,
            default => null,
        };
        if ($isoYear !== null) {
            $jdn = CalendarMath::toJulianDay($isoYear, $calMonth, 1);
            $epochMs = ($jdn - 2_440_588) * self::MS_PER_DAY;
            $this->intlCal->setTime((float) $epochMs);
            $this->intlCal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $calDay);
            return;
        }

        $this->intlCal->clear();

        if (in_array($this->calendarId, ['coptic', 'ethiopic'], strict: true)) {
            $this->intlCal->set(self::FIELD_EXTENDED_YEAR, $calYear);
            $this->intlCal->set(\IntlCalendar::FIELD_MONTH, $calMonth - 1);
        } else {
            $this->intlCal->set(\IntlCalendar::FIELD_YEAR, $calYear);
            $this->intlCal->set(\IntlCalendar::FIELD_MONTH, $calMonth - 1);
        }

        $this->intlCal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $calDay);
    }

    /**
     * When the Gregorian shortcut is used, stores the pre-computed max day
     * for the target month (null for non-Gregorian calendars).
     */
    private ?int $gregorianMaxDay = null;

    private function setCalendarFieldsFromMonthCode(int $calYear, string $monthCode, int $calDay): void
    {
        $this->lastSetJdn = null;
        // For Gregorian-based calendars, use direct ISO conversion to avoid Julian cutover.
        $isoYear = match ($this->calendarId) {
            'gregory', 'japanese' => $calYear,
            'buddhist' => $calYear - 543,
            'roc' => $calYear + self::ROC_YEAR_OFFSET,
            default => null,
        };
        if ($isoYear !== null) {
            $month = $this->defaultMonthCodeToMonth($monthCode);
            // Pre-compute the max day for this month for overflow handling in resolveAndConstrain.
            $this->gregorianMaxDay = CalendarMath::calcDaysInMonth($isoYear, $month);
            // Clamp the day to avoid JDN overflow into the next month.
            $clampedDay = min($calDay, $this->gregorianMaxDay);
            $jdn = CalendarMath::toJulianDay($isoYear, $month, $clampedDay);
            $epochMs = ($jdn - 2_440_588) * self::MS_PER_DAY;
            $this->intlCal->setTime((float) $epochMs);
            return;
        }
        $this->gregorianMaxDay = null;

        $this->intlCal->clear();

        if (in_array($this->calendarId, ['coptic', 'ethiopic'], strict: true)) {
            $this->intlCal->set(self::FIELD_EXTENDED_YEAR, $calYear);
        } else {
            $this->intlCal->set(\IntlCalendar::FIELD_YEAR, $calYear);
        }

        $this->intlCal->set(\IntlCalendar::FIELD_MONTH, $this->defaultMonthCodeToMonth($monthCode) - 1);

        $this->intlCal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $calDay);
    }

    /**
     * Reads back epoch ms from IntlCalendar, converts to ISO, and applies overflow handling.
     *
     * @return array{int, int<1, 12>, int<1, 31>} [isoYear, isoMonth, isoDay]
     */
    private function resolveAndConstrain(int $calDay, string $overflow): array
    {
        // Use pre-computed max day for Gregorian-based calendars (the JDN shortcut
        // clamps the day to avoid month overflow, so ICU's getActualMaximum would
        // report the wrong month's max if the day overflowed).
        $maxDay = $this->gregorianMaxDay ?? $this->intlCal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);

        if ($overflow === 'reject') {
            if ($calDay > $maxDay) {
                throw new RangeError("Day {$calDay} exceeds maximum {$maxDay} for this calendar month.");
            }
        }
        // For Gregorian-based, the day was already clamped in setCalendarFieldsFromMonthCode.
        // For non-Gregorian with constrain, clamp via ICU.
        if ($overflow === 'constrain' && $this->gregorianMaxDay === null && $calDay > $maxDay) {
            $this->intlCal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $maxDay);
        }

        $epochMs = $this->intlCal->getTime();
        $jdn = (int) floor($epochMs / (float) self::MS_PER_DAY) + 2_440_588;
        return CalendarMath::fromJulianDay($jdn);
    }

    // -------------------------------------------------------------------------
    // Internal: set the IntlCalendar to an ISO date
    // -------------------------------------------------------------------------

    private function setIsoDate(int $isoYear, int $isoMonth, int $isoDay): void
    {
        // Fast short-circuit before toJulianDay.
        if (
            $this->lastSetJdn !== null
            && $this->lastSetIsoYear === $isoYear
            && $this->lastSetIsoMonth === $isoMonth
            && $this->lastSetIsoDay === $isoDay
        ) {
            return;
        }
        $jdn = CalendarMath::toJulianDay($isoYear, $isoMonth, $isoDay);
        $epochMs = ($jdn - 2_440_588) * self::MS_PER_DAY;
        $this->intlCal->setTime((float) $epochMs);
        $this->lastSetJdn = $jdn;
        $this->lastSetIsoYear = $isoYear;
        $this->lastSetIsoMonth = $isoMonth;
        $this->lastSetIsoDay = $isoDay;
    }

    // -------------------------------------------------------------------------
    // Japanese era helper
    // -------------------------------------------------------------------------

    /**
     * Japanese era start dates as [isoYear, isoMonth, isoDay].
     * Note: Meiji uses 1873-01-01 (when Japan adopted the Gregorian calendar),
     * not the traditional 1868 date, because TC39 maps pre-1873 dates to 'ce'.
     */
    private const JAPANESE_ERA_STARTS = [
        'reiwa' => [2019, 5, 1],
        'heisei' => [1989, 1, 8],
        'showa' => [1926, 12, 25],
        'taisho' => [1912, 7, 30],
        'meiji' => [1873, 1, 1],
    ];

    /**
     * Returns the TC39 era string for a Japanese date from ISO fields (proleptic).
     */
    private function japaneseEraFromIso(int $isoYear, int $isoMonth, int $isoDay): string
    {
        foreach (self::JAPANESE_ERA_STARTS as $era => [$startY, $startM, $startD]) {
            if (
                $isoYear > $startY
                || $isoYear === $startY && $isoMonth > $startM
                || $isoYear === $startY && $isoMonth === $startM && $isoDay >= $startD
            ) {
                return $era;
            }
        }
        return $isoYear >= 1 ? 'ce' : 'bce';
    }

    /**
     * Returns the TC39 eraYear for a Japanese date from ISO fields (proleptic).
     * eraYear uses the actual era start year (not the display cutover).
     */
    private function japaneseEraYearFromIso(int $isoYear, int $isoMonth, int $isoDay): int
    {
        /** @var array<string, int> Actual start years for eraYear computation */
        static $eraStartYears = [
            'reiwa' => 2019,
            'heisei' => 1989,
            'showa' => 1926,
            'taisho' => 1912,
            'meiji' => 1868,
        ];
        $era = $this->japaneseEraFromIso($isoYear, $isoMonth, $isoDay);
        if (array_key_exists($era, $eraStartYears)) {
            return $isoYear - $eraStartYears[$era] + 1;
        }
        // ce/bce fallback
        return $isoYear >= 1 ? $isoYear : 1 - $isoYear;
    }
}
