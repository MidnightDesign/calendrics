<?php

declare(strict_types=1);

namespace Temporal;

use Temporal\Spec\PlainTime as SpecPlainTime;
use Temporal\Trait\HasLocalizedString;
use Temporal\Trait\HasTimeOfDayProperties;
use Temporal\Trait\HasTimeOfDaySpec;

/**
 * A wall-clock time without a date or time zone.
 *
 * This is the porcelain wrapper around {@see SpecPlainTime}, providing a
 * type-safe PHP API with enums instead of raw option arrays.
 */
final class PlainTime implements \Stringable, \JsonSerializable, HasTimeOfDaySpec
{
    use HasTimeOfDayProperties;
    use HasLocalizedString;

    private readonly SpecPlainTime $spec;

    /**
     * Creates a new PlainTime from individual time components.
     *
     * @param int $hour        0–23
     * @param int $minute      0–59
     * @param int $second      0–59
     * @param int $millisecond 0–999
     * @param int $microsecond 0–999
     * @param int $nanosecond  0–999
     * @throws \Temporal\Exception\RangeError if any parameter is out of range.
     */
    public function __construct(
        int $hour = 0,
        int $minute = 0,
        int $second = 0,
        int $millisecond = 0,
        int $microsecond = 0,
        int $nanosecond = 0,
    ) {
        $this->spec = new SpecPlainTime($hour, $minute, $second, $millisecond, $microsecond, $nanosecond);
    }

    /**
     * Creates a PlainTime by parsing an ISO 8601 time string.
     *
     * @param string $text An ISO 8601 time string (e.g. "13:45:30.123456789").
     * @throws \Temporal\Exception\RangeError if the string is invalid.
     */
    public static function parse(string $text): self
    {
        return self::fromSpec(SpecPlainTime::from($text));
    }

    /**
     * Creates a PlainTime from a PHP `\DateTimeInterface`.
     *
     * The hour/minute/second/microsecond are read from `$dt`'s own time zone
     * (via the `G`, `i`, `s`, `u` format specifiers); date and zone identifier
     * are dropped.
     *
     * PHP's `\DateTimeImmutable` carries microsecond precision; the resulting
     * PlainTime always has `nanosecond = 0`.
     */
    public static function fromDateTime(\DateTimeInterface $dt): self
    {
        $u = (int) $dt->format('u');

        return new self(
            (int) $dt->format('G'),
            (int) $dt->format('i'),
            (int) $dt->format('s'),
            intdiv(num1: $u, num2: 1000),
            $u % 1000,
            0,
        );
    }

    /**
     * Compares two PlainTime values.
     *
     * @return int -1, 0, or 1.
     */
    public static function compare(self $one, self $two): int
    {
        return SpecPlainTime::compare($one->spec, $two->spec);
    }

    /**
     * Returns a new PlainTime with specified fields replaced.
     *
     * Only non-null parameters are applied; null parameters keep the current value.
     *
     * @param int<0, 23>|null  $hour        0–23, or null to keep.
     * @param int<0, 59>|null  $minute      0–59, or null to keep.
     * @param int<0, 59>|null  $second      0–59, or null to keep.
     * @param int<0, 999>|null $millisecond 0–999, or null to keep.
     * @param int<0, 999>|null $microsecond 0–999, or null to keep.
     * @param int<0, 999>|null $nanosecond  0–999, or null to keep.
     * @throws \Temporal\Exception\RangeError if a field value is out of range.
     */
    public function with(
        ?int $hour = null,
        ?int $minute = null,
        ?int $second = null,
        ?int $millisecond = null,
        ?int $microsecond = null,
        ?int $nanosecond = null,
    ): self {
        $fields = [];
        if ($hour !== null) {
            $fields['hour'] = $hour;
        }
        if ($minute !== null) {
            $fields['minute'] = $minute;
        }
        if ($second !== null) {
            $fields['second'] = $second;
        }
        if ($millisecond !== null) {
            $fields['millisecond'] = $millisecond;
        }
        if ($microsecond !== null) {
            $fields['microsecond'] = $microsecond;
        }
        if ($nanosecond !== null) {
            $fields['nanosecond'] = $nanosecond;
        }

        if ($fields === []) {
            return $this;
        }

        return self::fromSpec($this->spec->with($fields));
    }

    /**
     * Returns a new PlainTime advanced by the given duration.
     *
     * Calendar fields (years, months, weeks, days) in the duration are silently ignored.
     *
     * @param Duration $duration The duration to add.
     */
    public function add(Duration $duration): self
    {
        return self::fromSpec($this->spec->add($duration->toSpec()));
    }

    /**
     * Returns a new PlainTime moved back by the given duration.
     *
     * Calendar fields (years, months, weeks, days) in the duration are silently ignored.
     *
     * @param Duration $duration The duration to subtract.
     */
    public function subtract(Duration $duration): self
    {
        return self::fromSpec($this->spec->subtract($duration->toSpec()));
    }

