<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Spec\Duration;

/**
 * The object a `relativeTo` denotes, with its spelling reduced away.
 *
 * TC39 ToRelativeTemporalObject accepts four spellings — a ZonedDateTime, a PlainDate,
 * an ISO string, a property bag — and turns all of them into one of two objects. Every
 * range rule that follows keys off which of the two it got, and none of them off the
 * spelling it came from, so the rules are asked of this value rather than of the raw
 * option. A guard written the other way round covers the spellings its author had in
 * mind and silently passes the ones they didn't.
 *
 * Which questions apply depends on {@see self::$zoned}: an anchor on the instant
 * timeline is bounded by the epoch range, one on the calendar by the date range.
 *
 * @internal
 */
final readonly class RelativeAnchor
{
    private function __construct(
        public bool $zoned,
        private int $epochSec,
        private int $subNs,
        private int $epochDays,
    ) {}

    /** An anchor on the instant timeline — a ZonedDateTime, however it was spelled. */
    public static function onInstant(int $epochSec, int $subNs): self
    {
        return new self(true, $epochSec, $subNs, 0);
    }

    /** An anchor on the calendar — a PlainDate, however it was spelled. */
    public static function onDate(int $epochDays): self
    {
        return new self(false, 0, 0, $epochDays);
    }

    /**
     * Whether adding $d to this instant lands outside the representable epoch range.
     *
     * Rounding against a zoned anchor means adding the duration to it and differencing
     * back, so the far end has to be representable too.
     */
    public function targetOutOfRange(Duration $d): bool
    {
        return RelativeTo::zdtTargetOutOfRange($this->epochSec, $this->subNs, $d);
    }

    /**
     * Whether the day surrounding this instant runs past the representable epoch range.
     *
     * Rounding to days-or-coarser needs the length of the anchor's day, which TC39 gets
     * by locating the start of the next day (and, on the negative edge, of this one). An
     * unrepresentable boundary is a RangeError however small the duration is — a blank
     * one included. The bound assumes a fixed 86 400 s day; a real zone shifts the
     * boundary by at most its offset change, far inside the margin left here.
     */
    public function dayBoundaryOutOfRange(): bool
    {
        $dayFloorSec = floor((float) $this->epochSec / 86_400.0) * 86_400.0;
        return (
            ($dayFloorSec + 86_400.0) > EpochLimits::MAX_EPOCH_SECONDS
            || $dayFloorSec < -EpochLimits::MAX_EPOCH_SECONDS
        );
    }

    /**
     * Whether this date's midnight lies outside the representable date-time range.
     *
     * A plain anchor is carried to a PlainDateTime at midnight to difference against,
     * and the earliest valid date has no representable midnight to be carried to.
     */
    public function midnightOutOfRange(): bool
    {
        return abs($this->epochDays) > 100_000_000;
    }
}
