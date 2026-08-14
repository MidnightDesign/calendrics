<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

/**
 * Backing storage and accessors for the over-int64 instant representation shared by
 * {@see \Temporal\Spec\Instant} and {@see \Temporal\Spec\ZonedDateTime}.
 *
 * Both classes carry the same instant as a triple — the public {@see $epochNanoseconds}
 * field (clamped to a PHP_INT_MIN/MAX sentinel once the true value overflows int64) plus
 * the carried {@see $trueEpochSec}/{@see $trueSubNs} pair that survives that clamp. This
 * trait owns those three fields and every operation on them so the
 * "epochNanoseconds is a sentinel iff trueEpochSec !== null" invariant lives in exactly
 * one place rather than being re-asserted by hand in each class:
 *
 *   - {@see epochParts()} — decodes the triple back into (epochSec, subNs), handling
 *     sentinels transparently without allocating a temporary {@see EpochValue}.
 *   - {@see isClampedEpoch()} — reports the one state the decode cannot rescue, so callers
 *     ask about the invariant instead of re-deriving it from the raw fields.
 *   - {@see applyEpoch()} — stamps the carried true parts from a single {@see EpochValue},
 *     the canonical encoder of the int64-fit / sentinel rule.
 *
 * {@see $epochNanoseconds} is `readonly` and is therefore still assigned directly by each
 * using class's constructor (the only place a readonly field may be written) from the same
 * {@see EpochValue} that {@see applyEpoch()} consumes; the two together spread one object,
 * never a hand-built triple.
 *
 * @internal
 * @psalm-internal Temporal\Spec
 */
trait HasEpochParts
{
    /**
     * Combined nanoseconds since the Unix epoch, or a PHP_INT_MIN/MAX sentinel when the
     * true value overflows int64 (in which case the true value is carried in
     * {@see $trueEpochSec}/{@see $trueSubNs}). Assigned by the using class's constructor.
     *
     * @psalm-suppress PropertyNotSetInConstructor — set unconditionally in each using class's constructor
     */
    public readonly int $epochNanoseconds;

    /**
     * True UTC epoch seconds — set when {@see $epochNanoseconds} is a sentinel
     * (PHP_INT_MIN/MAX) because the actual value overflows int64 nanoseconds. Carrying
     * the true parts lets over-int64 (but in-spec) instants survive construction,
     * arithmetic, and conversion without clamping.
     */
    private ?int $trueEpochSec = null;

    /** Sub-second nanoseconds (0–999_999_999) paired with {@see $trueEpochSec}. */
    private int $trueSubNs = 0;

    /**
     * Returns the true UTC epoch seconds and sub-second nanoseconds, handling sentinel
     * {@see $epochNanoseconds} values transparently.
     *
     * @return array{int, int} [epochSec, subNs] where subNs is 0–999_999_999
     */
    public function epochParts(): array
    {
        if ($this->trueEpochSec !== null) {
            return [$this->trueEpochSec, $this->trueSubNs];
        }
        $epochSec = CalendarMath::floorDiv($this->epochNanoseconds, 1_000_000_000);
        return [$epochSec, $this->epochNanoseconds - ($epochSec * 1_000_000_000)];
    }

    /**
     * True when {@see $epochNanoseconds} sits at a PHP_INT_MIN/MAX sentinel with no
     * carried true parts behind it.
     *
     * That combination is the one state in which the instant is genuinely lost rather
     * than merely unrepresentable as an int64 nanosecond count: the field was clamped and
     * nothing was stashed, so {@see epochParts()} can only decode the sentinel itself.
     * Operations that would have to read the true instant back — as opposed to those that
     * work from the local calendar fields — must refuse it.
     */
    public function isClampedEpoch(): bool
    {
        return (
            $this->trueEpochSec === null
            && ($this->epochNanoseconds === PHP_INT_MAX || $this->epochNanoseconds === PHP_INT_MIN)
        );
    }

    /**
     * Stamps the carried true epoch parts from $epoch onto this instance.
     *
     * The public {@see $epochNanoseconds} field is readonly and is set separately by the
     * constructor from the same {@see EpochValue}; this writer establishes the matching
     * sentinel bookkeeping ({@see $trueEpochSec}/{@see $trueSubNs}) so the
     * "sentinel iff trueEpochSec !== null" invariant is never assembled by hand.
     */
    private function applyEpoch(EpochValue $epoch): void
    {
        $this->trueEpochSec = $epoch->trueEpochSec;
        $this->trueSubNs = $epoch->trueSubNs;
    }
}
