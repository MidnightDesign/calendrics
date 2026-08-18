<?php

declare(strict_types=1);

namespace Calendrics\Trait;

use Calendrics\Calendar;

/**
 * Virtual (get-only) calendar year-month properties that delegate to a spec
 * instance accessible via `$this->spec`.
 *
 * Used by outer-layer wrapper classes (PlainYearMonth, PlainDate,
 * PlainDateTime, ZonedDateTime) to share property-hook declarations that
 * would otherwise be copy-pasted. Each consumer must declare a `$spec`
 * property whose type exposes the matching calendar-identity fields
 * (year, month, calendarId, era, eraYear, monthCode, daysInMonth,
 * daysInYear, monthsInYear, inLeapYear).
 *
 * @internal
 * @phpstan-require-implements HasYearMonthSpec
 * @psalm-require-implements HasYearMonthSpec
 */
trait HasYearMonthProperties
{
    /**
     * Calendar year (projected through the active calendar).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $year {
        get => $this->spec->year;
    }

    /**
     * Month of the year (projected through the active calendar).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $month {
        get => $this->spec->month;
    }

    /**
     * Calendar system for this value.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public Calendar $calendar {
        get => Calendar::from($this->spec->calendarId);
    }

    /**
     * Calendar era identifier (e.g. "ce", "bce", "reiwa"), or null for calendars without eras.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public ?string $era {
        get => $this->spec->era;
    }

    /**
     * Year within the calendar era, or null for calendars without eras.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public ?int $eraYear {
        get => $this->spec->eraYear;
    }

    /**
     * Month code in "M01"–"M12" format (or "M01L"–"M12L" for leap months).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public string $monthCode {
        get => $this->spec->monthCode;
    }

    /**
     * Number of days in this value's month.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $daysInMonth {
        get => $this->spec->daysInMonth;
    }

    /**
     * Number of days in this value's year.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $daysInYear {
        get => $this->spec->daysInYear;
    }

    /**
     * Number of months in this value's year.
     *
     * @psalm-api
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $monthsInYear {
        get => $this->spec->monthsInYear;
    }

    /**
     * True if this value's year is a leap year.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public bool $inLeapYear {
        get => $this->spec->inLeapYear;
    }
}
