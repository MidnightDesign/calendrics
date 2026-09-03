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
    /** Milliseconds per day. */
    private const int MS_PER_DAY = 86_400_000;

    /** ICU field ID for EXTENDED_YEAR (not defined as a PHP constant). */
    private const int FIELD_EXTENDED_YEAR = 19;

    /** ICU field ID for IS_LEAP_MONTH. */
    private const int FIELD_IS_LEAP_MONTH = 22;

    /**
     * Map from TC39 calendar ID to ICU calendar type.
     *
     * @var array<string, string>
     */
    private const CALENDAR_TO_ICU = [
        'buddhist' => 'buddhist',
        'chinese' => 'chinese',
        'coptic' => 'coptic',
        'dangi' => 'dangi',
        'ethioaa' => 'ethiopic-amete-alem',
        'ethiopic' => 'ethiopic',
        'gregory' => 'gregorian',
        'islamic-civil' => 'islamic-civil',
        'islamic-tbla' => 'islamic-tbla',
        'islamic-umalqura' => 'islamic-umalqura',
        'japanese' => 'japanese',
        'persian' => 'persian',
        'roc' => 'roc',
    ];

    private readonly \IntlCalendar $intlCal;

    /** Calendars whose year/month/day share the ISO 8601 proleptic Gregorian structure. */
    private readonly bool $isGregorianBased;
    /** Calendars with 13 months (coptic/ethiopic family). */
    private readonly bool $isCopticLike;
    /**
     * Calendars where ICU's getActualMaximum() returns stale values after
     * setTime() unless the internal fields are explicitly resolved. Applies
     * to chinese/dangi leap-month handling.
     */
    private readonly bool $needsForcedFieldResolution;

    /** @var array<int, int> Memoized findChineseLeapMonthInYear results, keyed by calYear. */
    private array $chineseLeapMonthCache = [];

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
     * @var array<string, array{0: int, 1: int<1, 12>, 2: int<1, 31>}>
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
        $icuType = self::CALENDAR_TO_ICU[$calendarId] ?? throw new RangeError(
            "No ICU mapping for calendar \"{$calendarId}\".",
        );
        $cal = \IntlCalendar::createInstance('UTC', sprintf('@calendar=%s', $icuType));
        // TC39 requires proleptic Gregorian (no Julian cutover). ICU's Gregorian
        // calendar defaults to a 1582-10-15 cutover; setting the change date to
        // the minimum float value makes it fully proleptic.
        if ($cal instanceof \IntlGregorianCalendar) {
            $cal->setGregorianChange(PHP_FLOAT_MIN);
        }
        // IntlCalendar::createInstance is signature-nullable, but per
        // ext/intl/calendar/calendar_methods.cpp it can only return null when
        // (a) the timezone arg is invalid (we pass the literal 'UTC') or
        // (b) ICU fails under OOM. No analyzer narrows by argument literal,
        // so suppress the ones that flag this assignment.
        // @mago-ignore analysis:invalid-property-assignment-value
        /** @psalm-suppress PossiblyNullPropertyAssignmentValue */
        $this->intlCal = $cal;
        $this->isGregorianBased = match ($calendarId) {
            'gregory', 'japanese', 'buddhist', 'roc' => true,
            default => false,
        };
        $this->isCopticLike = match ($calendarId) {
            'coptic', 'ethiopic', 'ethioaa' => true,
            default => false,
        };
        $this->needsForcedFieldResolution = match ($calendarId) {
            'chinese', 'dangi' => true,
            default => false,
        };
    }

    // -------------------------------------------------------------------------
    // ISO -> Calendar field projection
    // -------------------------------------------------------------------------

    /** Offset from ICU EXTENDED_YEAR to TC39 "related Gregorian year" for Chinese. */
    private const CHINESE_YEAR_OFFSET = 2637;

    /**
     * ICU 76.1 reports wrong daysInYear for these Chinese calendar years.
     * Keys are TC39 related-Gregorian years, values are correct day counts.
     */
    private const CHINESE_DAYS_IN_YEAR_CORRECTIONS = [
        2026 => 354,
        2027 => 354,
        2029 => 355,
        2030 => 354,
    ];

    /**
     * ICU 76.1 reports the wrong leap month for these Chinese calendar years.
     * Keys are TC39 related-Gregorian years, values are the correct ICU month
     * index (0-based) after which the leap month falls.
     */
    private const CHINESE_LEAP_MONTH_CORRECTIONS = [
        1987 => 5, // Correct: leap after ICU month 5 (M06L), ICU says 6 (M07L)
    ];

    /** Offset from ICU EXTENDED_YEAR to TC39 "related Gregorian year" for Dangi. */
    private const DANGI_YEAR_OFFSET = 2333;

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
            $this->calendarId === 'chinese' => $this->intlCal->get(self::FIELD_EXTENDED_YEAR)
                - self::CHINESE_YEAR_OFFSET,
            $this->calendarId === 'dangi' => $this->intlCal->get(self::FIELD_EXTENDED_YEAR) - self::DANGI_YEAR_OFFSET,
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

        $v = match ($this->calendarId) {
            'chinese', 'dangi' => $this->chineseMonthOrdinal(),
            default => $this->intlCal->get(\IntlCalendar::FIELD_MONTH) + 1,
        };
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

        $v = match ($this->calendarId) {
            'chinese', 'dangi' => $this->chineseMonthCode(),
            default => sprintf('M%02d', $this->intlCal->get(\IntlCalendar::FIELD_MONTH) + 1),
        };
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

        // Apply ICU 76.1 correction for known Chinese calendar discrepancies.
        if ($this->calendarId === 'chinese') {
            $calYear = $this->intlCal->get(self::FIELD_EXTENDED_YEAR) - self::CHINESE_YEAR_OFFSET;
            if (array_key_exists($calYear, self::CHINESE_DAYS_IN_YEAR_CORRECTIONS)) {
                $v = self::CHINESE_DAYS_IN_YEAR_CORRECTIONS[$calYear];
                if (count($this->daysInYearCache) >= self::FIELD_CACHE_CAP) {
                    $this->daysInYearCache = [];
                }
                return $this->daysInYearCache[$key] = $v;
            }
        }

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

        $v = match ($this->calendarId) {
            'chinese', 'dangi' => $this->hasChineseLeapMonth() ? 13 : 12,
            default => $this->intlCal->getActualMaximum(\IntlCalendar::FIELD_MONTH) + 1,
        };
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
            'chinese', 'dangi' => $this->hasChineseLeapMonth(),
            'coptic', 'ethiopic', 'ethioaa' => $this->intlCal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_YEAR) > 365,
            'persian' => $this->intlCal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_YEAR) > 365,
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
            $maxMonths = $this->calendarMonthsInCalYear($calYear);
            if ($calMonth > $maxMonths) {
                throw new RangeError("Month {$calMonth} exceeds maximum {$maxMonths} for this calendar year.");
            }
        } elseif ($calMonth > 12) {
            // For non-Gregorian constrain, check if month exceeds max.
            $maxMonths = $this->calendarMonthsInCalYear($calYear);
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
     * @return array{0: int, 1: int<1, 12>, 2: int<1, 31>}
     */
    private function calendarToIsoFromMonthCodeUncached(
        int $calYear,
        string $monthCode,
        int $calDay,
        string $overflow,
    ): array {
        $isLeapCode = str_ends_with($monthCode, 'L');

        // For Chinese/Dangi leap month codes, first verify the leap month exists
        // in this year using day 1 (to avoid day overflow changing the month).
        if ($isLeapCode && in_array($this->calendarId, ['chinese', 'dangi'], strict: true)) {
            // For years with known ICU leap month corrections, validate using
            // the corrected data instead of querying ICU's IS_LEAP_MONTH flag.
            $hasCorrection =
                $this->calendarId === 'chinese' && array_key_exists($calYear, self::CHINESE_LEAP_MONTH_CORRECTIONS);

            if ($hasCorrection) {
                $correctLeapIcu = self::CHINESE_LEAP_MONTH_CORRECTIONS[$calYear];
                $baseCode = substr($monthCode, offset: 0, length: -1);
                $baseNum = (int) substr($baseCode, offset: 1);
                // The leap month code MxxL is valid only if xx-1 matches the corrected leap ICU month.
                if (($baseNum - 1) !== $correctLeapIcu) {
                    if ($overflow === 'constrain') {
                        $this->setCalendarFieldsFromMonthCode($calYear, $baseCode, $calDay);
                        return $this->resolveAndConstrain($calDay, $overflow);
                    }
                    throw new RangeError("monthCode \"{$monthCode}\" does not exist in this calendar year.");
                }
            } else {
                try {
                    $this->setCalendarFieldsFromMonthCode($calYear, $monthCode, 1);
                    $_ = $this->intlCal->get(\IntlCalendar::FIELD_MONTH);
                    if ($this->intlCal->get(self::FIELD_IS_LEAP_MONTH) !== 1) {
                        throw new RangeError("monthCode \"{$monthCode}\" does not exist in this calendar year.");
                    }
                } catch (RangeError $e) {
                    if ($overflow === 'constrain') {
                        // Chinese/Dangi: MxxL → Mxx (the regular version of the same month).
                        $baseCode = substr($monthCode, offset: 0, length: -1);
                        $this->setCalendarFieldsFromMonthCode($calYear, $baseCode, $calDay);
                        return $this->resolveAndConstrain($calDay, $overflow);
                    }
                    throw $e;
                }
            }
        }

        try {
            $this->setCalendarFieldsFromMonthCode($calYear, $monthCode, $calDay);
        } catch (RangeError $e) {
            // Leap month code in a year without that leap month: constrain.
            if ($overflow === 'constrain' && $isLeapCode) {
                $baseCode = substr($monthCode, offset: 0, length: -1);
                $this->setCalendarFieldsFromMonthCode($calYear, $baseCode, $calDay);
            } else {
                throw $e;
            }
        }
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
        // Gregorian-based fast path: skip every intlCal round-trip when both
        // the input and the resulting ISO year are >= 1583 (past the 1582
        // Julian cutover that japanese/buddhist/roc still honor in ICU). For
        // bare 'gregory' the cutover was disabled in the constructor, so the
        // fast path always applies. Within this window the calendar fields
        // equal the ISO fields, daysInMonth is pure ISO math, and monthsInYear
        // is 12.
        if ($this->isGregorianBased) {
            $totalMonths = $isoMonth + $months - 1;
            $yearAdd = CalendarMath::floorDiv($totalMonths, 12);
            $calMonth = $totalMonths - ($yearAdd * 12) + 1;
            $finalIsoYear = $isoYear + $years + $yearAdd;
            $cutoverSafe = $this->calendarId === 'gregory' || $isoYear >= 1583 && $finalIsoYear >= 1583;
            if ($cutoverSafe) {
                $newMaxDay = CalendarMath::calcDaysInMonth($finalIsoYear, $calMonth);
                if ($overflow === 'reject' && $isoDay > $newMaxDay) {
                    throw new RangeError(
                        "Day {$isoDay} exceeds maximum {$newMaxDay} for the resulting calendar month.",
                    );
                }
                $finalDay = $isoDay > $newMaxDay ? $newMaxDay : $isoDay;
                $jdn = CalendarMath::toJulianDay($finalIsoYear, $calMonth, $finalDay) + ($weeks * 7) + $days;
                return CalendarMath::fromJulianDay($jdn);
            }
        }

        if ($years !== 0 || $months !== 0) {
            // Capture the original calendar day and year/month. Use field-level
            // arithmetic: read calendar fields, add to year/month, resolve back.
            // This avoids Julian cutover issues in ICU for the gregorian path
            // (japanese/buddhist/roc retain ICU's 1582 cutover; only the bare
            // gregorian IntlCalendar was made proleptic in the constructor).
            if ($this->isGregorianBased) {
                // Fallback path only runs pre-1583 for buddhist/roc/japanese; the
                // ICU state is needed to read the Julian-adjusted FIELD_DAY_OF_MONTH.
                $this->setIsoDate($isoYear, $isoMonth, $isoDay);
                $originalCalDay = $this->intlCal->get(\IntlCalendar::FIELD_DAY_OF_MONTH);
                $calYear = $this->calendarYear();
                $calMonth = $this->calendarMonth();
            } else {
                $originalCalDay = $this->day($isoYear, $isoMonth, $isoDay);
                $calYear = $this->year($isoYear, $isoMonth, $isoDay);
                $calMonth = $this->month($isoYear, $isoMonth, $isoDay);
                // chinese/dangi year-addition reads ICU fields directly below.
                // setCalendarFields later calls intlCal->clear() which wipes state,
                // so other non-gregorian calendars don't need setIsoDate at all.
                if ($years !== 0 && ($this->calendarId === 'chinese' || $this->calendarId === 'dangi')) {
                    $this->setIsoDate($isoYear, $isoMonth, $isoDay);
                }
            }

            // For calendars with leap months, year addition must preserve monthCode
            // (not ordinal position), because leap months shift ordinals between years.
            if ($years !== 0 && in_array($this->calendarId, ['chinese', 'dangi'], strict: true)) {
                $icuMonth = $this->intlCal->get(\IntlCalendar::FIELD_MONTH);
                $isLeap = $this->intlCal->get(self::FIELD_IS_LEAP_MONTH);
                $calYear += $years;
                $calMonth = $this->chineseIcuMonthToOrdinal($calYear, $icuMonth, $isLeap, $overflow);
            } else {
                $calYear += $years;
            }

            $calMonth += $months;

            // Handle month overflow/underflow.
            while ($calMonth < 1) {
                $calYear--;
                $calMonth += $this->calendarMonthsInCalYear($calYear);
            }
            while (true) {
                $monthsInYear = $this->calendarMonthsInCalYear($calYear);
                if ($calMonth <= $monthsInYear) {
                    break;
                }
                $calMonth -= $monthsInYear;
                $calYear++;
            }

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
        // TC39 CalendarDateUntil: iterate from date1 toward date2 WITHOUT
        // swapping. The direction (sign) determines whether we add positive or
        // negative year/month increments. This is essential for leap-month
        // calendars where forward and backward traversal cross different months.

        $jdn1 = CalendarMath::toJulianDay($isoY1, $isoM1, $isoD1);
        $jdn2 = CalendarMath::toJulianDay($isoY2, $isoM2, $isoD2);

        if ($jdn1 === $jdn2) {
            return [0, 0, 0, 0];
        }

        $sign = $jdn2 > $jdn1 ? 1 : -1;

        // Read calendar fields.
        $this->setIsoDate($isoY1, $isoM1, $isoD1);
        $calY1 = $this->calendarYear();

        $this->setIsoDate($isoY2, $isoM2, $isoD2);
        $calY2 = $this->calendarYear();

        $years = 0;
        $months = 0;

        if ($largestUnit === 'year') {
            // Find years: start from a conservative estimate and increment in
            // the sign direction until one more would overshoot.
            $yearDiff = abs($calY2 - $calY1);
            $years = max(0, $yearDiff - 1);

            while ($this->trialDateAddDoesNotSurpass($isoY1, $isoM1, $isoD1, $sign * ($years + 1), 0, $jdn2, $sign)) {
                $years++;
            }

            // Find months within remaining partial year, starting from 0.
            while ($this->trialDateAddDoesNotSurpass(
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
            // Find total months: use a conservative estimate, then increment.
            $yearDiff = abs($calY2 - $calY1);
            if ($yearDiff > 1) {
                // For large spans, estimate conservatively: sum months across
                // intermediate years (excluding start and end partial years),
                // then back off generously to ensure we don't overshoot.
                $monthEstimate = $this->totalMonthsInYearsDirectional(
                    $isoY1,
                    $isoM1,
                    $isoD1,
                    max(0, $yearDiff - 1),
                    $sign,
                );
                $months = max(0, $monthEstimate - 14);
            }

            while ($this->trialDateAddDoesNotSurpass($isoY1, $isoM1, $isoD1, 0, $sign * ($months + 1), $jdn2, $sign)) {
                $months++;
            }
        }

        // Remaining days: add the found years+months from date1, measure JDN to date2.
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

    /**
     * Tests whether dateAdd(start, years, months) does not surpass the target JDN.
     * "Surpass" means overshoot in the given direction: for sign=+1, result <= target;
     * for sign=-1, result >= target.
     *
     * Uses 'constrain' overflow for all trials, matching TC39's CalendarDateUntil
     * algorithm (Step 11.f: CalendarDateAdd with "constrain"). For day constraining
     * (e.g., Jan 30 -> Feb 28), the original day is restored for comparison purposes.
     */
    private function trialDateAddDoesNotSurpass(
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
            // Year-only trial: check if the calendar day was constrained
            // (e.g. day 30 -> day 29 in a shorter month). If so, the trial
            // didn't preserve the exact date, so use strict inequality.
            $origCalDay = $this->day($isoY1, $isoM1, $isoD1);
            $trialCalDay = $this->day($tY, $tM, $tD);
            $dayConstrained = $trialCalDay < $origCalDay;

            // For leap-month calendars, also check monthCode constraining.
            $monthConstrained = false;
            $constrainedOrdEarlier = false;
            if (in_array($this->calendarId, ['chinese', 'dangi'], strict: true)) {
                $origMonthCode = $this->monthCode($isoY1, $isoM1, $isoD1);
                $trialMonthCode = $this->monthCode($tY, $tM, $tD);
                if ($origMonthCode !== $trialMonthCode) {
                    $monthConstrained = true;
                    $origOrd = $this->month($isoY1, $isoM1, $isoD1);
                    $trialOrd = $this->month($tY, $tM, $tD);
                    $constrainedOrdEarlier = $trialOrd < $origOrd;
                }
            }

            if ($monthConstrained) {
                // When day is also constrained, always use strict inequality
                // because the position shifted both in month and day.
                if ($dayConstrained) {
                    return $sign > 0 ? $trialJdn < $targetJdn : $trialJdn > $targetJdn;
                }
                if ($sign > 0) {
                    // Forward: ordinal decreased -> use strict <.
                    // Ordinal same/increased -> use <=.
                    return $constrainedOrdEarlier ? $trialJdn < $targetJdn : $trialJdn <= $targetJdn;
                }
                // Backward: ordinal increased/same -> use strict >.
                // Ordinal decreased -> use >=.
                return $constrainedOrdEarlier ? $trialJdn >= $targetJdn : $trialJdn > $targetJdn;
            }

            if ($dayConstrained) {
                // Day was constrained.
                // Forward: use strict < so a trial that landed early (day
                // clamped down) does not prematurely count as "within range".
                // Backward: use non-strict >= so a trial whose day was clamped
                // down (e.g. leap-year M13-day6 → common-year M13-day5) that
                // lands exactly ON the target still counts as a full year
                // difference, matching TC39 NonISODateUntil constrain semantics.
                return $sign > 0 ? $trialJdn < $targetJdn : $trialJdn >= $targetJdn;
            }

            return $sign > 0 ? $trialJdn <= $targetJdn : $trialJdn >= $targetJdn;
        }

        // Month trials: check if day was constrained and adjust.
        $origCalDay = $this->day($isoY1, $isoM1, $isoD1);
        $trialCalDay = $this->day($tY, $tM, $tD);

        if ($trialCalDay < $origCalDay) {
            // Day was constrained. Adjust the JDN to pretend the original day
            // was preserved, ensuring correct month counting at boundaries.
            $trialJdn += $origCalDay - $trialCalDay;
        }

        return $sign > 0 ? $trialJdn <= $targetJdn : $trialJdn >= $targetJdn;
    }

    /**
     * Estimates total months between two points by summing months-in-year
     * across intermediate years, walking in the given direction.
     */
    private function totalMonthsInYearsDirectional(int $isoY, int $isoM, int $isoD, int $yearCount, int $sign): int
    {
        $total = 0;
        $curIsoY = $isoY;
        $curIsoM = $isoM;
        $curIsoD = $isoD;
        for ($i = 0; $i < $yearCount; $i++) {
            $this->setIsoDate($curIsoY, $curIsoM, $curIsoD);
            $total += $this->calendarMonthsInYear();
            [$curIsoY, $curIsoM, $curIsoD] = $this->dateAdd($curIsoY, $curIsoM, $curIsoD, $sign, 0, 0, 0, 'constrain');
        }
        return $total;
    }

    /**
     * Returns the calendar year from the currently-set IntlCalendar state.
     * For Gregorian-based calendars, convert via the cached ISO year.
     */
    private function calendarYear(): int
    {
        // For Gregorian-based calendars, derive directly from the cached ISO
        // year. Every caller runs setIsoDate() immediately before this, so
        // $lastSetIsoYear is always valid on the Gregorian path.
        if (match ($this->calendarId) {
            'gregory', 'japanese', 'buddhist', 'roc' => true,
            default => false,
        }) {
            $isoY = $this->lastSetIsoYear;
            return match ($this->calendarId) {
                'gregory', 'japanese' => $isoY,
                'buddhist' => $isoY + 543,
                'roc' => $isoY - self::ROC_YEAR_OFFSET,
                default => $isoY,
            };
        }
        return match ($this->calendarId) {
            'coptic', 'ethiopic' => $this->intlCal->get(self::FIELD_EXTENDED_YEAR),
            'chinese' => $this->intlCal->get(self::FIELD_EXTENDED_YEAR) - self::CHINESE_YEAR_OFFSET,
            'dangi' => $this->intlCal->get(self::FIELD_EXTENDED_YEAR) - self::DANGI_YEAR_OFFSET,
            default => $this->intlCal->get(\IntlCalendar::FIELD_YEAR),
        };
    }

    /**
     * Returns the calendar ordinal month from the currently-set IntlCalendar state.
     *
     * The only caller (dateAdd's pre-1583 Gregorian fallback) is gated on
     * {@see $isGregorianBased} and always runs setIsoDate() first, so the
     * calendar is Gregorian-based (ordinal month == ISO month) and the ISO
     * fields are cached.
     */
    private function calendarMonth(): int
    {
        return $this->lastSetIsoMonth;
    }

    /**
     * Returns the months-in-year for the current calendar date.
     * Must call setIsoDate() or setCalendarFields() first for Chinese/Dangi.
     */
    private function calendarMonthsInYear(): int
    {
        return match ($this->calendarId) {
            'chinese', 'dangi' => $this->hasChineseLeapMonth() ? 13 : 12,
            // These Ethiopic/Coptic-family calendars always have 13 months (12 × 30 + 5/6 epagomenal).
            'coptic', 'ethiopic', 'ethioaa' => 13,
            // All other calendars (gregory, japanese, buddhist, roc, persian,
            // islamic-*) always have 12 months per year.
            default => 12,
        };
    }

    /**
     * Stateless variant of calendarMonthsInYear — takes calYear directly.
     * Avoids needing to setCalendarFields() before querying.
     */
    private function calendarMonthsInCalYear(int $calYear): int
    {
        return match ($this->calendarId) {
            'chinese', 'dangi' => $this->findChineseLeapMonthInYear($calYear) >= 0 ? 13 : 12,
            'coptic', 'ethiopic', 'ethioaa' => 13,
            default => 12,
        };
    }

    /**
     * Sums months-in-year for $count consecutive years starting from calYear.
     * Used to collapse years into months for largestUnit='month'.
     */
    // -------------------------------------------------------------------------
    // Month code utilities
    // -------------------------------------------------------------------------

    #[\Override]
    public function monthCodeToMonth(string $monthCode, int $calYear, string $overflow = 'reject'): int
    {
        return match ($this->calendarId) {
            'chinese', 'dangi' => $this->chineseMonthCodeToMonth($monthCode, $calYear, $overflow),
            default => $this->defaultMonthCodeToMonth($monthCode),
        };
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

    /**
     * Chinese/Dangi monthCode → ordinal month.
     *
     * "M01"-"M12" are regular months; "MxxL" is a leap month following month xx.
     * The ordinal depends on which months precede it in the year (including any leap month).
     */
    private function chineseMonthCodeToMonth(string $monthCode, int $calYear, string $overflow = 'reject'): int
    {
        // Leap-month existence and constraining are resolved downstream in
        // calendarToIsoFromMonthCode; this helper only maps a code to its ordinal.
        unset($overflow);

        $isLeapCode = str_ends_with($monthCode, 'L');
        $baseCode = $isLeapCode ? substr($monthCode, offset: 0, length: -1) : $monthCode;

        $m = null;
        if (preg_match('/^M(\d{2})$/', $baseCode, $m) !== 1) {
            throw new RangeError("Invalid monthCode \"{$monthCode}\" for calendar \"{$this->calendarId}\".");
        }
        $baseNum = (int) $m[1]; // 1-12
        if ($baseNum < 1 || $baseNum > 12) {
            throw new RangeError("monthCode \"{$monthCode}\" is out of range for calendar \"{$this->calendarId}\".");
        }

        // Find the leap month in this year (if any) by scanning via ICU.
        $leapIcuMonth = $this->findChineseLeapMonthInYear($calYear);

        if ($isLeapCode) {
            // Leap month after base month xx. ICU month = baseNum - 1, IS_LEAP_MONTH = 1.
            // Ordinal = baseNum (for regular months before it) + 1 (for the leap month itself).
            // But if a leap month occurred before baseNum, we must account for it.
            // Actually for Chinese calendar, there is at most one leap month per year.
            // The ordinal of a leap month MxxL = baseNum + 1 (the regular month xx has ordinal baseNum,
            // then the leap month follows as baseNum+1).
            // But if the leap month is before another regular month, that shifts ordinals too.
            // Since this IS the leap month: ordinal = baseNum + 1.
            return $baseNum + 1;
        }

        // Regular month Mxx: ordinal = baseNum unless a leap month precedes it.
        // A leap month after ICU month $leapIcuMonth (0-indexed) means all regular months
        // with ICU month > leapIcuMonth have their ordinal incremented by 1.
        // baseNum maps to ICU month baseNum - 1.
        $ordinal = $baseNum;
        if ($leapIcuMonth >= 0 && ($baseNum - 1) > $leapIcuMonth) {
            $ordinal = $baseNum + 1;
        }
        return $ordinal;
    }

    /**
     * Finds the ICU month index (0-based) that has a leap month following it in the given
     * Chinese/Dangi calendar year, or -1 if none.
     */
    private function findChineseLeapMonthInYear(int $calYear): int
    {
        if (array_key_exists($calYear, $this->chineseLeapMonthCache)) {
            return $this->chineseLeapMonthCache[$calYear];
        }

        // Apply ICU 76.1 correction for known leap month discrepancies.
        if ($this->calendarId === 'chinese' && array_key_exists($calYear, self::CHINESE_LEAP_MONTH_CORRECTIONS)) {
            return $this->chineseLeapMonthCache[$calYear] = self::CHINESE_LEAP_MONTH_CORRECTIONS[$calYear];
        }

        $savedTime = $this->intlCal->getTime();
        $icuYear = $calYear + ($this->calendarId === 'chinese' ? self::CHINESE_YEAR_OFFSET : self::DANGI_YEAR_OFFSET);

        $result = -1;
        for ($m = 0; $m <= 11; $m++) {
            $this->intlCal->clear();
            $this->intlCal->set(self::FIELD_EXTENDED_YEAR, $icuYear);
            $this->intlCal->set(\IntlCalendar::FIELD_MONTH, $m);
            $this->intlCal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 15);
            $_ = $this->intlCal->get(\IntlCalendar::FIELD_MONTH);

            $daysInMonth = $this->intlCal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
            $this->intlCal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $daysInMonth);
            $this->intlCal->add(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);

            if ($this->intlCal->get(self::FIELD_IS_LEAP_MONTH) === 1) {
                $result = $m;
                break;
            }
        }

        $this->intlCal->setTime($savedTime);
        return $this->chineseLeapMonthCache[$calYear] = $result;
    }

    /**
     * Converts an ICU month + IS_LEAP_MONTH flag to an ordinal month in the
     * given Chinese/Dangi calendar year. If the source was a leap month that
     * does not exist in the target year, constrains to the non-leap version.
     */
    private function chineseIcuMonthToOrdinal(
        int $calYear,
        int $icuMonth,
        int $isLeap,
        string $overflow = 'constrain',
    ): int {
        $leapIcuMonth = $this->findChineseLeapMonthInYear($calYear);

        if ($isLeap !== 0) {
            // Source was a leap month. If the target year has the same leap
            // month, return its ordinal. Otherwise constrain/reject.
            if ($leapIcuMonth === $icuMonth) {
                return $icuMonth + 2; // leap month ordinal = ICU month + 2
            }
            if ($overflow === 'reject') {
                $monthCode = sprintf('M%02dL', $icuMonth + 1);
                throw new RangeError("monthCode \"{$monthCode}\" does not exist in this calendar year.");
            }

            // Constrain: use the non-leap version of the same ICU month.
        }

        // Regular month: ordinal = icuMonth + 1, shifted by +1 if a leap
        // month precedes it in this year.
        $ordinal = $icuMonth + 1;
        if ($leapIcuMonth >= 0 && $icuMonth > $leapIcuMonth) {
            $ordinal++;
        }
        return $ordinal;
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
    public function resolveEra(string $era, int $eraYear): ?int
    {
        // Chinese/Dangi have no eras — signal to caller to ignore.
        if ($this->calendarId === 'chinese' || $this->calendarId === 'dangi') {
            return null;
        }

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
     * Maps TC39 ordinal month to the appropriate ICU month slot, which differs
     * for calendars with intercalary or leap months (Chinese/Dangi).
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
            'gregory', 'japanese' => $calYear,
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
        } elseif ($this->calendarId === 'chinese' || $this->calendarId === 'dangi') {
            $this->setChineseCalendarFromOrdinal($calYear, $calMonth, $calDay);
            return; // setChineseCalendarFromOrdinal sets DAY_OF_MONTH itself
        } else {
            $this->intlCal->set(\IntlCalendar::FIELD_YEAR, $calYear);
            $this->intlCal->set(\IntlCalendar::FIELD_MONTH, $calMonth - 1);
        }

        $this->intlCal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $calDay);
    }

    /**
     * Sets the IntlCalendar from calendar-specific year, monthCode, and day.
     *
     * Converts the month code directly to ICU month slot, which avoids the
     * ordinal-to-ICU mapping needed by setCalendarFields().
     */
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
        } elseif ($this->calendarId === 'chinese') {
            $this->intlCal->set(self::FIELD_EXTENDED_YEAR, $calYear + self::CHINESE_YEAR_OFFSET);
        } elseif ($this->calendarId === 'dangi') {
            $this->intlCal->set(self::FIELD_EXTENDED_YEAR, $calYear + self::DANGI_YEAR_OFFSET);
        } else {
            $this->intlCal->set(\IntlCalendar::FIELD_YEAR, $calYear);
        }

        if ($this->calendarId === 'chinese' || $this->calendarId === 'dangi') {
            // Chinese/Dangi: MxxL → ICU month xx-1 with IS_LEAP_MONTH=1
            $isLeapCode = str_ends_with($monthCode, 'L');
            $baseCode = $isLeapCode ? substr($monthCode, offset: 0, length: -1) : $monthCode;
            $m = null;
            if (preg_match('/^M(\d{2})$/', $baseCode, $m) !== 1) {
                throw new RangeError("Invalid monthCode \"{$monthCode}\" for calendar \"{$this->calendarId}\".");
            }
            $baseNum = (int) $m[1];
            if ($baseNum < 1 || $baseNum > 12) {
                throw new RangeError(
                    "monthCode \"{$monthCode}\" is out of range for calendar \"{$this->calendarId}\".",
                );
            }

            // For years with known ICU leap month bugs, use ordinal-based
            // resolution which applies corrections via findChineseLeapMonthInYear.
            if ($this->calendarId === 'chinese' && array_key_exists($calYear, self::CHINESE_LEAP_MONTH_CORRECTIONS)) {
                $ordinal = $this->chineseMonthCodeToMonth($monthCode, $calYear);
                $this->setChineseCalendarFromOrdinal($calYear, $ordinal, $calDay);
                return;
            }

            $this->intlCal->set(\IntlCalendar::FIELD_MONTH, $baseNum - 1);
            $this->intlCal->set(self::FIELD_IS_LEAP_MONTH, $isLeapCode ? 1 : 0);
        } else {
            // Standard calendars: M01-M12 → ICU 0-11; M13 → ICU 12 (coptic/ethiopic/ethioaa)
            $maxMonth = $this->isCopticLike ? 13 : 12;
            $m = null;
            if (preg_match('/^M(\d{2})$/', $monthCode, $m) !== 1) {
                throw new RangeError("Invalid monthCode \"{$monthCode}\" for calendar \"{$this->calendarId}\".");
            }
            $num = (int) $m[1];
            if ($num < 1 || $num > $maxMonth) {
                throw new RangeError(
                    "monthCode \"{$monthCode}\" is out of range for calendar \"{$this->calendarId}\".",
                );
            }
            $this->intlCal->set(\IntlCalendar::FIELD_MONTH, $num - 1);
        }

        $this->intlCal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $calDay);
    }

    /**
     * Reads back epoch ms from IntlCalendar, converts to ISO, and applies overflow handling.
     *
     * @return array{0: int, 1: int<1, 12>, 2: int<1, 31>} [isoYear, isoMonth, isoDay]
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

    /**
     * Sets Chinese/Dangi calendar fields from TC39 ordinal month.
     *
     * Finds which ICU month + leap combination matches the given ordinal for the year.
     */
    private function setChineseCalendarFromOrdinal(int $calYear, int $calMonth, int $calDay): void
    {
        $this->lastSetJdn = null;
        // Find the leap month in this year (if any).
        $leapIcuMonth = $this->findChineseLeapMonthInYear($calYear);

        // Determine the target ICU month and IS_LEAP_MONTH flag.
        if ($leapIcuMonth < 0) {
            // No leap month: ordinal n → ICU month n-1, not leap.
            $icuMonth = $calMonth - 1;
            $isLeap = 0;
        } else {
            // The leap month follows ICU month $leapIcuMonth.
            // Regular months before and including the leap ICU month: ordinal = ICU month + 1.
            // The leap month itself: ordinal = leapIcuMonth + 2.
            // Regular months after the leap ICU month: ordinal = ICU month + 2.
            $leapOrdinal = $leapIcuMonth + 2; // ordinal of the leap month
            if ($calMonth <= ($leapIcuMonth + 1)) {
                // Before or at the regular month that has a leap copy.
                $icuMonth = $calMonth - 1;
                $isLeap = 0;
            } elseif ($calMonth === $leapOrdinal) {
                // This IS the leap month.
                $icuMonth = $leapIcuMonth;
                $isLeap = 1;
            } else {
                // After the leap month.
                $icuMonth = $calMonth - 2;
                $isLeap = 0;
            }
        }

        $icuYear = $calYear + ($this->calendarId === 'chinese' ? self::CHINESE_YEAR_OFFSET : self::DANGI_YEAR_OFFSET);

        $this->intlCal->clear();
        $this->intlCal->set(self::FIELD_EXTENDED_YEAR, $icuYear);
        $this->intlCal->set(\IntlCalendar::FIELD_MONTH, $icuMonth);
        $this->intlCal->set(self::FIELD_IS_LEAP_MONTH, $isLeap);
        $this->intlCal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $calDay);
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
        if ($this->needsForcedFieldResolution) {
            // Without this, getActualMaximum() may return stale values for
            // Chinese/Dangi leap months.
            $_ = $this->intlCal->get(\IntlCalendar::FIELD_DAY_OF_MONTH);
        }
        $this->lastSetJdn = $jdn;
        $this->lastSetIsoYear = $isoYear;
        $this->lastSetIsoMonth = $isoMonth;
        $this->lastSetIsoDay = $isoDay;
    }

    // -------------------------------------------------------------------------
    // Chinese/Dangi calendar helpers
    // -------------------------------------------------------------------------

    /**
     * Whether the current Chinese/Dangi year has a leap month.
     * Must call setIsoDate() first.
     */
    private function hasChineseLeapMonth(): bool
    {
        $calYear =
            $this->intlCal->get(self::FIELD_EXTENDED_YEAR)
            - ($this->calendarId === 'chinese' ? self::CHINESE_YEAR_OFFSET : self::DANGI_YEAR_OFFSET);

        return $this->findChineseLeapMonthInYear($calYear) >= 0;
    }

    /**
     * Returns corrected (icuMonth, isLeap) for the current IntlCalendar state,
     * applying known ICU 76.1 corrections for Chinese calendar leap month bugs.
     *
     * For year 1987, ICU places the leap month after month 7 (ICU 6) but it
     * should be after month 6 (ICU 5). This means ICU's "regular M07" is
     * actually "leap M06", and ICU's "leap M07" is actually "regular M07".
     *
     * @return array{0: int, 1: int} [icuMonth, isLeap]
     */
    private function correctedChineseMonthFields(): array
    {
        $icuMonth = $this->intlCal->get(\IntlCalendar::FIELD_MONTH);
        $isLeap = $this->intlCal->get(self::FIELD_IS_LEAP_MONTH);

        if ($this->calendarId !== 'chinese') {
            return [$icuMonth, $isLeap];
        }

        $calYear = $this->intlCal->get(self::FIELD_EXTENDED_YEAR) - self::CHINESE_YEAR_OFFSET;
        $correctLeap = self::CHINESE_LEAP_MONTH_CORRECTIONS[$calYear] ?? null;
        if ($correctLeap === null) {
            return [$icuMonth, $isLeap];
        }

        // ICU thinks leap is after $icuBuggyLeap, but it's really after $correctLeap.
        // For months at the boundary, remap the fields.
        // ICU (buggy month 6, isLeap=0) -> should be (month 5, isLeap=1)
        // ICU (buggy month 6, isLeap=1) -> should be (month 6, isLeap=0)
        $icuBuggyLeap = $correctLeap + 1;
        if ($icuMonth === $icuBuggyLeap && $isLeap === 0) {
            return [$correctLeap, 1];
        }
        if ($icuMonth === $icuBuggyLeap && $isLeap === 1) {
            return [$icuBuggyLeap, 0];
        }

        return [$icuMonth, $isLeap];
    }

    /**
     * Computes the TC39 ordinal month for Chinese/Dangi calendar.
     * Must call setIsoDate() first.
     *
     * Chinese/Dangi months are 0-indexed in ICU. A leap month has the same
     * MONTH value as the preceding month but IS_LEAP_MONTH=1. The TC39 ordinal
     * counts months sequentially (including the leap month).
     */
    private function chineseMonthOrdinal(): int
    {
        [$icuMonth, $isLeap] = $this->correctedChineseMonthFields();

        // Base ordinal (no leap month consideration).
        $ordinal = $icuMonth + 1;

        if ($isLeap !== 0) {
            // This IS a leap month — it follows the regular month with the same index.
            return $ordinal + 1;
        }

        // Check if a leap month occurred before the current month in this year.
        $calYear =
            $this->intlCal->get(self::FIELD_EXTENDED_YEAR)
            - ($this->calendarId === 'chinese' ? self::CHINESE_YEAR_OFFSET : self::DANGI_YEAR_OFFSET);
        $leapIcuMonth = $this->findChineseLeapMonthInYear($calYear);
        if ($leapIcuMonth >= 0 && $icuMonth > $leapIcuMonth) {
            return $ordinal + 1;
        }

        return $ordinal;
    }

    /**
     * Computes the TC39 month code for Chinese/Dangi calendar.
     * Must call setIsoDate() first.
     *
     * Regular months: M01-M12. Leap months: MxxL (where xx = the regular month number).
     */
    private function chineseMonthCode(): string
    {
        [$icuMonth, $isLeap] = $this->correctedChineseMonthFields();

        $code = sprintf('M%02d', $icuMonth + 1);

        return $isLeap !== 0 ? sprintf('%sL', $code) : $code;
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
