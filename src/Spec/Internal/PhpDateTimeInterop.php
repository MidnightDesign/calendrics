<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;

/**
 * Internal helpers for converting between epoch-nanosecond integers and PHP's
 * `\DateTimeInterface`/`\DateTimeImmutable`.
 *
 * Used by `Temporal\Instant::{fromDateTime,toDateTime}` and
 * `Temporal\ZonedDateTime::{fromDateTime,toDateTime}`. The other porcelain
 * `fromDateTime()` factories (`Temporal\PlainDateTime`, `Temporal\PlainDate`,
 * `Temporal\PlainTime`) extract individual calendar fields directly from the
 * source `\DateTimeInterface` and do not go through this helper.
 *
 * Although the namespace is `Temporal\Spec\Internal\`, this helper isn't
 * strictly spec-layer machinery — it's placed here to keep all internal-only
 * helpers in one namespace. As with everything in `Temporal\Spec\Internal\`,
 * it is not part of the public BC contract: signatures, behavior, and
 * existence may change between any two releases.
 */
final class PhpDateTimeInterop
{
    /** Not instantiable.
     * @psalm-suppress UnusedConstructor
     */
    private function __construct() {}

    /**
     * Returns the epoch nanosecond count of `$dt` with sub-microsecond bits
     * zeroed.
     *
     * PHP's `\DateTimeInterface` carries microsecond precision via the `u`
     * format specifier, so the lowest three decimal digits of the result are
     * always zero. This matches the loss-of-precision contract documented on
     * the porcelain `fromDateTime()` factories.
     *
     * @throws RangeError if `$dt`'s instant lies outside the
     *         int64 nanosecond range (roughly the years 1678–2262 around
     *         the Unix epoch).
     */
    public static function epochNanoseconds(\DateTimeInterface $dt): int
    {
        $ts = $dt->getTimestamp();
        // PHP's int64 nanosecond range covers ~±292 years around the Unix
        // epoch. Beyond that, `$ts × NS_PER_SECOND` overflows to float and
        // the `int` return type would raise a TypeError before the porcelain
        // factory could surface a meaningful range error.
        // The threshold is one less than intdiv(PHP_INT_MAX, NS_PER_SECOND)
        // = 9_223_372_036 so that the full product plus the maximum
        // microsecond contribution (999_999 × 1_000 = 999_999_000) stays
        // within int64: 9_223_372_035 × 10⁹ + 999_999_000 < PHP_INT_MAX.
        $tsMax = 9_223_372_035;
        if ($ts > $tsMax || $ts < -$tsMax) {
            throw new RangeError(sprintf(
                "DateTime '%s' is outside the representable int64 nanosecond range.",
                $dt->format(\DateTimeInterface::RFC3339),
            ));
        }

        return ($ts * 1_000_000_000) + ((int) $dt->format('u') * 1_000);
    }

    /**
     * Builds a `\DateTimeImmutable` for the given epoch nanoseconds, displayed
     * in `$tz`.
     *
     * PHP's native date-time types only carry microsecond precision, so the
     * sub-microsecond bits of `$epochNanoseconds` (the lowest three decimal
     * digits) are dropped. This matches the loss-of-precision contract
     * documented on the porcelain `toDateTime()` methods.
     *
     * Because this helper lives in `Temporal\Spec\Internal\`, it is not part
     * of the public BC contract and may change between any two releases.
     */
    public static function toDateTime(int $epochNanoseconds, \DateTimeZone $tz): \DateTimeImmutable
    {
        $us = intdiv(num1: $epochNanoseconds, num2: 1_000);
        $secs = intdiv(num1: $us, num2: 1_000_000);
        $usOfSec = $us % 1_000_000;
        if ($usOfSec < 0) {
            $usOfSec += 1_000_000;
            $secs -= 1;
        }

        $dt = \DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', $secs, $usOfSec),
            new \DateTimeZone('UTC'),
        );
        \assert($dt !== false, description: 'createFromFormat U.u with integer-formatted input must succeed');

        return $dt->setTimezone($tz);
    }
}
