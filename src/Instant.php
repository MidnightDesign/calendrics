<?php

declare(strict_types=1);

namespace Temporal;

use Temporal\Spec\Internal\DateTimeFields;
use Temporal\Trait\HasEpochProperties;
use Temporal\Trait\HasEpochSpec;
use Temporal\Trait\HasLocalizedString;

/**
 * A fixed point in time with nanosecond precision.
 *
 * This is the porcelain (user-facing) wrapper around the spec-layer
 * {@see Spec\Instant}. It provides typed enums, named parameters, and a
 * simpler API surface for application code while delegating all computation
 * to the spec layer.
 */
final class Instant implements \Stringable, \JsonSerializable, HasEpochSpec
{
    use HasEpochProperties;
    use HasLocalizedString;

    /** The underlying spec-layer instance. */
    private readonly Spec\Instant $spec;

    /**
     * @param int $epochNanoseconds Nanoseconds since the Unix epoch.
     * @throws \Temporal\Exception\RangeError if the value is out of range.
     */
    public function __construct(int $epochNanoseconds)
    {
        $this->spec = new Spec\Instant($epochNanoseconds);
    }

    /**
     * Parses an ISO 8601 / RFC 3339 date-time string with a UTC offset into an Instant.
     *
     * @param string $text ISO 8601 string (e.g. "2020-01-01T00:00:00Z").
     * @throws \Temporal\Exception\RangeError if the string cannot be parsed.
     */
    public static function parse(string $text): self
    {
        return self::fromSpec(Spec\Instant::from($text));
    }

    /**
     * Creates an Instant from a Unix timestamp in milliseconds.
     *
     * @param int $epochMilliseconds Milliseconds since the Unix epoch.
     * @throws \Temporal\Exception\RangeError if the value is out of range.
     */
    public static function fromEpochMilliseconds(int $epochMilliseconds): self
    {
        return self::fromSpec(Spec\Instant::fromEpochMilliseconds($epochMilliseconds));
    }

    /**
     * Creates an Instant from a Unix timestamp in nanoseconds.
     *
     * @param int $epochNanoseconds Nanoseconds since the Unix epoch.
     */
    public static function fromEpochNanoseconds(int $epochNanoseconds): self
    {
        return self::fromSpec(Spec\Instant::fromEpochNanoseconds($epochNanoseconds));
    }

    /**
     * Creates an Instant from a PHP `\DateTimeInterface`.
     *
     * PHP's `\DateTimeImmutable` carries microsecond precision; sub-microsecond
     * Temporal bits (the lowest three decimal digits of `epochNanoseconds`) are
     * therefore always zero on the resulting Instant.
     */
    public static function fromDateTime(\DateTimeInterface $dt): self
    {
        return new self(DateTimeFields::epochNanoseconds($dt));
    }

    /**
     * Returns a `\DateTimeImmutable` for the same instant, in `$tz` (default UTC).
     *
     * The result has microsecond precision; sub-microsecond bits of the Temporal
     * `epochNanoseconds` are dropped (they cannot be represented by PHP's
     * native date-time types).
     *
     * @param \DateTimeZone|null $tz Display time zone, or null for UTC.
     */
    public function toDateTime(?\DateTimeZone $tz = null): \DateTimeImmutable
    {
        return DateTimeFields::toDateTime($this->spec->epochNanoseconds, $tz ?? new \DateTimeZone('UTC'));
    }

    /**
     * Compares two Instants chronologically.
     *
     * @return int -1, 0, or 1.
     */
    public static function compare(self $one, self $two): int
    {
        return Spec\Instant::compare($one->spec, $two->spec);
    }

    /**
     * Returns true when both Instants represent the same point in time.
     */
    public function equals(self $other): bool
    {
        return $this->spec->equals($other->spec);
    }

    /**
     * Returns a new Instant advanced by the given duration.
     *
     * Calendar fields (years, months, weeks, days) in the duration are forbidden.
     *
     * @param Duration $duration The duration to add.
     * @throws \Temporal\Exception\RangeError if the duration contains calendar fields.
     */
    public function add(Duration $duration): self
    {
        return self::fromSpec($this->spec->add($duration->toSpec()));
    }

    /**
     * Returns a new Instant moved back by the given duration.
     *
     * Calendar fields (years, months, weeks, days) in the duration are forbidden.
     *
     * @param Duration $duration The duration to subtract.
     * @throws \Temporal\Exception\RangeError if the duration contains calendar fields.
     */
    public function subtract(Duration $duration): self
    {
        return self::fromSpec($this->spec->subtract($duration->toSpec()));
    }

    /**
     * Returns a new Instant rounded to the given unit and increment.
     *
     * @param Unit         $smallestUnit       The unit to round to.
     * @param RoundingMode $roundingMode       Rounding mode (default: HalfExpand).
     * @param int          $roundingIncrement  Must evenly divide the next-larger unit.
     * @throws \Temporal\Exception\RangeError for invalid unit or increment values.
     */
    public function round(
        Unit $smallestUnit,
        RoundingMode $roundingMode = RoundingMode::HalfExpand,
        int $roundingIncrement = 1,
    ): self {
        return self::fromSpec($this->spec->round([
            'smallestUnit' => $smallestUnit->value,
            'roundingMode' => $roundingMode->value,
            'roundingIncrement' => $roundingIncrement,
        ]));
    }

