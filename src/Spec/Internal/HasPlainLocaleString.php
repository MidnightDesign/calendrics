<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\TypeError;

/**
 * The `toLocaleString()` implementation shared by the zoneless `Plain*` types.
 *
 * All five render a wall-clock value that carries no zone, so they format in UTC and
 * share one {@see PlainLocaleFormat} projection for their component mode, supported
 * styles, calendar, and exact epoch representation.
 *
 * {@see \Calendrics\Spec\ZonedDateTime} and {@see \Calendrics\Spec\Instant} are
 * deliberately not users: both format an exact instant in a real time zone through
 * {@see IntlFormatter::formatEpoch()}, and each carries its own `toLocaleString()`.
 *
 * @internal
 */
trait HasPlainLocaleString
{
    abstract public function toString(): string;

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
        if (!$this instanceof PlainLocaleFormattable) {
            throw new \LogicException('HasPlainLocaleString requires PlainLocaleFormattable.');
        }
        if ($options === null) {
            $opts = [];
        } else {
            $opts = Options::bagSnapshot($options, IntlFormatter::OPTION_NAMES);
        }
        /** @psalm-var array<string, mixed> $opts */
        $hasTimeStyle = array_key_exists('timeStyle', $opts) && $opts['timeStyle'] !== null;
        $hasDateStyle = array_key_exists('dateStyle', $opts) && $opts['dateStyle'] !== null;
        $format = PlainLocaleFormat::from($this);

        if ($hasTimeStyle && $format->isDateOnly()) {
            throw new TypeError('toLocaleString(): timeStyle option is not allowed for this type.');
        }
        if ($hasDateStyle && $format->isTimeOnly()) {
            throw new TypeError('toLocaleString(): dateStyle option is not allowed for this type.');
        }

        $locale = IntlFormatter::resolveLocale($locales);
        $timeZone = 'UTC';

        IntlFormatter::validateCalendar($format->calendarId, $locale, $opts, $format->kind);
        $formatter = IntlFormatter::buildIntlFormatter($locale, $timeZone, $opts, $format->kind);
        $result = IntlFormatter::formatEpoch($formatter, $format->epochSec, $format->subNs, $timeZone, $locale);

        return $result !== false ? $result : $this->toString();
    }
}
