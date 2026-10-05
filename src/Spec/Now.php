<?php

declare(strict_types=1);

namespace Calendrics\Spec;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\TimeZoneHelper;

/**
 * The Temporal.Now namespace object.
 *
 * Provides access to the current date and time.
 *
 * @see https://tc39.es/proposal-temporal/#sec-temporal-now-object
 * @psalm-api
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
     * PHP lacks nanosecond precision; this implementation uses microsecond
     * precision (via microtime) and fills the sub-microsecond bits with zero.
     *
     * @psalm-api
     */
    public static function instant(): Instant
    {
        // microtime(true) returns float seconds since Unix epoch with microsecond precision.
        // Multiply by 1_000_000 to get microseconds, then cast to int, then × 1000 for nanoseconds.
        $us = (int) (microtime(as_float: true) * 1_000_000.0);
        return new Instant($us * 1_000);
    }

    /**
     * Returns the current local time zone identifier string.
     *
     * @psalm-api
     */
    public static function timeZoneId(): string
    {
        return date_default_timezone_get();
    }

    /**
     * Returns today's date in the ISO 8601 calendar.
     *
     * If a time zone identifier string is provided, the date is computed
     * relative to that time zone; otherwise the system default is used.
     *
     * @throws TypeError  if $timeZone is not null and not a string.
     * @throws RangeError if the string is not a valid time zone identifier.
     * @psalm-api
     */
    public static function plainDateISO(?string $timeZone = null): PlainDate
    {
        $tzId = self::resolveTimeZone($timeZone, func_num_args() > 0);
        /** @psalm-suppress ArgumentTypeCoercion */
        $tz = new \DateTimeZone($tzId);
        $dt = new \DateTimeImmutable('now', $tz);
        return new PlainDate((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'));
    }

    /**
     * Returns the current time (no date) in the ISO 8601 calendar.
     *
     * @throws TypeError  if $timeZone is not null and not a string.
     * @throws RangeError if the string is not a valid time zone identifier.
     * @psalm-api
     */
    public static function plainTimeISO(?string $timeZone = null): PlainTime
    {
        $tzId = self::resolveTimeZone($timeZone, func_num_args() > 0);
        /** @psalm-suppress ArgumentTypeCoercion */
        $tz = new \DateTimeZone($tzId);
        $dt = new \DateTimeImmutable('now', $tz);
        return new PlainTime((int) $dt->format('G'), (int) $dt->format('i'), (int) $dt->format('s'));
    }

    /**
     * Returns the current date and time in the ISO 8601 calendar.
     *
     * If a time zone identifier string is provided, the date/time is computed
     * relative to that time zone; otherwise the system default is used.
     *
     * @throws TypeError  if $timeZone is not null and not a string.
     * @throws RangeError if the string is not a valid time zone identifier.
     * @psalm-api
     */
    public static function plainDateTimeISO(?string $timeZone = null): PlainDateTime
    {
        $tzId = self::resolveTimeZone($timeZone, func_num_args() > 0);
        /** @psalm-suppress ArgumentTypeCoercion */
        $tz = new \DateTimeZone($tzId);
        $dt = new \DateTimeImmutable('now', $tz);
        return new PlainDateTime(
            (int) $dt->format('Y'),
            (int) $dt->format('n'),
            (int) $dt->format('j'),
            (int) $dt->format('G'),
            (int) $dt->format('i'),
            (int) $dt->format('s'),
        );
    }

    /**
     * Returns the current date and time as a ZonedDateTime in the ISO 8601 calendar.
     *
     * If a time zone identifier string is provided, it is used; otherwise the
     * system default is used.
     *
     * @throws TypeError  if $timeZone is not null and not a string.
     * @throws RangeError if the string is not a valid time zone identifier.
     * @psalm-api
     */
    public static function zonedDateTimeISO(?string $timeZone = null): ZonedDateTime
    {
        $tzId = self::resolveTimeZone($timeZone, func_num_args() > 0);
        // Use microsecond-precision epoch nanoseconds (same as instant()).
        $us = (int) (microtime(as_float: true) * 1_000_000.0);
        $epochNs = $us * 1_000;
        return new ZonedDateTime($epochNs, $tzId);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate and resolve a time zone argument to a PHP DateTimeZone-compatible string.
     *
     * null + not provided → system default timezone string
     * null + explicitly provided → TypeError (JS undefined = omitted, null = bad type)
     * non-string → TypeError
     * datetime string → extract IANA annotation or UTC offset; reject bare datetimes
     * standalone offset/IANA → validate and return
     *
     * @throws TypeError  for non-string arguments (including explicitly-passed null).
     * @throws RangeError for empty strings or otherwise invalid strings.
     */
    private static function resolveTimeZone(?string $timeZone, bool $provided = false): string
    {
        if ($timeZone === null) {
            if ($provided) {
                throw new TypeError('Temporal.Now: timeZone must be a string; got null.');
            }
            return date_default_timezone_get();
        }
        return TimeZoneHelper::normalizeTimezoneId($timeZone);
    }
}
