<?php

declare(strict_types=1);

namespace Calendrics\Trait;

/**
 * Virtual (get-only) day-of-month properties that delegate to a spec
 * instance accessible via `$this->spec`.
 *
 * Used by outer-layer wrapper classes (PlainDate, PlainDateTime,
 * ZonedDateTime) to share property-hook declarations that would otherwise
 * be copy-pasted. Composes with {@see HasYearMonthProperties}.
 *
 * @internal
 * @phpstan-require-implements HasDayOfMonthSpec
 * @psalm-require-implements HasDayOfMonthSpec
 */
trait HasDayOfMonthProperties
{
    /**
     * Day of the month (projected through the active calendar).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $day {
        get => $this->spec->day;
    }

    /**
     * ISO 8601 day of week: 1 = Monday, 7 = Sunday.
     *
     * @var int<1, 7>
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $dayOfWeek {
        get => $this->spec->dayOfWeek;
    }

    /**
     * Ordinal day of the year (1-based). Range depends on the calendar system.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $dayOfYear {
        get => $this->spec->dayOfYear;
    }

    /**
     * ISO 8601 week number: 1–53, or null for non-ISO calendars.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public ?int $weekOfYear {
        get => $this->spec->weekOfYear;
    }

    /**
     * ISO 8601 week-year (may differ from calendar year near year boundaries),
     * or null for non-ISO calendars.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public ?int $yearOfWeek {
        get => $this->spec->yearOfWeek;
    }

    /**
     * Days in a week (always 7 for ISO 8601).
     *
     * @psalm-api
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $daysInWeek {
        get => $this->spec->daysInWeek;
    }
}
