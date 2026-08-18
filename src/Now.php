<?php

declare(strict_types=1);

namespace Calendrics;

use Calendrics\Spec\Now as SpecNow;

/**
 * Provides access to the current date and time as porcelain Temporal types.
 *
 * This is the user-facing counterpart of {@see SpecNow}. Each method delegates
 * to the spec layer and wraps the result in the corresponding porcelain type.
 */
final class Now
{
    /** Not instantiable.
     * @psalm-suppress UnusedConstructor
     */
    private function __construct() {}

    /**
     * Returns the current time as a Temporal.Instant.
     *
     * Uses microsecond precision (sub-microsecond bits are zero).
     */
    public static function instant(): Instant
    {
        return Instant::fromSpec(SpecNow::instant());
    }

    /**
     * Returns the system default time zone identifier string.
     */
    public static function timeZoneId(): string
    {
        return SpecNow::timeZoneId();
    }

    /**
     * Returns today's date projected through the given calendar.
     *
     * @param string|null $timeZone IANA time zone or UTC offset; null uses the system default.
     * @param Calendar    $calendar Calendar system (default ISO 8601).
     * @throws \Calendrics\Exception\RangeError if the string is not a valid time zone identifier.
     */
    public static function plainDate(?string $timeZone = null, Calendar $calendar = Calendar::Iso8601): PlainDate
    {
        $spec = $timeZone !== null ? SpecNow::plainDateISO($timeZone) : SpecNow::plainDateISO();

        if ($calendar !== Calendar::Iso8601) {
            $spec = $spec->withCalendar($calendar->value);
        }

        return PlainDate::fromSpec($spec);
    }

    /**
     * Returns the current wall-clock time (no date) in the ISO 8601 calendar.
     *
     * @param string|null $timeZone IANA time zone or UTC offset; null uses the system default.
     * @throws \Calendrics\Exception\RangeError if the string is not a valid time zone identifier.
     */
    public static function plainTime(?string $timeZone = null): PlainTime
    {
        $spec = $timeZone !== null ? SpecNow::plainTimeISO($timeZone) : SpecNow::plainTimeISO();

        return PlainTime::fromSpec($spec);
    }

    /**
     * Returns the current date and time projected through the given calendar.
     *
     * @param string|null $timeZone IANA time zone or UTC offset; null uses the system default.
     * @param Calendar    $calendar Calendar system (default ISO 8601).
     * @throws \Calendrics\Exception\RangeError if the string is not a valid time zone identifier.
     */
    public static function plainDateTime(
        ?string $timeZone = null,
        Calendar $calendar = Calendar::Iso8601,
    ): PlainDateTime {
        $spec = $timeZone !== null ? SpecNow::plainDateTimeISO($timeZone) : SpecNow::plainDateTimeISO();

        if ($calendar !== Calendar::Iso8601) {
            $spec = $spec->withCalendar($calendar->value);
        }

        return PlainDateTime::fromSpec($spec);
    }

    /**
     * Returns the current date and time as a ZonedDateTime projected through the given calendar.
     *
     * @param string|null $timeZone IANA time zone or UTC offset; null uses the system default.
     * @param Calendar    $calendar Calendar system (default ISO 8601).
     * @throws \Calendrics\Exception\RangeError if the string is not a valid time zone identifier.
     */
    public static function zonedDateTime(
        ?string $timeZone = null,
        Calendar $calendar = Calendar::Iso8601,
    ): ZonedDateTime {
        $spec = $timeZone !== null ? SpecNow::zonedDateTimeISO($timeZone) : SpecNow::zonedDateTimeISO();

        if ($calendar !== Calendar::Iso8601) {
            $spec = $spec->withCalendar($calendar->value);
        }

        return ZonedDateTime::fromSpec($spec);
    }
}
