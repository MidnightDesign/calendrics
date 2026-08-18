<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

/**
 * The over-int64 instant representation shared by {@see Calendrics\Spec\Instant} and
 * {@see Calendrics\Spec\ZonedDateTime}, modeled as one immutable triple instead of
 * three scattered fields.
 *
 * The TC39 nanosecond range (±8.64e21 ns) exceeds PHP's signed 64-bit int, so both
 * classes keep the true value as a (epochSec, subNs) pair and clamp the public
 * epochNanoseconds field to a sentinel (PHP_INT_MIN/MAX) once the combined value no
 * longer fits int64. The pair is carried unconditionally — an exact int64 value keeps
 * it too — so nothing downstream has to guess whether a PHP_INT_MIN/MAX field is a
 * clamp marker or the instant's real nanosecond count. This object owns the pieces of
 * that bookkeeping that are byte-for-byte identical between the two classes:
 *
 *   - {@see narrowParts()} — the int|float → int part narrowing both seams accept.
 *   - {@see fromParts()} — sub-second normalization + the int64-fit / sentinel pack.
 *   - {@see fromNanoseconds()} — the same pack for a nanosecond count already int64.
 *
 * The spec range CHECK is left at the call sites because its RangeError message is
 * class-specific (Instant vs. ZonedDateTime wording); only the int64-fit / sentinel
 * packing moves here.
 *
 * @internal
 * @psalm-internal Calendrics\Spec
 */
final readonly class EpochValue
{
    /**
     * @param int $epochNanoseconds Combined nanoseconds since the Unix epoch, or a
     *        PHP_INT_MIN/MAX sentinel when the true value overflows int64.
     * @param int $trueEpochSec True UTC epoch seconds — always the real value, whether
     *        or not $epochNanoseconds clamped.
     * @param int $trueSubNs Sub-second nanoseconds (0–999_999_999) paired with
     *        $trueEpochSec.
     */
    public function __construct(
        public int $epochNanoseconds,
        public int $trueEpochSec,
        public int $trueSubNs,
    ) {}

    /**
     * Narrows an (epochSec, subNs) pair that may arrive as floats into int parts,
     * or returns null when the pair cannot describe a representable instant.
     *
     * Instant and ZonedDateTime expose @internal factories that take true epoch parts.
     * The test262 transpiler feeds them the floor decomposition of an over-int64 BigInt
     * epoch value, whose epoch-seconds component lands in PHP as a float literal once it
     * passes PHP_INT_MAX. Both factories therefore accept int|float and funnel the value
     * through here, so an out-of-range magnitude surfaces as the caller's RangeError
     * rather than as a TypeError from the parameter type.
     *
     * A finite over-int64 float epochSec cannot be inside the ±8.64e12 s spec range, so
     * it is rejected unconditionally. (float) PHP_INT_MAX rounds up past PHP_INT_MAX, so
     * the comparison uses the spec bound — which is < 2^53 and therefore exact in float.
     *
     * @return array{int, int}|null The int pair, or null when no valid pair exists.
     */
    public static function narrowParts(int|float $epochSec, int|float $subNs): ?array
    {
        $maxSec = EpochLimits::MAX_EPOCH_SECONDS;
        if (is_float($epochSec)) {
            if (!is_finite($epochSec) || $epochSec > (float) $maxSec || $epochSec < -(float) $maxSec) {
                return null;
            }
            $epochSec = (int) $epochSec;
        }
        if (is_float($subNs)) {
            if (
                !is_finite($subNs)
                || floor($subNs) !== $subNs
                || $subNs > (float) PHP_INT_MAX
                || $subNs < (float) PHP_INT_MIN
            ) {
                return null;
            }
            $subNs = (int) $subNs;
        }

        return [$epochSec, $subNs];
    }

    /**
     * Builds an EpochValue from an in-range (epochSec, subNs) pair: the sub-second
     * count is normalized into [0, 1e9) (carrying whole seconds, flooring toward −∞),
     * then the public epochNanoseconds field is resolved — packed exactly when the
     * combined value fits a signed 64-bit int, otherwise clamped to a sentinel
     * (PHP_INT_MIN for negative, PHP_INT_MAX for positive) with the true parts carried.
     *
     * Callers MUST have already range-checked the pair against the spec bound; this
     * factory only encodes the int64-fit / sentinel rule.
     */
    public static function fromParts(int $epochSec, int $subNs): self
    {
        // Normalize sub-second nanoseconds into [0, 1e9), carrying into seconds.
        // Unconditional floor-divide carry: for a $subNs already in [0, 1e9) the carry
        // is 0 and the pair is unchanged, so no in-range fast-path guard is needed (an
        // `if ($subNs < 0 || $subNs >= 1e9)` guard's `< 0` arm is in fact dead — every
        // caller reaches here with $subNs ≥ 0).
        $carry = CalendarMath::floorDiv($subNs, EpochLimits::NS_PER_SECOND);
        $epochSec += $carry;
        $subNs -= $carry * EpochLimits::NS_PER_SECOND;

        $maxSecForNs = EpochLimits::MAX_EPOCH_SECONDS_FOR_INT64_NS;
        // @infection-ignore-all > vs >= (and < vs <=) at ±MAX_EPOCH_SECONDS_FOR_INT64_NS
        // is unobservable: the boundary only differs at the exact second
        // ±9_223_372_035, and either way the sole effect is which of an equal-magnitude
        // int64-fitting pack vs. a sentinel clamp is chosen — and the clamped
        // epochNanoseconds field is never read directly (over-int64 reads route through
        // the carried trueEpochSec; the BigInt-overflow limit fixtures assert the
        // RangeError and the ±8.64e12 s boundary instants, both of which fit int64).
        if ($epochSec > $maxSecForNs || $epochSec < -$maxSecForNs) {
            // @infection-ignore-all Reached only when |epochSec| > MAX_EPOCH_SECONDS_FOR_INT64_NS,
            // so $epochSec is never 0 here (the < 0 ⇒ <= 0 boundary is dead) and the chosen
            // sentinel (PHP_INT_MIN/MAX) is never observed: every over-int64 read goes through
            // the carried trueEpochSec, and no runnable test262 fixture asserts the raw sentinel.
            // Swapping the ternary arms is therefore equivalent under the corpus.
            return new self($epochSec < 0 ? PHP_INT_MIN : PHP_INT_MAX, $epochSec, $subNs);
        }
        return new self(($epochSec * EpochLimits::NS_PER_SECOND) + $subNs, $epochSec, $subNs);
    }

    /**
     * Builds an EpochValue from a nanosecond count that is already an int — the value a
     * public constructor is handed verbatim — so the exact int64 boundary values
     * PHP_INT_MIN/PHP_INT_MAX carry their real parts instead of reading as clamp markers.
     *
     * The decomposition floors toward −∞ without multiplying the second count back:
     * PHP_INT_MIN floors to −9_223_372_037 s, whose nanosecond product is past
     * PHP_INT_MIN and would silently degrade the remainder to an imprecise float.
     */
    public static function fromNanoseconds(int $epochNanoseconds): self
    {
        $epochSec = intdiv($epochNanoseconds, EpochLimits::NS_PER_SECOND);
        $subNs = $epochNanoseconds % EpochLimits::NS_PER_SECOND;
        if ($subNs < 0) {
            $epochSec--;
            $subNs += EpochLimits::NS_PER_SECOND;
        }
        return new self($epochNanoseconds, $epochSec, $subNs);
    }
}
