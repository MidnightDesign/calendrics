<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

/**
 * Temporal epoch range bounds, shared across Instant, ZonedDateTime, and Duration.
 *
 * The TC39 spec bounds a valid instant to ±8.64e21 nanoseconds, i.e.
 * ±8_640_000_000_000 seconds (±8_640_000_000_000_000 milliseconds). That full
 * nanosecond magnitude exceeds PHP's signed 64-bit int, so the implementation
 * keeps the true value in (epochSec, subNs) parts and clamps the public
 * epochNanoseconds field to a sentinel once it no longer fits int64. The
 * {@see self::MAX_EPOCH_SECONDS_FOR_INT64_NS} / {@see self::MAX_EPOCH_MILLISECONDS_FOR_INT64_NS}
 * bounds mark where that clamping starts.
 *
 * @internal
 */
final class EpochLimits
{
    /**
     * Maximum |epoch seconds| within the spec range (±8.64e12 s ⇒ ±8.64e21 ns).
     *
     * Comparisons against a float epoch value cast this to float at the call site
     * ((float) PHP_INT_MAX rounds up past PHP_INT_MAX, so the spec bound is the
     * faithful comparand); the value is < 2^53 and therefore exact in float.
     */
    public const int MAX_EPOCH_SECONDS = 8_640_000_000_000;

    /**
     * Maximum |epoch milliseconds| within the spec range
     * (= {@see self::MAX_EPOCH_SECONDS} × 1000).
     */
    public const int MAX_EPOCH_MILLISECONDS = 8_640_000_000_000_000;

    /**
     * Largest |epoch seconds| whose full nanosecond value still fits a signed
     * 64-bit int for any sub-second remainder in [0, 1e9).
     *
     * This is intentionally ONE LESS than floor(PHP_INT_MAX / 1e9) = 9_223_372_036,
     * so that MAX_EPOCH_SECONDS_FOR_INT64_NS × 1e9 + (1e9 − 1) ≤ PHP_INT_MAX. Do not
     * conflate it with intdiv(PHP_INT_MAX, NS_PER_SECOND) (= 9_223_372_036), which is
     * the bare int64 field limit used where no sub-second remainder is added. Beyond
     * this bound the public epochNanoseconds field is clamped to a sentinel.
     */
    public const int MAX_EPOCH_SECONDS_FOR_INT64_NS = 9_223_372_035;

    /**
     * Bare int64 epoch-seconds field limit: floor(PHP_INT_MAX / NS_PER_SECOND) =
     * intdiv(PHP_INT_MAX, 1e9) = 9_223_372_036.
     *
     * This is the threshold used where a WHOLE-second timestamp is multiplied by 1e9
     * with NO sub-second remainder added — e.g. a zone transition's seconds value,
     * which clamps the public epochNanoseconds field once |ts| × 1e9 would exceed
     * int64. It is deliberately ONE GREATER than
     * {@see self::MAX_EPOCH_SECONDS_FOR_INT64_NS} (= 9_223_372_035): that bound
     * reserves headroom for an added sub-second remainder up to 1e9 − 1
     * (so sec × 1e9 + 999_999_999 ≤ PHP_INT_MAX), whereas this bound assumes a zero
     * remainder (so sec × 1e9 ≤ PHP_INT_MAX permits the extra second). Use this one
     * only at call sites that add no sub-second nanoseconds.
     */
    public const int MAX_EPOCH_SECONDS_FOR_INT64_NS_FIELD = 9_223_372_036;

    /**
     * Largest |epoch milliseconds| whose nanosecond value (ms × 1e6) still fits a
     * signed 64-bit int: floor(PHP_INT_MAX / 1e6). Beyond it, fromEpochMilliseconds
     * decomposes into (epochSec, subNs) and routes through fromEpochParts().
     */
    public const int MAX_EPOCH_MILLISECONDS_FOR_INT64_NS = 9_223_372_036_854;

    /** Nanoseconds per day. */
    public const int NS_PER_DAY = 86_400_000_000_000;

    /** Nanoseconds per hour. */
    public const int NS_PER_HOUR = 3_600_000_000_000;

    /** Nanoseconds per minute. */
    public const int NS_PER_MINUTE = 60_000_000_000;

    /** Nanoseconds per second. */
    public const int NS_PER_SECOND = 1_000_000_000;

    /** Nanoseconds per millisecond. */
    public const int NS_PER_MILLISECOND = 1_000_000;

    /** Nanoseconds per microsecond. */
    public const int NS_PER_MICROSECOND = 1_000;
}
