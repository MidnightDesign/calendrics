<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\TypeError;

/** @internal */
trait TemporalSerde
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
     * Converts this temporal value to a Unix timestamp (seconds) suitable for
     * IntlDateFormatter::format().
     *
     * Types with sub-second fields (PlainTime, PlainDateTime) return a float whose
     * fractional part carries them, so fractionalSecondDigits formatting works;
     * date-only types return whole seconds.
     */
    abstract protected function toLocaleTimestamp(): int|float;

    #[\Override]
    public function __toString(): string
    {
        return $this->toString();
    }

    /** @psalm-api */
    public function toJSON(): string
    {
        return $this->toString();
    }

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
     * @throws \Temporal\Exception\RangeError if this value's calendar is incompatible with the formatter's.
     */
    public function toLocaleString(string|array|null $locales = null, array|object|null $options = null): string
    {
        if ($options === null) {
            $opts = [];
        } else {
            $opts = is_array($options) ? $options : get_object_vars($options);
        }
        /** @psalm-var array<string, mixed> $opts */
        $hasTimeStyle = array_key_exists('timeStyle', $opts) && $opts['timeStyle'] !== null;
        $hasDateStyle = array_key_exists('dateStyle', $opts) && $opts['dateStyle'] !== null;

        $isDateOnly = $this->localeIsDateOnly();
        $isTimeOnly = $this->localeIsTimeOnly();

        // PlainDate, PlainYearMonth, PlainMonthDay: timeStyle is forbidden.
        if ($hasTimeStyle && $isDateOnly) {
            throw new TypeError('toLocaleString(): timeStyle option is not allowed for this type.');
        }
        // PlainTime: dateStyle is forbidden.
        if ($hasDateStyle && $isTimeOnly) {
            throw new TypeError('toLocaleString(): dateStyle option is not allowed for this type.');
        }

        $locale = IntlFormatter::resolveLocale($locales);

        // Plain types always format in UTC to prevent date/time shifting.
        // The timeZone option is accepted but ignored for display purposes.
        $timeZone = 'UTC';

        $defaultComponents = $this->localeDefaultComponents();

        IntlFormatter::validateCalendar($this->localeCalendarId(), $locale, $opts, $defaultComponents);

        // Pass locale into opts for pattern generator
        $opts['_locale'] = $locale;

        $formatter = IntlFormatter::buildIntlFormatter($locale, $timeZone, $opts, $defaultComponents);

        // Build a timestamp from the type's fields
        $timestamp = $this->toLocaleTimestamp();
        $result = $formatter->format($timestamp);

        return $result !== false ? $result : $this->toString();
    }
}
