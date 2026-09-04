<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\TypeError;

/**
 * The `toLocaleString()` implementation shared by the zoneless `Plain*` types.
 *
 * All five render a wall-clock value that carries no zone, so they format in UTC and
 * differ only in which components they default to, which style options they reject,
 * which calendar they must agree with, and how their fields collapse into an epoch
 * instant — the five hooks below.
 *
 * {@see \Calendrics\Spec\ZonedDateTime} and {@see \Calendrics\Spec\Instant} are
 * deliberately not users: both format an exact instant in a real time zone through
 * {@see IntlFormatter::formatEpoch()}, and each carries its own `toLocaleString()`.
 *
 * A new user must also implement {@see PlainLocaleFormattable}: collaborators select
 * this formatting path by that interface, and nothing else enforces the pairing.
 *
 * @internal
 */
trait HasPlainLocaleString
{
    abstract public function toString(): string;

    /**
     * Returns the default component mode for IntlDateFormatter when formatting
     * this type via toLocaleString: one of 'date', 'time', 'datetime', 'yearmonth', 'monthday'.
     */
    abstract protected function localeDefaultComponents(): string;

    /**
     * Returns true if this type represents a date without a time-of-day component,
     * in which case the toLocaleString timeStyle option must be rejected.
     */
    abstract protected function localeIsDateOnly(): bool;

    /**
     * Returns true if this type represents a time-of-day without a date component,
     * in which case the toLocaleString dateStyle option must be rejected.
     */
    abstract protected function localeIsTimeOnly(): bool;

    /**
     * Returns this value's calendar identifier for the toLocaleString()
     * calendar-compatibility check, or null for types that carry no calendar
     * (PlainTime), which are formattable by any formatter.
     */
    abstract protected function localeCalendarId(): ?string;

    /**
     * Converts this temporal value to the epoch seconds and sub-second nanoseconds
     * {@see IntlFormatter::formatEpoch()} formats from, reading the wall-clock fields
     * as UTC.
     *
     * The two parts stay separate because a single number cannot carry both across the
     * whole ±271821-year range: at the extremes a double's ulp is wider than the
     * sub-second remainder, which would round 275760-09-13T23:59:59.999999999 up into
     * the following day.
     *
     * @return array{int, int} Epoch seconds, then nanoseconds within that second.
     */
    abstract protected function toLocaleEpochParts(): array;

    /**
     * Returns a locale-sensitive string representation using IntlDateFormatter.
     *
     * For date-only types (PlainDate, PlainYearMonth, PlainMonthDay), timeStyle is forbidden.
     * For time-only types (PlainTime), dateStyle is forbidden.
     * Style options (dateStyle/timeStyle) cannot be combined with individual component options.
     * The value's calendar must be compatible with the formatter's — see
     * {@see IntlFormatter::validateCalendar()}.
     *
     * @param string|array<array-key, mixed>|null $locales
     * @param array<array-key, mixed>|object|null $options
     * @psalm-api
     * @throws TypeError if a style option is not applicable to this type.
     * @throws \Calendrics\Exception\RangeError if this value's calendar is incompatible with the formatter's.
     */
    public function toLocaleString(string|array|null $locales = null, array|object|null $options = null): string
    {
        if ($options === null) {
            $opts = [];
        } else {
            $opts = Options::bagSnapshot($options, IntlFormatter::OPTION_NAMES);
        }
        /** @psalm-var array<string, mixed> $opts */
        $hasTimeStyle = array_key_exists('timeStyle', $opts) && $opts['timeStyle'] !== null;
        $hasDateStyle = array_key_exists('dateStyle', $opts) && $opts['dateStyle'] !== null;

        $isDateOnly = $this->localeIsDateOnly();
        $isTimeOnly = $this->localeIsTimeOnly();

        if ($hasTimeStyle && $isDateOnly) {
            throw new TypeError('toLocaleString(): timeStyle option is not allowed for this type.');
        }
        if ($hasDateStyle && $isTimeOnly) {
            throw new TypeError('toLocaleString(): dateStyle option is not allowed for this type.');
        }

        $locale = IntlFormatter::resolveLocale($locales);

        // Plain types always format in UTC to prevent date/time shifting.
        // The timeZone option is accepted but ignored for display purposes.
        $timeZone = 'UTC';

        $defaultComponents = $this->localeDefaultComponents();

        IntlFormatter::validateCalendar($this->localeCalendarId(), $locale, $opts, $defaultComponents);

        $formatter = IntlFormatter::buildIntlFormatter($locale, $timeZone, $opts, $defaultComponents);

        [$epochSec, $subNs] = $this->toLocaleEpochParts();
        $result = IntlFormatter::formatEpoch($formatter, $epochSec, $subNs, $timeZone, $locale);

        return $result !== false ? $result : $this->toString();
    }
}
