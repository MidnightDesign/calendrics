<?php

declare(strict_types=1);

namespace Calendrics;

/**
 * A span of time expressed as 10 calendar and clock fields.
 *
 * This is the porcelain-layer wrapper around {@see Spec\Duration}, providing
 * typed enums, named parameters, and a simpler API surface for application code.
 */
final class Duration implements \Stringable, \JsonSerializable
{
    /**
     * Returns 1 if any field is positive, -1 if any field is negative, 0 if all are zero.
     *
     * @var int<-1, 1>
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $sign {
        get => $this->spec->sign;
    }

    /**
     * True when all fields are zero.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public bool $blank {
        get => $this->spec->blank;
    }

    /** The underlying spec-layer instance. */
    private readonly Spec\Duration $spec;

    /**
     * @throws \Calendrics\Exception\RangeError when fields are out of range or non-zero fields do not all share the same sign.
     */
    public function __construct(
        public readonly int $years = 0,
        public readonly int $months = 0,
        public readonly int $weeks = 0,
        public readonly int $days = 0,
        public readonly int $hours = 0,
        public readonly int $minutes = 0,
        public readonly int $seconds = 0,
        public readonly int $milliseconds = 0,
        public readonly int $microseconds = 0,
        public readonly int $nanoseconds = 0,
    ) {
        $this->spec = new Spec\Duration(
            $years,
            $months,
            $weeks,
            $days,
            $hours,
            $minutes,
            $seconds,
            $milliseconds,
            $microseconds,
            $nanoseconds,
        );
    }

    /**
     * Parses an ISO 8601 duration string into a Duration.
     *
     * @throws \Calendrics\Exception\RangeError if the string is not a valid ISO 8601 duration.
     */
    public static function parse(string $text): self
    {
        return self::fromSpec(Spec\Duration::from($text));
    }

    /**
     * Compares two Durations, returning -1, 0, or 1.
     *
     * A relativeTo anchor is required when either duration contains calendar units
     * (years, months, or weeks).
     *
     * @throws \Calendrics\Exception\RangeError if calendar units are present without a relativeTo anchor.
     */
    public static function compare(self $one, self $two, PlainDate|ZonedDateTime|null $relativeTo = null): int
    {
        if ($relativeTo !== null) {
            return Spec\Duration::compare($one->spec, $two->spec, ['relativeTo' => $relativeTo->toSpec()]);
        }

        return Spec\Duration::compare($one->spec, $two->spec);
    }

    /**
     * Returns a new Duration with all fields negated.
     */
    public function negated(): self
    {
        return self::fromSpec($this->spec->negated());
    }

    /**
     * Returns a new Duration with all fields made positive.
     */
    public function abs(): self
    {
        return self::fromSpec($this->spec->abs());
    }

    /**
     * Returns true when both Durations have identical field values.
     */
    public function equals(self $other): bool
    {
        return $this->spec->equals($other->spec);
    }

    /**
     * Returns a new Duration with the specified fields replaced; unspecified fields keep their current values.
     *
     * @throws \TypeError if no recognized Duration field is provided.
     * @throws \Calendrics\Exception\RangeError if the resulting fields have mixed signs.
     */
    public function with(
        ?int $years = null,
        ?int $months = null,
        ?int $weeks = null,
        ?int $days = null,
        ?int $hours = null,
        ?int $minutes = null,
        ?int $seconds = null,
        ?int $milliseconds = null,
        ?int $microseconds = null,
        ?int $nanoseconds = null,
    ): self {
        $fields = [];
        if ($years !== null) {
            $fields['years'] = $years;
        }
        if ($months !== null) {
            $fields['months'] = $months;
        }
        if ($weeks !== null) {
            $fields['weeks'] = $weeks;
        }
        if ($days !== null) {
            $fields['days'] = $days;
        }
        if ($hours !== null) {
            $fields['hours'] = $hours;
        }
        if ($minutes !== null) {
            $fields['minutes'] = $minutes;
        }
        if ($seconds !== null) {
            $fields['seconds'] = $seconds;
        }
        if ($milliseconds !== null) {
            $fields['milliseconds'] = $milliseconds;
        }
        if ($microseconds !== null) {
            $fields['microseconds'] = $microseconds;
        }
        if ($nanoseconds !== null) {
            $fields['nanoseconds'] = $nanoseconds;
        }

        return self::fromSpec($this->spec->with($fields));
    }

