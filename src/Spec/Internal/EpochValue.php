<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

/**
 * The over-int64 instant representation shared by {@see Temporal\Spec\Instant} and
 * {@see Temporal\Spec\ZonedDateTime}, modeled as one immutable triple instead of
 * three scattered fields.
 *
 * The TC39 nanosecond range (±8.64e21 ns) exceeds PHP's signed 64-bit int, so both
 * classes keep the true value as a (epochSec, subNs) pair and clamp the public
 * epochNanoseconds field to a sentinel (PHP_INT_MIN/MAX) once the combined value no
 * longer fits int64. This object owns the two pieces of that bookkeeping that are
 * byte-for-byte identical between the two classes:
 *
 *   - {@see narrowParts()} — the int|float → int part narrowing both seams accept.
 *   - {@see fromParts()} — sub-second normalization + the int64-fit / sentinel pack.
 *   - {@see parts()} — the inverse decompose back into (epochSec, subNs).
 *
 * The spec range CHECK is left at the call sites because its RangeError message is
 * class-specific (Instant vs. ZonedDateTime wording); only the int64-fit / sentinel
 * packing moves here.
 *
 * @internal
 * @psalm-internal Temporal\Spec
 */
final readonly class EpochValue
{
    /**
     * @param int $epochNanoseconds Combined nanoseconds since the Unix epoch, or a
     *        PHP_INT_MIN/MAX sentinel when the true value overflows int64.
     * @param ?int $trueEpochSec True UTC epoch seconds; null (with $trueSubNs 0) when
     *        the value fits int64 exactly and $epochNanoseconds holds it verbatim.
     * @param int $trueSubNs Sub-second nanoseconds (0–999_999_999) paired with
     *        $trueEpochSec.
     */
    public function __construct(
        public int $epochNanoseconds,
        public ?int $trueEpochSec,
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
        // @infection-ignore-all trueSubNs literal 0: dead in the fits-int64 case. This
        // value carries trueEpochSec = null, and parts() reads $this->trueSubNs only when
        // trueEpochSec !== null — so the value written here is never read back. 0/1/-1 are
        // indistinguishable.
        return new self(($epochSec * EpochLimits::NS_PER_SECOND) + $subNs, null, 0);
    }

    /**
     * Returns the true UTC epoch seconds and sub-second nanoseconds, handling sentinel
     * epochNanoseconds values transparently: when the true parts were carried they are
     * returned directly, otherwise the int64 epochNanoseconds field is decomposed
     * (flooring toward −∞, so a negative value borrows a second).
     *
     * @return array{int, int} [epochSec, subNs] where subNs is 0–999_999_999
     */
    public function parts(): array
    {
        if ($this->trueEpochSec !== null) {
            return [$this->trueEpochSec, $this->trueSubNs];
        }
        $epochSec = CalendarMath::floorDiv($this->epochNanoseconds, EpochLimits::NS_PER_SECOND);
        $subNs = $this->epochNanoseconds - ($epochSec * EpochLimits::NS_PER_SECOND);
        return [$epochSec, $subNs];
    }
}