    /**
     * Returns a new PlainTime rounded to the given unit and increment.
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
     * Returns the duration from $other to this time (this - other).
     *
     * @param self         $other              The other PlainTime to measure from.
     * @param Unit         $largestUnit        The largest unit in the result (default: Hour).
     * @param Unit         $smallestUnit       The smallest unit in the result (default: Nanosecond).
     * @param RoundingMode $roundingMode       Rounding mode (default: Trunc).
     * @param int          $roundingIncrement  Rounding increment (default: 1).
     */
    public function since(
        self $other,
        Unit $largestUnit = Unit::Hour,
        Unit $smallestUnit = Unit::Nanosecond,
        RoundingMode $roundingMode = RoundingMode::Trunc,
        int $roundingIncrement = 1,
    ): Duration {
        $opts = [
            'largestUnit' => $largestUnit->value,
            'smallestUnit' => $smallestUnit->value,
            'roundingMode' => $roundingMode->value,
            'roundingIncrement' => $roundingIncrement,
        ];

        return Duration::fromSpec($this->spec->since($other->spec, $opts));
    }

    /**
     * Returns the duration from this time to $other (other - this).
     *
     * @param self         $other              The other PlainTime to measure to.
     * @param Unit         $largestUnit        The largest unit in the result (default: Hour).
     * @param Unit         $smallestUnit       The smallest unit in the result (default: Nanosecond).
     * @param RoundingMode $roundingMode       Rounding mode (default: Trunc).
     * @param int          $roundingIncrement  Rounding increment (default: 1).
     */
    public function until(
        self $other,
        Unit $largestUnit = Unit::Hour,
        Unit $smallestUnit = Unit::Nanosecond,
        RoundingMode $roundingMode = RoundingMode::Trunc,
        int $roundingIncrement = 1,
    ): Duration {
        $opts = [
            'largestUnit' => $largestUnit->value,
            'smallestUnit' => $smallestUnit->value,
            'roundingMode' => $roundingMode->value,
            'roundingIncrement' => $roundingIncrement,
        ];

        return Duration::fromSpec($this->spec->until($other->spec, $opts));
    }

    /**
     * Returns true if this PlainTime represents the same time as $other.
     */
    public function equals(self $other): bool
    {
        return $this->spec->equals($other->spec);
    }

    /**
     * Returns an ISO 8601 time string representation.
     *
     * @param int|null      $fractionalSecondDigits Number of fractional second digits (0-9), or null for 'auto'.
     * @param Unit|null     $smallestUnit           Smallest unit to display; overrides $fractionalSecondDigits.
     * @param RoundingMode  $roundingMode           Rounding mode for display (default: Trunc).
     */
    public function toString(
        ?int $fractionalSecondDigits = null,
        ?Unit $smallestUnit = null,
        RoundingMode $roundingMode = RoundingMode::Trunc,
    ): string {
        $opts = [];

        if ($fractionalSecondDigits !== null) {
            $opts['fractionalSecondDigits'] = $fractionalSecondDigits;
        }
        if ($smallestUnit !== null) {
            $opts['smallestUnit'] = $smallestUnit->value;
        }
        $opts['roundingMode'] = $roundingMode->value;

        return $this->spec->toString($opts);
    }

    /**
     * Returns this time rendered for human readers in the given locale.
     *
     * Formatting is delegated to ICU via `ext-intl`, so the result follows the
     * locale's CLDR data and can change with the ICU version. It is display
     * text: use {@see self::toString()} whenever the string will be parsed
     * again.
     *
     * A PlainTime has no time zone, so {@see TimeStyle::Full} and
     * {@see TimeStyle::Long} render the UTC label the formatter falls back to
     * rather than a meaningful zone; {@see TimeStyle::Medium} is the widest
     * style that stays zone-free.
     *
     * @param string|null    $locale    BCP 47 locale tag (e.g. "de-DE"), or null for ICU's default locale.
     * @param TimeStyle|null $timeStyle How much of the time to show, or null for the locale's
     *                                  hour-minute-second default (e.g. "9:30:45 AM" in `en-US`).
     * @return string
     */
    public function toLocaleString(?string $locale = null, ?TimeStyle $timeStyle = null): string
    {
        return $this->spec->toLocaleString($locale, self::localeStyleOptions(timeStyle: $timeStyle));
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
     * Returns the underlying spec-layer PlainTime.
     */
    public function toSpec(): SpecPlainTime
    {
        return $this->spec;
    }

    /**
     * Creates a porcelain PlainTime from a spec-layer PlainTime.
     */
    public static function fromSpec(SpecPlainTime $spec): self
    {
        return new self(
            $spec->hour,
            $spec->minute,
            $spec->second,
            $spec->millisecond,
            $spec->microsecond,
            $spec->nanosecond,
        );
    }

    /**
     * Returns a human-readable representation for debugging.
     *
     * @return array<string, int>
     */
    public function __debugInfo(): array
    {
        return [
            'hour' => $this->hour,
            'minute' => $this->minute,
            'second' => $this->second,
            'millisecond' => $this->millisecond,
            'microsecond' => $this->microsecond,
            'nanosecond' => $this->nanosecond,
        ];
    }
}