    /**
     * Returns the duration from $other to this instant (this - other).
     *
     * @param self         $other             The other instant to measure from.
     * @param Unit         $largestUnit       The largest unit in the result (default: Second).
     * @param Unit         $smallestUnit      The smallest unit in the result (default: Nanosecond).
     * @param RoundingMode $roundingMode      How to round the result (default: Trunc).
     * @param int          $roundingIncrement The rounding increment for the smallest unit.
     */
    public function since(
        self $other,
        Unit $largestUnit = Unit::Second,
        Unit $smallestUnit = Unit::Nanosecond,
        RoundingMode $roundingMode = RoundingMode::Trunc,
        int $roundingIncrement = 1,
    ): Duration {
        return Duration::fromSpec($this->spec->since($other->spec, [
            'largestUnit' => $largestUnit->value,
            'smallestUnit' => $smallestUnit->value,
            'roundingMode' => $roundingMode->value,
            'roundingIncrement' => $roundingIncrement,
        ]));
    }

    /**
     * Returns the duration from this instant to $other (other - this).
     *
     * @param self         $other             The other instant to measure to.
     * @param Unit         $largestUnit       The largest unit in the result (default: Second).
     * @param Unit         $smallestUnit      The smallest unit in the result (default: Nanosecond).
     * @param RoundingMode $roundingMode      How to round the result (default: Trunc).
     * @param int          $roundingIncrement The rounding increment for the smallest unit.
     */
    public function until(
        self $other,
        Unit $largestUnit = Unit::Second,
        Unit $smallestUnit = Unit::Nanosecond,
        RoundingMode $roundingMode = RoundingMode::Trunc,
        int $roundingIncrement = 1,
    ): Duration {
        return Duration::fromSpec($this->spec->until($other->spec, [
            'largestUnit' => $largestUnit->value,
            'smallestUnit' => $smallestUnit->value,
            'roundingMode' => $roundingMode->value,
            'roundingIncrement' => $roundingIncrement,
        ]));
    }

    /**
     * Converts this Instant to a ZonedDateTime in the given time zone.
     *
     * @param string $timeZone IANA timezone identifier, UTC offset string, or 'UTC'.
     * @throws \Temporal\Exception\RangeError if the time zone is invalid.
     */
    public function toZonedDateTime(string $timeZone): ZonedDateTime
    {
        return ZonedDateTime::fromSpec($this->spec->toZonedDateTimeISO($timeZone));
    }

    /**
     * Returns an ISO 8601 string representation of this Instant.
     *
     * @param int|null      $fractionalSecondDigits Number of fractional second digits (0-9), or null for 'auto'.
     * @param Unit|null     $smallestUnit           Smallest unit to display; overrides $fractionalSecondDigits.
     * @param RoundingMode  $roundingMode           Rounding mode for display (default: Trunc).
     * @param string|null   $timeZone               Time zone for display; null means UTC ('Z' suffix).
     */
    public function toString(
        ?int $fractionalSecondDigits = null,
        ?Unit $smallestUnit = null,
        RoundingMode $roundingMode = RoundingMode::Trunc,
        ?string $timeZone = null,
    ): string {
        $opts = ['roundingMode' => $roundingMode->value];
        if ($fractionalSecondDigits !== null) {
            $opts['fractionalSecondDigits'] = $fractionalSecondDigits;
        }
        if ($smallestUnit !== null) {
            $opts['smallestUnit'] = $smallestUnit->value;
        }
        if ($timeZone !== null) {
            $opts['timeZone'] = $timeZone;
        }

        return $this->spec->toString($opts);
    }

    /**
     * Returns this instant rendered for human readers in the given locale.
     *
     * Formatting is delegated to ICU via `ext-intl`, so the result follows the
     * locale's CLDR data and can change with the ICU version. It is display
     * text: use {@see self::toString()} whenever the string will be parsed
     * again.
     *
     * An Instant is a point on the timeline with no civil calendar of its own,
     * so a zone has to be chosen before it can be written down. `$timeZone`
     * defaults to UTC — deterministic rather than machine-dependent — and is
     * usually worth setting explicitly.
     *
     * @param string|null    $locale    BCP 47 locale tag (e.g. "de-DE"), or null for ICU's default locale.
     * @param DateStyle|null $dateStyle How much of the date to spell out, or null.
     * @param TimeStyle|null $timeStyle How much of the time to show, or null.
     * @param string|null    $timeZone  IANA identifier or "±HH:MM" offset to render in; null means UTC.
     * @return string
     */
    public function toLocaleString(
        ?string $locale = null,
        ?DateStyle $dateStyle = null,
        ?TimeStyle $timeStyle = null,
        ?string $timeZone = null,
    ): string {
        $opts = self::localeStyleOptions($dateStyle, $timeStyle);
        if ($timeZone !== null) {
            $opts['timeZone'] = $timeZone;
        }

        return $this->spec->toLocaleString($locale, $opts);
    }

    /**
     * Returns the ISO 8601 string representation (default formatting).
     */
    #[\Override]
    public function __toString(): string
    {
        return $this->spec->toString();
    }

    /**
     * Returns the ISO 8601 string for JSON serialization.
     */
    #[\Override]
    public function jsonSerialize(): string
    {
        return $this->spec->toString();
    }

    /**
     * Returns the underlying spec-layer Instant.
     */
    public function toSpec(): Spec\Instant
    {
        return $this->spec;
    }

    /**
     * Creates a porcelain Instant from a spec-layer Instant.
     */
    public static function fromSpec(Spec\Instant $spec): self
    {
        return new self($spec->epochNanoseconds);
    }

    /**
     * Returns a human-readable representation for debugging.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'epochNanoseconds' => $this->epochNanoseconds,
            'epochMilliseconds' => $this->epochMilliseconds,
            'string' => $this->spec->toString(),
        ];
    }
}
