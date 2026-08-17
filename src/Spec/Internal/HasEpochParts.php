<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

/**
 * Backing storage and accessors for the over-int64 instant representation shared by
 * {@see \Temporal\Spec\Instant} and {@see \Temporal\Spec\ZonedDateTime}.
 *
 * Both classes carry the same instant as a triple — the public {@see $epochNanoseconds}
 * field (clamped to a PHP_INT_MIN/MAX sentinel once the true value overflows int64) plus
 * the {@see $trueEpochSec}/{@see $trueSubNs} pair that survives that clamp. The pair is
 * carried unconditionally, including for values that fit int64 exactly: PHP_INT_MIN and
 * PHP_INT_MAX are themselves legitimate nanosecond counts, so a field equal to a sentinel
 * says nothing on its own about whether the instant clamped. Reading the pair rather than
 * the field is therefore always right, and this trait owns both so that rule lives in one
 * place rather than being re-asserted by hand in each class:
 *
 *   - {@see epochParts()} — the (epochSec, subNs) pair every consumer should read.
 *   - {@see applyEpoch()} — stamps that pair from a single {@see EpochValue},
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
     * True UTC epoch seconds. Set for every instant, not only the over-int64 ones whose
     * {@see $epochNanoseconds} clamped to a sentinel: carrying the pair unconditionally
     * lets over-int64 (but in-spec) instants survive construction, arithmetic, and
     * conversion without clamping, and keeps an exact PHP_INT_MIN/MAX nanosecond count
     * from being mistaken for one of them.
     */
    private int $trueEpochSec;

    /** Sub-second nanoseconds (0–999_999_999) paired with {@see $trueEpochSec}. */
    private int $trueSubNs;

    /**
     * Returns the true UTC epoch seconds and sub-second nanoseconds.
     *
     * @return array{int, int} [epochSec, subNs] where subNs is 0–999_999_999
     */
    public function epochParts(): array
    {
        return [$this->trueEpochSec, $this->trueSubNs];
    }

    /**
     * Stamps the true epoch parts from $epoch onto this instance.
     *
     * The public {@see $epochNanoseconds} field is readonly and is set separately by the
     * constructor from the same {@see EpochValue}; this writer establishes the matching
     * {@see $trueEpochSec}/{@see $trueSubNs} pair so the triple is never assembled by hand.
     */
    private function applyEpoch(EpochValue $epoch): void
    {
        $this->trueEpochSec = $epoch->trueEpochSec;
        $this->trueSubNs = $epoch->trueSubNs;
    }
}