    /**
     * Returns the sum of this duration and another.
     *
     * Both durations must be free of calendar fields (years, months, weeks) unless
     * they cancel out to zero.
     *
     * @throws \Calendrics\Exception\RangeError if either duration has calendar fields.
     */
    public function add(self $other): self
    {
        return self::fromSpec($this->spec->add($other->spec));
    }

    /**
     * Returns the difference of this duration and another (equivalent to adding the negation).
     *
     * Both durations must be free of calendar fields (years, months, weeks) unless
     * they cancel out to zero.
     *
     * @throws \Calendrics\Exception\RangeError if either duration has calendar fields.
     */
    public function subtract(self $other): self
    {
        return self::fromSpec($this->spec->subtract($other->spec));
    }

    /**
     * Rounds this duration to the given unit(s) and options.
     *
     * At least one of smallestUnit or largestUnit must be provided.
     * A relativeTo anchor is required when the duration or rounding units involve
     * calendar fields (years, months, or weeks).
     *
     * @throws \Calendrics\Exception\RangeError if options are invalid or calendar units are used without a relativeTo anchor.
     */
    public function round(
        ?Unit $smallestUnit = null,
        ?Unit $largestUnit = null,
        RoundingMode $roundingMode = RoundingMode::HalfExpand,
        int $roundingIncrement = 1,
        PlainDate|ZonedDateTime|null $relativeTo = null,
    ): self {
        $opts = ['roundingMode' => $roundingMode->value, 'roundingIncrement' => $roundingIncrement];
        if ($smallestUnit !== null) {
            $opts['smallestUnit'] = $smallestUnit->value;
        }
        if ($largestUnit !== null) {
            $opts['largestUnit'] = $largestUnit->value;
        }
        if ($relativeTo !== null) {
            $opts['relativeTo'] = $relativeTo->toSpec();
        }

        return self::fromSpec($this->spec->round($opts));
    }

    /**
     * Returns the total value of this duration in the given unit as a number.
     *
     * A relativeTo anchor is required when the target unit or the duration itself
     * involves calendar fields (years, months, or weeks).
     *
     * @throws \Calendrics\Exception\RangeError if calendar units are used without a relativeTo anchor.
     */
    public function total(Unit $unit, PlainDate|ZonedDateTime|null $relativeTo = null): int|float
    {
        $opts = ['unit' => $unit->value];
        if ($relativeTo !== null) {
            $opts['relativeTo'] = $relativeTo->toSpec();
        }

        return $this->spec->total($opts);
    }

    /**
     * Returns an ISO 8601 duration string, with optional rounding/precision options.
     *
     * @param int|null  $fractionalSecondDigits Number of sub-second digits (0-9), or null for auto.
     * @param Unit|null $smallestUnit           Overrides fractionalSecondDigits when provided.
     * @param RoundingMode $roundingMode        Rounding mode for sub-second truncation (default: Trunc).
     */
    public function toString(
        ?int $fractionalSecondDigits = null,
        ?Unit $smallestUnit = null,
        RoundingMode $roundingMode = RoundingMode::Trunc,
    ): string {
        $opts = ['roundingMode' => $roundingMode->value];
        if ($fractionalSecondDigits !== null) {
            $opts['fractionalSecondDigits'] = $fractionalSecondDigits;
        }
        if ($smallestUnit !== null) {
            $opts['smallestUnit'] = $smallestUnit->value;
        }

        return $this->spec->toString($opts);
    }

    /** Returns the ISO 8601 string representation. */
    #[\Override]
    public function __toString(): string
    {
        return $this->toString();
    }

    /** Returns the ISO 8601 string representation for JSON encoding. */
    #[\Override]
    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    /** Returns the underlying spec-layer instance. */
    public function toSpec(): Spec\Duration
    {
        return $this->spec;
    }

    /** Wraps a spec-layer Duration instance into a porcelain Duration. */
    public static function fromSpec(Spec\Duration $spec): self
    {
        return new self(
            (int) $spec->years,
            (int) $spec->months,
            (int) $spec->weeks,
            (int) $spec->days,
            (int) $spec->hours,
            (int) $spec->minutes,
            (int) $spec->seconds,
            (int) $spec->milliseconds,
            (int) $spec->microseconds,
            (int) $spec->nanoseconds,
        );
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'string' => $this->toString(),
            'years' => $this->years,
            'months' => $this->months,
            'weeks' => $this->weeks,
            'days' => $this->days,
            'hours' => $this->hours,
            'minutes' => $this->minutes,
            'seconds' => $this->seconds,
            'milliseconds' => $this->milliseconds,
            'microseconds' => $this->microseconds,
            'nanoseconds' => $this->nanoseconds,
        ];
    }
}
