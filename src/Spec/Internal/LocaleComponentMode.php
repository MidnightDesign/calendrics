<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

/**
 * Which components `toLocaleString()` renders when the caller names none.
 *
 * `PlainDate`, `PlainTime`, `PlainYearMonth` and `PlainMonthDay` each have one fixed
 * mode of the same name; `PlainDateTime`, `Instant` and `ZonedDateTime` render both
 * halves. ECMA-402 derives a formatter's defaults from the *required* half of the value
 * being formatted, so the mode also settles which style option is applicable: a
 * date-only value rejects `timeStyle`, a time-only value rejects `dateStyle`.
 *
 * @internal
 */
enum LocaleComponentMode
{
    case Date;
    case Time;
    case DateTime;
    case YearMonth;
    case MonthDay;

    /**
     * The date fields this mode renders when the caller names no component of its own.
     *
     * @return list<string> ICU skeleton symbols
     */
    public function defaultDateFields(): array
    {
        return match ($this) {
            self::Date, self::DateTime => ['y', 'M', 'd'],
            self::YearMonth => ['y', 'M'],
            self::MonthDay => ['M', 'd'],
            self::Time => [],
        };
    }

    /**
     * The time fields this mode renders when the caller names no component of its own.
     *
     * @return list<string> ICU skeleton symbols
     */
    public function defaultTimeFields(): array
    {
        return match ($this) {
            self::Time, self::DateTime => ['j', 'm', 's'],
            self::Date, self::YearMonth, self::MonthDay => [],
        };
    }

    /** The whole skeleton this mode renders for a caller who named nothing at all. */
    public function defaultSkeleton(): string
    {
        return implode('', [...$this->defaultDateFields(), ...$this->defaultTimeFields()]);
    }

    /**
     * The date field a whole date carries but this mode does not, or null for a mode
     * that carries either a whole date or no date at all.
     *
     * @return 'year'|'day'|null
     */
    public function absentDateField(): ?string
    {
        return match ($this) {
            self::YearMonth => 'day',
            self::MonthDay => 'year',
            self::Date, self::Time, self::DateTime => null,
        };
    }

    /** Whether the mode carries only part of a date, as `PlainYearMonth` and `PlainMonthDay` do. */
    public function isPartialDate(): bool
    {
        return $this->absentDateField() !== null;
    }

    /** Whether the mode carries no time of day, and so cannot honor a `timeStyle`. */
    public function isDateOnly(): bool
    {
        return $this->defaultTimeFields() === [];
    }

    /** Whether the mode carries no date, and so cannot honor a `dateStyle`. */
    public function isTimeOnly(): bool
    {
        return $this->defaultDateFields() === [];
    }
}
