<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal\Calendar;

/**
 * Defines all calendar-dependent operations required by Temporal types.
 *
 * Each Temporal type stores dates internally as ISO 8601 fields (isoYear, isoMonth, isoDay).
 * A CalendarProtocol implementation acts as a "lens" that projects those ISO fields into
 * calendar-specific values (year, month, day, era, monthCode, etc.) and resolves
 * calendar-specific input back into ISO fields.
 *
 * @internal
 */
interface CalendarProtocol
{
    // -------------------------------------------------------------------------
    // ISO -> Calendar field projection
    // -------------------------------------------------------------------------

    public function year(int $isoYear, int $isoMonth, int $isoDay): int;

    public function month(int $isoYear, int $isoMonth, int $isoDay): int;

    public function day(int $isoYear, int $isoMonth, int $isoDay): int;

    /** @psalm-api */
    public function era(int $isoYear, int $isoMonth, int $isoDay): ?string;

    /** @psalm-api */
    public function eraYear(int $isoYear, int $isoMonth, int $isoDay): ?int;

    public function monthCode(int $isoYear, int $isoMonth, int $isoDay): string;

    /** @psalm-api */
    public function dayOfYear(int $isoYear, int $isoMonth, int $isoDay): int;

    /** @psalm-api */
    public function daysInMonth(int $isoYear, int $isoMonth, int $isoDay): int;

    /** @psalm-api */
    public function daysInYear(int $isoYear, int $isoMonth, int $isoDay): int;

    /** @psalm-api */
    public function monthsInYear(int $isoYear, int $isoMonth, int $isoDay): int;

    /** @psalm-api */
    public function inLeapYear(int $isoYear, int $isoMonth, int $isoDay): bool;

    // -------------------------------------------------------------------------
    // Calendar -> ISO field resolution
    // -------------------------------------------------------------------------

    /**
     * Resolves calendar-specific year/month/day to ISO fields.
     *
     * @return array{0: int, 1: int, 2: int} [isoYear, isoMonth, isoDay]
     */
    public function calendarToIso(int $calYear, int $calMonth, int $calDay, string $overflow): array;

    /**
     * Resolves calendar-specific year/monthCode/day to ISO fields.
     *
     * @return array{0: int, 1: int, 2: int} [isoYear, isoMonth, isoDay]
     */
    public function calendarToIsoFromMonthCode(int $calYear, string $monthCode, int $calDay, string $overflow): array;

    // -------------------------------------------------------------------------
    // Calendar-aware arithmetic
    // -------------------------------------------------------------------------

    /**
     * Adds years, months, weeks, and days to an ISO date using calendar-specific rules.
     *
     * @return array{0: int, 1: int, 2: int} [isoYear, isoMonth, isoDay]
     */
    public function dateAdd(
        int $isoYear,
        int $isoMonth,
        int $isoDay,
        int $years,
        int $months,
        int $weeks,
        int $days,
        string $overflow,
    ): array;

    /**
     * Computes the difference between two ISO dates in calendar-specific units.
     *
     * When $receiverIsLater is true, the day remainder is computed by anchoring
     * backward from isoDate2 (the later date) rather than forward from isoDate1.
     * This matches TC39's asymmetric behavior for since() vs until().
     *
     * @return array{0: int, 1: int, 2: int, 3: int} [years, months, weeks, days]
     */
    public function dateUntil(
        int $isoY1,
        int $isoM1,
        int $isoD1,
        int $isoY2,
        int $isoM2,
        int $isoD2,
        string $largestUnit,
        bool $receiverIsLater = false,
    ): array;

    // -------------------------------------------------------------------------
    // Month code utilities
    // -------------------------------------------------------------------------

    /**
     * Converts a month code (e.g. "M01", "M05L") to an ordinal month number for the given year.
     *
     * Applies ConstrainMonthCode semantics: when the month code does not exist in the given
     * year, 'reject' throws while 'constrain' maps it to the best common month (e.g. Hebrew
     * "M05L" → "M06" in a non-leap year) before taking the ordinal.
     *
     * @param string $overflow 'constrain' or 'reject'
     */
    public function monthCodeToMonth(string $monthCode, int $calYear, string $overflow = 'reject'): int;

    // -------------------------------------------------------------------------
    // Era resolution
    // -------------------------------------------------------------------------

    /**
     * Resolves era + eraYear to the calendar's year value.
     *
     * For calendars without eras (Chinese/Dangi), returns null to signal that
     * era should be ignored. Throws if the era is invalid for this calendar.
     *
     * @return int|null The resolved year, or null if era is not applicable.
     * @throws \Calendrics\Exception\RangeError if the era is not valid for this calendar.
     */
    public function resolveEra(string $era, int $eraYear): ?int;
}
