<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;

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
     * Converts this temporal value to a Unix timestamp (seconds) suitable for
     * IntlDateFormatter::format().
     *
     * Types with sub-second fields (PlainTime, PlainDateTime) return a float whose
     * fractional part carries them, so fractionalSecondDigits formatting works;
     * date-only types return whole seconds.
     */
    abstract protected function toLocaleTimestamp(): int|float;

    /**
     * Returns this type's calendar identifier for toLocaleString's calendar-mismatch
     * check. Types that carry a calendar (PlainDate, PlainDateTime, PlainYearMonth,
     * PlainMonthDay) override this to return their `calendarId`; the default covers
     * types without a calendar slot (PlainTime), which behave like ISO values.
     */
    protected function localeCalendarId(): string
    {
        return 'iso8601';
    }

    /**
     * Whether toLocaleString's calendar-mismatch check exempts the ISO calendar.
     *
     * Full dates format in any locale when their calendar is iso8601 (the ISO
     * fields are calendar-neutral), but PlainYearMonth and PlainMonthDay override
     * this to false: their display is inherently calendar-dependent, so ECMA-402
     * requires their calendar to equal the format calendar exactly.
     */
    protected function localeCalendarIsoExempt(): bool
    {
        return true;
    }

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
     *
     * @param string|array<array-key, mixed>|null $locales
     * @param array<array-key, mixed>|object|null $options
     * @psalm-api
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
            throw new \TypeError('toLocaleString(): timeStyle option is not allowed for this type.');
        }
        // PlainTime: dateStyle is forbidden.
        if ($hasDateStyle && $isTimeOnly) {
            throw new \TypeError('toLocaleString(): dateStyle option is not allowed for this type.');
        }

        $locale = IntlFormatter::resolveLocale($locales);

        // ECMA-402 HandleDateTimeValue: the value's calendar must match the calendar
        // the DateTimeFormat resolved from the locale and options; otherwise
        // formatting throws RangeError. Full dates additionally format in any locale
        // when their calendar is iso8601 (see localeCalendarIsoExempt()).
        $calendarId = $this->localeCalendarId();
        if (!($calendarId === 'iso8601' && $this->localeCalendarIsoExempt())) {
            $formatCalendar = IntlFormatter::resolvedCalendar($locale, $opts);
            if ($calendarId !== $formatCalendar) {
                throw new RangeError(sprintf(
                    'toLocaleString(): calendar %s of this object does not match the calendar %s to format in.',
                    $calendarId,
                    $formatCalendar,
                ));
            }
        }

        // Plain types always format in UTC to prevent date/time shifting.
        // The timeZone option is accepted but ignored for display purposes.
        $timeZone = 'UTC';

        $defaultComponents = $this->localeDefaultComponents();

        // Pass locale into opts for pattern generator
        $opts['_locale'] = $locale;

        $formatter = IntlFormatter::buildIntlFormatter($locale, $timeZone, $opts, $defaultComponents);

        // Build a timestamp from the type's fields
        $timestamp = $this->toLocaleTimestamp();
        $result = $formatter->format($timestamp);

        return $result !== false ? $result : $this->toString();
    }
}
