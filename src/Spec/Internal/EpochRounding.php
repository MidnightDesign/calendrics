<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;

/**
 * Rounds a true epoch value, held as a (epochSec, subNs) pair, to a nanosecond
 * increment using TC39 RoundNumberToIncrementAsIfPositive.
 *
 * The decomposition into seconds + sub-second parts is load-bearing: an
 * over-int64 instant's combined nanosecond value does not fit a signed 64-bit
 * int, so the rounding is performed in the seconds domain (for minute-or-coarser
 * increments) or on the sub-second remainder alone (for sub-second increments),
 * and the combined value is never materialized. Shared by {@see Temporal\Spec\Instant}
 * and {@see Temporal\Spec\ZonedDateTime}.
 *
 * @internal
 */
final class EpochRounding
{
    /**
     * Rounds a true (epochSec, subNs) pair to the nearest multiple of $increment
     * nanoseconds. $subNs must be in [0, 1e9).
     *
     * @return array{int, int} [epochSec, subNs] with subNs in [0, 1e9)
     * @throws RangeError for unknown rounding modes.
     */
    public static function round(int $epochSec, int $subNs, int $increment, string $mode): array
    {
        if ($increment === 1) {
            return [$epochSec, $subNs];
        }

        if ($increment < EpochLimits::NS_PER_SECOND) {
            // Strictly sub-second increment: round the sub-second portion in isolation
            // and carry into seconds. A whole-second (or coarser) increment is excluded
            // here on purpose — at exactly NS_PER_SECOND the result lands on a whole
            // second, and the halfEven tie must break on the parity of that *second*,
            // not the (always-zero) sub-second quotient, so it is handled below.
            $roundedSubNs = self::roundAsIfPositive($subNs, $increment, $mode);
            if ($roundedSubNs >= EpochLimits::NS_PER_SECOND) {
                $epochSec += intdiv($roundedSubNs, EpochLimits::NS_PER_SECOND);
                $roundedSubNs %= EpochLimits::NS_PER_SECOND;
            }
            return [$epochSec, $roundedSubNs];
        }

        // Second (or coarser) increment: round in the seconds domain so the combined
        // nanosecond value never has to fit in int64. epochSec is bounded by the
        // valid-instant range (~|2.7e14|), so seconds-domain math is safe. The
        // sub-second remainder only matters at exact-half boundaries, where it tips
        // the distance strictly past the midpoint (AsIfPositive semantics) and the
        // halfEven tie resolves on the parity of the floor second multiple.
        $incSec = intdiv($increment, EpochLimits::NS_PER_SECOND);
        $floorSec = self::floorToIncrement($epochSec, $incSec);
        // d1 = distance from the floor multiple, in nanoseconds within [0, increment).
        $d1Ns = (($epochSec - $floorSec) * EpochLimits::NS_PER_SECOND) + $subNs;
        $expand = self::shouldExpand($d1Ns, $increment, $mode, intdiv($floorSec, $incSec));
        return [$expand ? $floorSec + $incSec : $floorSec, 0];
    }

    /**
     * Rounds $ns to the nearest multiple of $increment using
     * RoundNumberToIncrementAsIfPositive: 'floor'/'trunc' round toward zero,
     * 'ceil'/'expand' toward +∞, and the half-modes break ties with the positive-
     * sign convention regardless of the original value's sign.
     *
     * Package-internal seam: {@see Temporal\Spec\ZonedDateTime}, {@see Temporal\Spec\Instant},
     * {@see Temporal\Spec\PlainDateTime}, and {@see DateTimeDifference} call this for their
     * non-negative as-if-positive nanosecond rounding rather than re-implementing it. Not
     * part of any public surface.
     *
     * @throws RangeError for unknown rounding modes.
     */
    public static function roundAsIfPositive(int $ns, int $increment, string $mode): int
    {
        // Integer floor-division: r1 = floor(ns / increment).
        $q = intdiv($ns, $increment);
        $rem = $ns - ($q * $increment);
        $r1 = $rem < 0 ? $q - 1 : $q;

        // d1 = distance of $ns from r1 (always in [0, $increment)).
        $d1 = $ns - ($r1 * $increment);

        // Directed rounding (AsIfPositive: trunc/floor → r1; ceil/expand → r2).
        // The halfEven tie breaks on the parity of the floor multiple index $r1.
        $rounded = self::shouldExpand($d1, $increment, $mode, $r1) ? $r1 + 1 : $r1;

        return $rounded * $increment;
    }

    /**
     * The directed-rounding decision shared by {@see round} and {@see roundAsIfPositive}:
     * given the distance $distance from the floor multiple (always in [0, $increment)),
     * returns whether to expand away from that floor toward the next multiple. The
     * halfEven tie resolves on the parity of $floorMultiple, the index of the floor
     * multiple. AsIfPositive semantics: the half-modes use the positive-sign tie
     * convention regardless of the original value's sign.
     *
     * @throws RangeError for unknown rounding modes.
     */
    private static function shouldExpand(int $distance, int $increment, string $mode, int $floorMultiple): bool
    {
        return match ($mode) {
            'trunc', 'floor' => false,
            'ceil', 'expand' => $distance > 0,
            'halfExpand', 'halfCeil' => ($distance * 2) >= $increment,
            'halfTrunc', 'halfFloor' => ($distance * 2) > $increment,
            'halfEven' => ($distance * 2) === $increment ? ($floorMultiple % 2) !== 0 : ($distance * 2) > $increment,
            default => throw new RangeError("Invalid roundingMode \"{$mode}\"."),
        };
    }

    /** Largest multiple of $increment ≤ $value. */
    private static function floorToIncrement(int $value, int $increment): int
    {
        $q = intdiv($value, $increment);
        if (($value - ($q * $increment)) < 0) {
            $q--;
        }
        return $q * $increment;
    }
}
