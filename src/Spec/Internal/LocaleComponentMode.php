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
enum LocaleComponentMode: string
{
    case Date = 'date';
    case Time = 'time';
    case DateTime = 'datetime';
    case YearMonth = 'yearmonth';
    case MonthDay = 'monthday';

    /** Whether the mode carries no time of day, and so cannot honor a `timeStyle`. */
    public function isDateOnly(): bool
    {
        return match ($this) {
            self::Date, self::YearMonth, self::MonthDay => true,
            self::Time, self::DateTime => false,
        };
    }

    /** Whether the mode carries no date, and so cannot honor a `dateStyle`. */
    public function isTimeOnly(): bool
    {
        return match ($this) {
            self::Time => true,
            self::Date, self::DateTime, self::YearMonth, self::MonthDay => false,
        };
    }
}
