<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Spec\Duration;

/**
 * The calendar portion of a difference: whole years, months, weeks and days.
 *
 * A zoned difference is computed as a calendar span plus a sub-day nanosecond remainder,
 * and the span passes through several stages before it is final — it gets re-anchored when
 * rounding overflows a day, gives a day back when the date portion overshoots a DST gap,
 * and is added back to the earlier instant twice to measure real elapsed time. Carrying it
 * as one value keeps those stages from threading four parallel ints through every
 * signature, and gives the two questions repeatedly asked of it — "is there anything here?"
 * and "what Duration does this add?" — a single answer each.
 *
 * The overall sign lives outside: a zoned difference is computed in the positive direction
 * and signed once at the end.
 *
 * @internal
 */
final readonly class DateSpan
{
    public function __construct(
        public int $years = 0,
        public int $months = 0,
        public int $weeks = 0,
        public int $days = 0,
    ) {}

    /**
     * Returns a copy with the day count replaced.
     */
    public function withDays(int $days): self
    {
        return new self($this->years, $this->months, $this->weeks, $days);
    }

    /**
     * Returns a copy with the years folded into months, for `largestUnit: 'month'`.
     */
    public function monthsOnly(): self
    {
        return new self(0, ($this->years * 12) + $this->months, $this->weeks, $this->days);
    }

    /**
     * Reports whether this span moves the date at all.
     *
     * A zero span means the two instants fall on the same local date, which is the case
     * where the sub-day remainder is the whole answer.
     */
    public function isZero(): bool
    {
        return $this->years === 0 && $this->months === 0 && $this->weeks === 0 && $this->days === 0;
    }

    /**
     * Materializes this span as a Duration, for adding it back to an instant.
     */
    public function toDuration(): Duration
    {
        return new Duration(years: $this->years, months: $this->months, weeks: $this->weeks, days: $this->days);
    }
}
