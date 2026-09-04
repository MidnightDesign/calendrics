<?php

declare(strict_types=1);

namespace Calendrics\Spec;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\AnchorMath;
use Calendrics\Spec\Internal\DurationRounding;
use Calendrics\Spec\Internal\DurationTotal;
use Calendrics\Spec\Internal\FieldBag;
use Calendrics\Spec\Internal\Options;
use Calendrics\Spec\Internal\RelativeTo;
use Stringable;

/**
 * A span of time expressed as 10 calendar and clock fields.
 *
 * All non-zero fields must share the same sign. Calendar fields (years,
 * months, weeks) cannot be converted to nanoseconds without a reference date,
 * so no internal nanosecond total is maintained.
 *
 * @see https://tc39.es/proposal-temporal/#sec-temporal-duration-objects
 */
final class Duration implements Stringable
{
    /**
     * The ten recognized duration fields. A duration carries no calendar, so this list
     * is fixed — there are no CalendarExtraFields to add.
     *
     * @var list<string>
     */
    private const array PLURAL_FIELDS = [
        'years',
        'months',
        'weeks',
        'days',
        'hours',
        'minutes',
        'seconds',
        'milliseconds',
        'microseconds',
        'nanoseconds',
    ];

    /**
     * Returns 1 if any field is positive, -1 if any field is negative, 0 if all are zero.
     *
     * @var int<-1, 1>
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $sign {
        get {
            foreach ([
                $this->years,
                $this->months,
                $this->weeks,
                $this->days,
                $this->hours,
                $this->minutes,
                $this->seconds,
                $this->milliseconds,
                $this->microseconds,
                $this->nanoseconds,
            ] as $v) {
                if ($v !== 0) {
                    return $v > 0 ? 1 : -1;
                }
            }
            return 0;
        }
    }

    /**
     * True when all fields are zero.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public bool $blank {
        get => $this->sign === 0;
    }

    /**
     * @throws RangeError when fields are out of range or non-zero fields do not all share the same sign.
     */
    public function __construct(
        public readonly int|float $years = 0,
        public readonly int|float $months = 0,
        public readonly int|float $weeks = 0,
        public readonly int|float $days = 0,
        public readonly int|float $hours = 0,
        public readonly int|float $minutes = 0,
        public readonly int|float $seconds = 0,
        public readonly int|float $milliseconds = 0,
        public readonly int|float $microseconds = 0,
        public readonly int|float $nanoseconds = 0,
    ) {
        $years = $this->years;
        $months = $this->months;
        $weeks = $this->weeks;
        $days = $this->days;
        $hours = $this->hours;
        $minutes = $this->minutes;
        $seconds = $this->seconds;
        $milliseconds = $this->milliseconds;
        $microseconds = $this->microseconds;
        $nanoseconds = $this->nanoseconds;

        $allInt =
            is_int($years)
            && is_int($months)
            && is_int($weeks)
            && is_int($days)
            && is_int($hours)
            && is_int($minutes)
            && is_int($seconds)
            && is_int($milliseconds)
            && is_int($microseconds)
            && is_int($nanoseconds);

        // TC39: each Duration field must be finite and integer-valued.
        // Skip validation entirely in the common all-int case.
        if (!$allInt) {
            foreach ([
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
            ] as $field) {
                if (!is_float($field)) {
                    continue;
                }

                if (!is_finite($field)) {
                    throw new RangeError('Duration fields must be finite; Infinity and NaN are not allowed.');
                }
                if (fmod(num1: $field, num2: 1.0) !== 0.0) {
                    throw new RangeError('Duration fields must be integer-valued; fractional values are not allowed.');
                }
            }
        }

        // TC39 §7.5.10 IsValidDuration — calendar fields capped at 2^32.
        /** @infection-ignore-all GreaterThanOrEqual |x| >= 2^32 vs > 2^32-1 are identical for integers */
        if (abs($years) >= 4_294_967_296 || abs($months) >= 4_294_967_296 || abs($weeks) >= 4_294_967_296) {
            throw new RangeError('Duration years, months, and weeks must each be less than 2^32 in absolute value.');
        }

        // TC39 §7.5.11 IsValidDuration: the combined total of days + time fields must not
        // exceed MaxTimeDuration = 2^53 × 10^9 - 1 nanoseconds. The bound is checked on
        // $seconds itself rather than on an int cast of it: a float beyond int64 range
        // casts to 0, which would pass the check and then balance as zero seconds.
        if (abs($seconds) > 9_007_199_254_740_991) {
            throw new RangeError('Duration time fields exceed the maximum representable range.');
        }
        $secI = (int) $seconds;

        if (
            is_int($nanoseconds)
            && is_int($microseconds)
            && is_int($milliseconds)
            && is_int($days)
            && is_int($hours)
            && is_int($minutes)
        ) {
            // All-integer path: propagate carry ns → µs → ms → s → check full total.
            $carryNs = intdiv(num1: $nanoseconds, num2: 1_000);
            $usEff = $microseconds + $carryNs;
            $carryUs = intdiv(num1: $usEff, num2: 1_000);
            $msEff = $milliseconds + $carryUs;
            $carryMs = intdiv(num1: $msEff, num2: 1_000);
            $sEff = $secI + $carryMs;
            $intSecFull = ($days * 86_400) + ($hours * 3_600) + ($minutes * 60) + $sEff;
            if ($intSecFull > 9_007_199_254_740_991 || $intSecFull < -9_007_199_254_740_991) {
                throw new RangeError('Duration time fields exceed the maximum representable range.');
            }
        } else {
            // Float path: any field is a float (large µs/ns may exceed PHP int64).
            $MAX_SAFE_F = 9_007_199_254_740_992.0; // 2^53 exactly as float64
            $subNs = ((float) $milliseconds * 1_000_000.0) + ((float) $microseconds * 1_000.0) + (float) $nanoseconds;
            $totalSec =
                ((float) $days * 86_400.0)
                + ((float) $hours * 3_600.0)
                + ((float) $minutes * 60.0)
                + (float) $seconds
                + ($subNs / 1_000_000_000.0);
            if (abs($totalSec) > $MAX_SAFE_F) {
                throw new RangeError('Duration time fields exceed the maximum representable range.');
            }
        }

        // Sign check: all non-zero fields must share the same sign.
        // Inlined to avoid fields() array allocation per construction.
        $positive = null;
        foreach ([
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
        ] as $v) {
            if ($v === 0 || $v === 0.0) {
                continue;
            }
            /** @infection-ignore-all GreaterThan > 0 ≡ >= 0 when $v is guaranteed non-zero (guarded above) */
            $isPositive = $v > 0;
            if ($positive === null) {
                $positive = $isPositive;
                continue;
            }
            if ($positive !== $isPositive) {
                throw new RangeError('All non-zero Duration fields must have the same sign.');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Static factory methods
    // -------------------------------------------------------------------------

    /**
     * Creates a Duration from an existing Duration, a property-bag array, or an ISO 8601 string.
     *
     * Property-bag example: ['years' => 1, 'hours' => 2]
     * String examples: 'P1Y', 'PT30M', '-P1DT2H', 'PT1.5S', 'PT1,5S', 'PT1.03125H'
     *
     * @param self|string|array<array-key, mixed>|object $item Duration, array property bag, or ISO 8601 duration string.
     * @throws RangeError if the value cannot be interpreted as a Duration.
     * @throws \TypeError if the type is not Duration, array, or string.
     */
    public static function from(string|array|object $item): self
    {
        if ($item instanceof self) {
            // TC39 requires a new instance, not the same reference.
            return new self(
                $item->years,
                $item->months,
                $item->weeks,
                $item->days,
                $item->hours,
                $item->minutes,
                $item->seconds,
                $item->milliseconds,
                $item->microseconds,
                $item->nanoseconds,
            );
        }
        if (is_string($item)) {
            return self::fromString($item);
        }
        $item = FieldBag::forFields($item, self::PLURAL_FIELDS);
        return self::parseDurationLike($item);
    }

    /**
     * Parses an ISO 8601 duration string.
     *
     * Supported examples:
     *   'P1Y', 'PT30M', '-P1DT2H', 'PT1.5S', 'PT1,5S', 'PT1.03125H', 'P1Y2M3W4DT5H6M7.008009001S'
     *
     * The overall sign prefix (+ or -) applies to all components. Individual
     * component signs are not supported (e.g. 'P-1Y' is invalid per TC39).
     * A decimal fraction may appear only on the last present component (ISO 8601 §5.5.3.5).
     *
     * @throws RangeError if the string is not a valid ISO 8601 duration.
     */
    private static function fromString(string $text): self
    {
        /*
         * Regex groups:
         *   1  — overall sign (+ / - / empty)
         *   2  — years     3  — months    4  — weeks    5  — days
         *   6  — hours     7  — hours fraction digits
         *   8  — minutes   9  — minutes fraction digits
         *  10  — seconds  11  — seconds fraction digits
         *
         * The (?=\d) lookahead after T prevents 'P1YT' from matching.
         */
        $pattern = '/^([+-])?P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?(?:T(?=\d)(?:(\d+)(?:[.,](\d+))?H)?(?:(\d+)(?:[.,](\d+))?M)?(?:(\d+)(?:[.,](\d+))?S)?)?$/i';

        // PCRE2 omits optional group captures from the array when their outer
        // optional group never participated.
        /** @var array<string> $m */
        $m = [];
        if (preg_match($pattern, $text, $m) !== 1) {
            throw new RangeError("Invalid Duration string \"{$text}\": expected ISO 8601 duration.");
        }

        $hoursFrac = $m[7] ?? '';
        $minutesStr = $m[8] ?? '';
        $minutesFrac = $m[9] ?? '';
        $secondsStr = $m[10] ?? '';
        $secondsFrac = $m[11] ?? '';

        // TC39: seconds fraction must have at most 9 digits.
        if (strlen($secondsFrac) > 9) {
            throw new RangeError("Invalid Duration string \"{$text}\": seconds fraction must have at most 9 digits.");
        }

        // ISO 8601: a decimal fraction may appear only on the last present component.
        if ($hoursFrac !== '' && ($minutesStr !== '' || $secondsStr !== '')) {
            throw new RangeError("Invalid Duration string \"{$text}\": fraction only allowed on the last component.");
        }
        if ($minutesFrac !== '' && $secondsStr !== '') {
            throw new RangeError("Invalid Duration string \"{$text}\": fraction only allowed on the last component.");
        }

        /** @var array<string> $allGroups */
        $allGroups = [
            $m[2] ?? '',
            $m[3] ?? '',
            $m[4] ?? '',
            $m[5] ?? '',
            $m[6] ?? '',
            $hoursFrac,
            $minutesStr,
            $minutesFrac,
            $secondsStr,
            $secondsFrac,
        ];
        if (implode('', $allGroups) === '') {
            throw new RangeError("Invalid Duration string \"{$text}\": at least one field is required.");
        }

        // (int)'' === 0, so absent/empty groups naturally become 0.
        // Guard against very large digit strings: PHP's (int) cast silently returns 0 for strings
        // that overflow int64 (e.g. "9"×1000), so check float64 first.
        $safeInt = static function (string $digits): int {
            if ($digits === '' || !is_numeric($digits)) {
                return 0;
            }
            $f = (float) $digits;
            if (!is_finite($f)) {
                throw new RangeError('Duration field value is too large (overflows to Infinity).');
            }
            return (int) $digits;
        };

        $years = $safeInt($m[2] ?? '');
        $months = $safeInt($m[3] ?? '');
        $weeks = $safeInt($m[4] ?? '');
        $days = $safeInt($m[5] ?? '');
        $hours = $safeInt($m[6] ?? '');
        $minutes = $safeInt($minutesStr);
        $seconds = $safeInt($secondsStr);

        $milliseconds = 0;
        $microseconds = 0;
        $nanoseconds = 0;

        if ($hoursFrac !== '') {
            // Distribute fractional hours (1 H = 3 600 000 000 000 ns) into smaller units.
            [$dm, $ds, $dms, $dus, $dns] = self::distributeFracNs($hoursFrac, 3_600_000_000_000);
            $minutes += $dm;
            $seconds += $ds;
            $milliseconds = $dms;
            $microseconds = $dus;
            $nanoseconds = $dns;
        } elseif ($minutesFrac !== '') {
            // Distribute fractional minutes (1 M = 60 000 000 000 ns) into smaller units.
            [, $ds, $dms, $dus, $dns] = self::distributeFracNs($minutesFrac, 60_000_000_000);
            $seconds += $ds;
            $milliseconds = $dms;
            $microseconds = $dus;
            $nanoseconds = $dns;
        } elseif ($secondsFrac !== '') {
            /** @infection-ignore-all IncrementInteger length 9→10 is equivalent: str_pad only appends chars, positions 0–8 are identical in both padded strings */
            $frac = str_pad($secondsFrac, length: 9, pad_string: '0');
            $milliseconds = (int) substr($frac, offset: 0, length: 3);
            $microseconds = (int) substr($frac, offset: 3, length: 3);
            $nanoseconds = (int) substr($frac, offset: 6, length: 3);
        }

        /** @infection-ignore-all EqualIdentical === vs == is equivalent for two string operands */
        if (($m[1] ?? '') === '-') {
            return new self(
                -$years,
                -$months,
                -$weeks,
                -$days,
                -$hours,
                -$minutes,
                -$seconds,
                -$milliseconds,
                -$microseconds,
                -$nanoseconds,
            );
        }

        return new self(
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

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Returns a Duration with all fields negated.
     */
    public function negated(): self
    {
        return new self(
            -$this->years,
            -$this->months,
            -$this->weeks,
            -$this->days,
            -$this->hours,
            -$this->minutes,
            -$this->seconds,
            -$this->milliseconds,
            -$this->microseconds,
            -$this->nanoseconds,
        );
    }

    /**
     * Returns a Duration with all fields made positive.
     */
    public function abs(): self
    {
        return new self(
            abs($this->years),
            abs($this->months),
            abs($this->weeks),
            abs($this->days),
            abs($this->hours),
            abs($this->minutes),
            abs($this->seconds),
            abs($this->milliseconds),
            abs($this->microseconds),
            abs($this->nanoseconds),
        );
    }

    /**
     * Returns true when both Durations have identical field values.
     */
    public function equals(self $other): bool
    {
        return (
            $this->years === $other->years
            && $this->months === $other->months
            && $this->weeks === $other->weeks
            && $this->days === $other->days
            && $this->hours === $other->hours
            && $this->minutes === $other->minutes
            && $this->seconds === $other->seconds
            && $this->milliseconds === $other->milliseconds
            && $this->microseconds === $other->microseconds
            && $this->nanoseconds === $other->nanoseconds
        );
    }

    /**
     * Returns a Duration with the specified fields replaced.
     *
     * @param array<array-key, mixed>|object $fields
     * @throws \TypeError if $fields is not an array/object or has no recognized plural Duration field.
     */
    public function with(array|object $fields): self
    {
        // Reject Temporal objects (IsPartialTemporalObject step 2).
        if (
            $fields instanceof self
            || $fields instanceof PlainDate
            || $fields instanceof PlainDateTime
            || $fields instanceof PlainTime
            || $fields instanceof PlainYearMonth
            || $fields instanceof PlainMonthDay
            || $fields instanceof ZonedDateTime
            || $fields instanceof Instant
        ) {
            throw new TypeError('Duration::with() argument must not be a Temporal object.');
        }

        $fields = FieldBag::forFields($fields, self::PLURAL_FIELDS);

        // TC39 ToTemporalPartialDurationRecord: at least one recognized plural field required.
        $hasAny = false;
        foreach (self::PLURAL_FIELDS as $f) {
            if (!array_key_exists($f, $fields)) {
                continue;
            }

            $hasAny = true;
            break;
        }
        if (!$hasAny) {
            throw new TypeError(
                'Duration::with() property bag must contain at least one recognized Duration field (years, months, weeks, days, hours, minutes, seconds, milliseconds, microseconds, nanoseconds).',
            );
        }

        // Use parseDurationLike to validate and cast each field; current values are defaults.
        /** @var array<string, mixed> $merged */
        $merged = [
            'years' => $fields['years'] ?? $this->years,
            'months' => $fields['months'] ?? $this->months,
            'weeks' => $fields['weeks'] ?? $this->weeks,
            'days' => $fields['days'] ?? $this->days,
            'hours' => $fields['hours'] ?? $this->hours,
            'minutes' => $fields['minutes'] ?? $this->minutes,
            'seconds' => $fields['seconds'] ?? $this->seconds,
            'milliseconds' => $fields['milliseconds'] ?? $this->milliseconds,
            'microseconds' => $fields['microseconds'] ?? $this->microseconds,
            'nanoseconds' => $fields['nanoseconds'] ?? $this->nanoseconds,
        ];
        return self::parseDurationLike($merged);
    }

    /**
     * Returns an ISO 8601 duration string, with optional rounding/precision options.
     *
     * Options (all optional):
     *   - fractionalSecondDigits: 'auto' (default) | 0–9 | non-integer (floored)
     *   - smallestUnit: 'second[s]'|'millisecond[s]'|'microsecond[s]'|'nanosecond[s]' (overrides fractionalSecondDigits)
     *   - roundingMode: 'trunc' (default) | 'floor' | 'ceil' | 'expand' | 'halfExpand' | 'halfTrunc' | 'halfFloor' | 'halfCeil' | 'halfEven'
     *
     * @param array<array-key, mixed>|object $options an array of options, or any object (treated as empty options bag).
     * @throws RangeError if options are invalid or rounding causes overflow.
     * @throws \TypeError if $options is an explicit null or a non-array, non-object scalar.
     */
    public function toString(mixed $options = []): string
    {
        // GetOptionsObject: explicit null / non-object primitive / Symbol => TypeError.
        // An omitted options argument arrives as the empty-array default.
        $options = Options::requireObject($options, ['fractionalSecondDigits', 'roundingMode', 'smallestUnit']);

        // $digits: null = auto, 0–9 = exact digit count.
        $digits = null;
        $roundingMode = 'trunc';

        if ($options !== []) {
            // fractionalSecondDigits
            if (array_key_exists('fractionalSecondDigits', $options)) {
                $fsd = Options::fractionalSecondDigits($options['fractionalSecondDigits']);
                if ($fsd !== null) {
                    $digits = $fsd;
                }
            }

            // smallestUnit overrides fractionalSecondDigits
            if (array_key_exists('smallestUnit', $options) && $options['smallestUnit'] !== null) {
                $su = Options::coerceEnumOption($options['smallestUnit'], 'smallestUnit');
                $digits = match ($su) {
                    'second', 'seconds' => 0,
                    'millisecond', 'milliseconds' => 3,
                    'microsecond', 'microseconds' => 6,
                    'nanosecond', 'nanoseconds' => 9,
                    default => throw new RangeError(
                        "Invalid smallestUnit \"{$su}\": must be second(s), millisecond(s), microsecond(s), or nanosecond(s).",
                    ),
                };
            }

            // roundingMode
            if (array_key_exists('roundingMode', $options) && $options['roundingMode'] !== null) {
                $roundingMode = Options::roundingMode(Options::coerceEnumOption(
                    $options['roundingMode'],
                    'roundingMode',
                ));
            }
        }

        // Early return for blank duration in auto mode.
        if ($this->blank && $digits === null) {
            return 'PT0S';
        }

        $sign = $this->sign;
        $prefix = $sign === -1 ? '-' : '';
        $abs = $this->abs();

        // Compute whole seconds and sub-second nanoseconds from the abs() fields.
        // Use fmod() for the remainder so that large float values (> PHP_INT_MAX) are handled
        // correctly — PHP's % operator converts floats to int via truncation, which overflows
        // for values like 4.5e21 µs. fmod() follows IEEE 754 and gives the exact remainder.
        // Carry = (v - fmod(v, divisor)) / divisor avoids the rounding-up error from v/divisor.
        $remMs = (int) fmod(num1: (float) $abs->milliseconds, num2: 1_000.0);
        $carryMs = (int) (((float) $abs->milliseconds - (float) $remMs) / 1_000.0);
        $remUs = (int) fmod(num1: (float) $abs->microseconds, num2: 1_000_000.0);
        $carryUs = (int) (((float) $abs->microseconds - (float) $remUs) / 1_000_000.0);
        $remNs = (int) fmod(num1: (float) $abs->nanoseconds, num2: 1_000_000_000.0);
        $carryNs = (int) (((float) $abs->nanoseconds - (float) $remNs) / 1_000_000_000.0);

        $subNs = ($remMs * 1_000_000) + ($remUs * 1_000) + $remNs;
        $totalSeconds = (int) $abs->seconds + $carryMs + $carryUs + $carryNs + (int) ($subNs / 1_000_000_000);
        $subNs %= 1_000_000_000;

        // Initialize local copies of time units that may be updated by carry after rounding.
        $absMinutes = (int) $abs->minutes;
        $absHours = (int) $abs->hours;
        $absDays = (int) $abs->days;

        // Apply rounding and format the fractional seconds string.
        if ($digits === null) {
            // auto: retain only significant digits.
            $frac = $subNs !== 0 ? sprintf('.%s', rtrim(sprintf('%09d', $subNs), characters: '0')) : '';
        } else {
            // Exact digit count with rounding.
            [$roundedFrac, $carrySecond] = self::roundSubSecond($subNs, $digits, $roundingMode, $sign);
            $totalSeconds += $carrySecond;

            // Range check: rounding might push totalSeconds beyond TC39's limit (2^53).
            $MAX_SAFE_F = 9_007_199_254_740_992.0;
            $totalSec =
                ((float) $abs->days * 86_400.0)
                + ((float) $abs->hours * 3_600.0)
                + ((float) $abs->minutes * 60.0)
                + (float) $totalSeconds;
            if ($totalSec >= $MAX_SAFE_F) {
                throw new RangeError('Duration total seconds exceed the maximum representable range after rounding.');
            }

            // Carry seconds → minutes → hours → days, but only into originally-non-zero larger units.
            // E.g. {h:1, min:59, sec:59, ms:900} rounds to PT2H0S; {sec:59, ms:900} stays PT60S.
            if ($carrySecond !== 0 && ($absMinutes !== 0 || $absHours !== 0)) {
                $absMinutes += intdiv(num1: $totalSeconds, num2: 60);
                $totalSeconds %= 60;
                if ($absMinutes >= 60 && $absHours !== 0) {
                    $absHours += intdiv(num1: $absMinutes, num2: 60);
                    $absMinutes %= 60;
                    if ($absHours >= 24 && $absDays !== 0) {
                        $absDays += intdiv(num1: $absHours, num2: 24);
                        $absHours %= 24;
                    }
                }
            }

            $frac = $digits === 0 ? '' : sprintf(sprintf('.%%0%dd', $digits), $roundedFrac);
        }

        $s = sprintf('%sP', $prefix);

        if ($abs->years !== 0) {
            $s .= sprintf('%dY', $abs->years);
        }
        if ($abs->months !== 0) {
            $s .= sprintf('%dM', $abs->months);
        }
        if ($abs->weeks !== 0) {
            $s .= sprintf('%dW', $abs->weeks);
        }
        if ($absDays !== 0) {
            $s .= sprintf('%dD', $absDays);
        }

        // With a fixed digit count we always emit the time component (even if zero).
        $hasTime = $digits !== null || $absHours !== 0 || $absMinutes !== 0 || $totalSeconds !== 0 || $subNs !== 0;

        if ($hasTime) {
            $s .= 'T';
            if ($absHours !== 0) {
                $s .= sprintf('%dH', $absHours);
            }
            if ($absMinutes !== 0) {
                $s .= sprintf('%dM', $absMinutes);
            }
            // In fixed-digit mode always emit seconds; in auto mode emit only when non-zero.
            if ($digits !== null || $totalSeconds !== 0 || $subNs !== 0) {
                $s .= sprintf('%s%sS', $totalSeconds, $frac);
            }
        }

        return $s;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * @psalm-suppress UnusedParam toJSON ignores its argument per TC39 spec
     * @psalm-api
     */
    public function toJSON(mixed $options = null): string
    {
        return $this->toString();
    }

    /**
     * Returns a locale-sensitive string for this Duration.
     *
     * PHP has no ICU Temporal support, so this falls back to toString().
     * The TC39 spec permits implementations to choose locale behavior.
     *
     * @param string|array<array-key, mixed>|null $locales BCP 47 locale string or array (ignored in PHP).
     * @param array<array-key, mixed>|object $options Intl.DateTimeFormat options bag (ignored in PHP).
     * @psalm-suppress UnusedParam
     * @psalm-api
     */
    public function toLocaleString(string|array|null $locales = null, array|object|null $options = null): string
    {
        return $this->toString();
    }

    /**
     * Returns the total of this duration as a number in the given unit.
     *
     * Returns an int when the result is a whole number, float otherwise.
     *
     * Calendar units (years, months, weeks) and calendar-based target units require a
     * relativeTo option (PlainDate, ZonedDateTime, ISO string, or property-bag array);
     * invalid bags throw TypeError, absent required options throw RangeError.
     *
     * @param string|array<array-key, mixed>|object $totalOf Unit string or options bag with 'unit' key.
     * @return int|float
     * @throws RangeError if the unit is invalid or unavailable without relativeTo.
     * @throws \TypeError if $totalOf is not a string or array, or if relativeTo is an invalid bag.
     * @psalm-api
     */
    public function total(string|array|object $totalOf): int|float
    {
        // A string totalOf is the smallestUnit shorthand; an array/object is an options
        // bag normalized via GetOptionsObject (a Symbol sentinel object => TypeError).
        // TC39: if totalOf is undefined, throw TypeError (required arg).
        if (!is_string($totalOf)) {
            if (is_object($totalOf) && $totalOf instanceof \Stringable) {
                $str = (string) $totalOf; // JsSymbol: throws; JsUndefined: returns 'undefined'
                if ($str === 'undefined') {
                    throw new TypeError('Duration::total() requires a non-undefined options argument.');
                }
            }
            $totalOf = Options::requireObject($totalOf, ['relativeTo', 'unit']);
        }

        if (is_array($totalOf)) {
            /** @var mixed $u */
            $u = $totalOf['unit'] ?? '';
            // A present, non-null unit is a string-typed option: ToString-coerce a
            // Stringable (JsSymbol throws TypeError), reject other types via RangeError.
            $unit = $u === null ? '' : Options::coerceEnumOption($u, 'Unit');
        } else {
            $unit = $totalOf;
        }
        $unit = Options::normalizeUnit($unit);

        return DurationTotal::compute($this, $unit, $totalOf);
    }

    /**
     * Returns the sum of this duration and another.
     *
     * Both durations must be free of calendar fields (years/months/weeks). The
     * result is balanced: sub-second carries are propagated upward to the largest
     * unit present in either operand. Uses integer arithmetic for exact results.
     *
     * @param self|string|array<array-key, mixed>|object $other Duration, ISO 8601 string, or property-bag array.
     * @throws RangeError if either duration has calendar fields or the result is out of range.
     * @throws \TypeError if $other is not a Duration, string, or array.
     */
    public function add(string|array|object $other): self
    {
        $other = self::from($other);

        if (
            $this->years !== 0
            || $this->months !== 0
            || $this->weeks !== 0
            || $other->years !== 0
            || $other->months !== 0
            || $other->weeks !== 0
        ) {
            throw new RangeError('add() with years, months, or weeks requires a relativeTo option.');
        }

        // Determine the largest unit present in either duration.
        $rank = 0;
        if ($this->days !== 0 || $other->days !== 0) {
            $rank = 6;
        } elseif ($this->hours !== 0 || $other->hours !== 0) {
            $rank = 5;
        } elseif ($this->minutes !== 0 || $other->minutes !== 0) {
            $rank = 4;
        } elseif ($this->seconds !== 0 || $other->seconds !== 0) {
            $rank = 3;
        } elseif ($this->milliseconds !== 0 || $other->milliseconds !== 0) {
            $rank = 2;
        } elseif ($this->microseconds !== 0 || $other->microseconds !== 0) {
            $rank = 1;
        }

        // Sum each field. PHP promotes int+int to float on overflow; tdivmod handles both.
        /** @psalm-suppress InvalidOperand */
        $d = $this->days + $other->days;
        /** @psalm-suppress InvalidOperand */
        $h = $this->hours + $other->hours;
        /** @psalm-suppress InvalidOperand */
        $min = $this->minutes + $other->minutes;
        /** @psalm-suppress InvalidOperand */
        $s = $this->seconds + $other->seconds;
        /** @psalm-suppress InvalidOperand */
        $ms = $this->milliseconds + $other->milliseconds;
        /** @psalm-suppress InvalidOperand */
        $us = $this->microseconds + $other->microseconds;
        /** @psalm-suppress InvalidOperand */
        $ns = $this->nanoseconds + $other->nanoseconds;

        return self::balanceTimeFields($d, $h, $min, $s, $ms, $us, $ns, $rank);
    }

    /**
     * Returns the difference of this duration and another (equivalent to adding the negation).
     *
     * @param self|string|array<array-key, mixed>|object $other Duration, ISO 8601 string, or property-bag array.
     * @throws RangeError if either duration has calendar fields.
     * @throws \TypeError if $other is not a Duration, string, or array.
     * @psalm-api
     */
    public function subtract(string|array|object $other): self
    {
        return $this->add(self::from($other)->negated());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Distributes a decimal fraction of a time unit into smaller units.
     *
     * Uses float64 arithmetic (same precision as JS) to match TC39 test262 expected values.
     *
     * @param string $fracDigits Fractional digits without the decimal point (e.g. "5" for 0.5 hours)
     * @param int    $nsPerUnit  Nanoseconds per whole unit (3_600_000_000_000 for hours, 60_000_000_000 for minutes)
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int} [extra_minutes, seconds, milliseconds, microseconds, nanoseconds]
     */
    private static function distributeFracNs(string $fracDigits, int $nsPerUnit): array
    {
        $asStr = sprintf('0.%s', $fracDigits);
        $asFloat = is_numeric($asStr) ? (float) $asStr : 0.0;
        $totalFracNs = (int) round($asFloat * (float) $nsPerUnit);

        $dm = intdiv(num1: $totalFracNs, num2: 60_000_000_000);
        $rem = $totalFracNs % 60_000_000_000;
        $ds = intdiv(num1: $rem, num2: 1_000_000_000);
        $rem %= 1_000_000_000;
        $dms = intdiv(num1: $rem, num2: 1_000_000);
        $rem %= 1_000_000;
        $dus = intdiv(num1: $rem, num2: 1_000);
        $dns = $rem % 1_000;

        return [$dm, $ds, $dms, $dus, $dns];
    }

    /**
     * Parses a property-bag array into a Duration.
     *
     * TC39 ToTemporalPartialDurationRecord semantics:
     *  - At least one recognized plural field required (TypeError if none).
     *  - Each provided numeric value must be a finite, integer-valued number
     *    (RangeError if not).
     *
     * @param array<array-key, mixed> $item
     */
    private static function parseDurationLike(array $item): self
    {
        $hasAny = false;
        foreach (self::PLURAL_FIELDS as $f) {
            if (!array_key_exists($f, $item)) {
                continue;
            }

            $hasAny = true;
            break;
        }
        if (!$hasAny) {
            throw new TypeError(
                'Duration property bag must contain at least one recognized field (years, months, weeks, days, hours, minutes, seconds, milliseconds, microseconds, nanoseconds).',
            );
        }

        // Validate and extract each field.
        $values = [];
        foreach (self::PLURAL_FIELDS as $field) {
            /** @var mixed $v */
            $v = $item[$field] ?? 0;
            // Coerce per the universal numeric contract: a numeric value (int /
            // float / numeric string) is accepted; a Stringable is cast first
            // (a Symbol-like sentinel's __toString throws Calendrics\Exception\TypeError);
            // any other value — null, bool, array, plain object, non-numeric
            // string — is out of range and yields a RangeError. (Previously such
            // values were silently (int)-cast to 0 / kept the prior field value.)
            if ($v instanceof Stringable) {
                $v = (string) $v;
            }
            if (is_string($v)) {
                if (!is_numeric($v)) {
                    throw new RangeError("Duration field \"{$field}\" must be a finite integer.");
                }
                // Numeric string → number. Cast to float; the integer-value check
                // and the large-float guard below normalise it back to int when exact.
                $v = (float) $v;
            }
            if (!is_int($v) && !is_float($v)) {
                throw new RangeError("Duration field \"{$field}\" must be a finite integer.");
            }
            if (is_float($v)) {
                if (is_nan($v) || is_infinite($v)) {
                    throw new RangeError("Duration field \"{$field}\" must be a finite integer.");
                }
                if (fmod(num1: $v, num2: 1.0) !== 0.0) {
                    throw new RangeError("Duration field \"{$field}\" must be an integer, got non-integer {$v}.");
                }
            }
            // Keep large floats (> PHP_INT_MAX) as float; cast the rest to int.
            // Values within int64 range are cast for exact integer semantics.
            $values[] = is_float($v) && abs($v) >= (float) PHP_INT_MAX ? $v : (int) $v;
        }

        return new self(...$values);
    }

    /**
     * Truncating integer division with remainder (modulo), handling both int and float.
     *
     * For int inputs uses PHP's intdiv/%. For float inputs (which arise when PHP
     * auto-promotes int+int overflow to float) uses (int) cast for truncation.
     * When the quotient exceeds PHP_INT_MAX (e.g. ns ≈ 1e25 / 1000 ≈ 1e22), returns
     * a float quotient — the range check in balanceTimeFields() will catch it.
     *
     * When |n| > PHP_INT_MAX but the quotient fits in int64, float64 division may
     * round the quotient incorrectly. In that case we use exact decimal long-division
     * via sprintf('%.0f'), which gives the exact integer string for large floats.
     *
     * @return array{0: int|float, 1: int} [quotient, remainder]
     */
    private static function tdivmod(int|float $n, int $divisor): array
    {
        if (is_int($n)) {
            return [intdiv($n, $divisor), $n % $divisor];
        }
        // Float path.
        $fq = $n / (float) $divisor;
        $floatMax = (float) PHP_INT_MAX;
        // Guard against int overflow when the quotient exceeds int64 range.
        if (abs($fq) >= $floatMax) {
            // Return float quotient; the remainder can still be extracted via fmod.
            return [$fq, (int) fmod($n, (float) $divisor)];
        }
        // When |n| itself exceeds int64 range, (int)($n/$divisor) can round the
        // quotient incorrectly (float64 loses ~19 decimal digits of precision).
        // Use exact string-based long-division: sprintf('%.0f') gives the exact
        // decimal representation of integer-valued floats.
        if (abs($n) >= $floatMax) {
            $sign = $n < 0.0 ? -1 : 1;
            $absStr = sprintf('%.0f', abs($n));
            $q = 0;
            $rem = 0;
            $len = strlen($absStr);
            for ($i = 0; $i < $len; $i++) {
                $rem = ($rem * 10) + (int) $absStr[$i];
                $q = ($q * 10) + intdiv($rem, $divisor);
                $rem %= $divisor;
            }
            return [$sign * $q, $sign * $rem];
        }
        $q = (int) $fq;
        $r = (int) round($n - ((float) $q * (float) $divisor));
        return [$q, $r];
    }

    /**
     * Balances a set of time field sums.
     *
     * Uses bottom-up integer carry (ns → µs → ms → s → min → h → days, stopping at `$rank`).
     * When the result has mixed signs (a cross-field borrow that integer carry cannot resolve),
     * falls back to float totalNs + top-down truncating distribution (TC39 BalanceTimeDuration).
     * Applies float64 rounding ((int)(float)) to each result field to match JS Number storage.
     *
     * @param int|float $d   Sum of days fields.
     * @param int|float $h   Sum of hours fields.
     * @param int|float $min Sum of minutes fields.
     * @param int|float $s   Sum of seconds fields.
     * @param int|float $ms  Sum of milliseconds fields.
     * @param int|float $us  Sum of microseconds fields.
     * @param int|float $ns  Sum of nanoseconds fields.
     * @param int       $rank  Largest unit rank (6=days, 5=hours, 4=minutes, 3=seconds, 2=ms, 1=µs, 0=ns).
     */
    private static function balanceTimeFields(
        int|float $d,
        int|float $h,
        int|float $min,
        int|float $s,
        int|float $ms,
        int|float $us,
        int|float $ns,
        int $rank,
    ): self {
        // Save originals for float fallback (used when integer carry leaves mixed signs).
        [$d0, $h0, $min0, $s0, $ms0, $us0, $ns0] = [$d, $h, $min, $s, $ms, $us, $ns];

        // Bottom-up integer carry.
        [$carryUs, $ns] = self::tdivmod($ns, 1_000);
        /** @psalm-suppress InvalidOperand */
        $us += $carryUs;
        if ($rank >= 2) {
            [$carryMs, $us] = self::tdivmod($us, 1_000);
            /** @psalm-suppress InvalidOperand */
            $ms += $carryMs;
        }
        if ($rank >= 3) {
            [$carryS, $ms] = self::tdivmod($ms, 1_000);
            /** @psalm-suppress InvalidOperand */
            $s += $carryS;
        }
        if ($rank >= 4) {
            [$carryMin, $s] = self::tdivmod($s, 60);
            /** @psalm-suppress InvalidOperand */
            $min += $carryMin;
        }
        if ($rank >= 5) {
            [$carryH, $min] = self::tdivmod($min, 60);
            /** @psalm-suppress InvalidOperand */
            $h += $carryH;
        }
        if ($rank >= 6) {
            [$carryD, $h] = self::tdivmod($h, 24);
            /** @psalm-suppress InvalidOperand */
            $d += $carryD;
        }

        // Detect mixed signs after integer carry.  Cross-field borrows (e.g. h=-1, min=+1)
        // are not resolved by bottom-up carry; the float path handles them correctly.
        $hasPos = false;
        $hasNeg = false;
        foreach ([$d, $h, $min, $s, $ms, $us, $ns] as $fv) {
            if ($fv > 0) {
                $hasPos = true;
            } elseif ($fv < 0) {
                $hasNeg = true;
            }
            if ($hasPos && $hasNeg) {
                break;
            }
        }

        $MAX_SAFE_F = 9_007_199_254_740_992.0;

        if ($hasPos && $hasNeg) {
            // Float totalNs + top-down truncating distribution (TC39 BalanceTimeDuration).
            if ($rank >= 6) {
                // Include days in the total.
                $totalNs =
                    ((float) $d0 * 86_400_000_000_000.0)
                    + ((float) $h0 * 3_600_000_000_000.0)
                    + ((float) $min0 * 60_000_000_000.0)
                    + ((float) $s0 * 1_000_000_000.0)
                    + ((float) $ms0 * 1_000_000.0)
                    + ((float) $us0 * 1_000.0)
                    + (float) $ns0;
                $d = (int) ($totalNs / 86_400_000_000_000.0);
                $totalNs -= (float) $d * 86_400_000_000_000.0;
            } else {
                $d = $d0; // Days unchanged when rank < 6.
                $totalNs =
                    ((float) $h0 * 3_600_000_000_000.0)
                    + ((float) $min0 * 60_000_000_000.0)
                    + ((float) $s0 * 1_000_000_000.0)
                    + ((float) $ms0 * 1_000_000.0)
                    + ((float) $us0 * 1_000.0)
                    + (float) $ns0;
            }

            $h = (int) ($totalNs / 3_600_000_000_000.0);
            $totalNs -= (float) $h * 3_600_000_000_000.0;
            $min = (int) ($totalNs / 60_000_000_000.0);
            $totalNs -= (float) $min * 60_000_000_000.0;
            $s = (int) ($totalNs / 1_000_000_000.0);
            $totalNs -= (float) $s * 1_000_000_000.0;
            $ms = (int) ($totalNs / 1_000_000.0);
            $totalNs -= (float) $ms * 1_000_000.0;
            $us = (int) ($totalNs / 1_000.0);
            $totalNs -= (float) $us * 1_000.0;
            $ns = (int) $totalNs;
        } else {
            // Same-sign path: apply float64 rounding to match JS Number field storage.
            // JS stores all numbers as float64; integer operations > 2^53 lose precision.
            // We must simulate this by converting int→float64→int even for PHP ints,
            // so that e.g. (9007199254740991 + 9007199254740990) = 18014398509481980 (float64)
            // rather than 18014398509481981 (exact PHP int).
            // Guard against overflow: values that don't fit in int64 remain as float.
            $floatMax = (float) PHP_INT_MAX;
            $roundF64 = static function (int|float $v) use ($floatMax): int|float {
                $fv = (float) $v;
                return abs($fv) < $floatMax ? (int) $fv : $fv;
            };
            $d = $roundF64($d);
            $h = $roundF64($h);
            $min = $roundF64($min);
            $s = $roundF64($s);
            $ms = $roundF64($ms);
            $us = $roundF64($us);
            $ns = $roundF64($ns);
        }

        // TC39 range check: total seconds must not exceed MAX_SAFE_INT.
        $totalSec = ((float) $d * 86_400.0) + ((float) $h * 3_600.0) + ((float) $min * 60.0) + (float) $s;
        if ($rank < 3) {
            $totalSec += ((float) $ms / 1_000.0) + ((float) $us / 1_000_000.0) + ((float) $ns / 1_000_000_000.0);
        }
        if (abs($totalSec) >= $MAX_SAFE_F) {
            throw new RangeError('Duration time fields exceed the maximum representable range.');
        }

        return new self(0, 0, 0, (int) $d, (int) $h, (int) $min, (int) $s, (int) $ms, (int) $us, (int) $ns);
    }

    /**
     * Rounds/truncates sub-second nanoseconds to the given number of decimal digits.
     *
     * For negative durations the rounding direction is inverted (floor ↔ expand).
     *
     * @param int    $subNs       Sub-second nanoseconds (0–999_999_999).
     * @param int    $digits      Number of fractional seconds digits (0–9).
     * @param string $roundingMode TC39 rounding mode name.
     * @param int    $sign        Duration sign (1 or -1; 0 treated as 1).
     * @return array{0: int, 1: int} [roundedFrac, carrySecond]
     *   $roundedFrac: the integer to format as $digits decimal digits (0 when $digits=0).
     *   $carrySecond: 0 or 1, to add to the whole-seconds total.
     */
    private static function roundSubSecond(int $subNs, int $digits, string $roundingMode, int $sign): array
    {
        if ($digits === 0) {
            $carry = self::applyRounding($subNs, 1_000_000_000, $roundingMode, 0, $sign);
            return [0, $carry];
        }

        $unitNs = (int) round(10 ** (9 - $digits));
        $quotient = intdiv(num1: $subNs, num2: $unitNs);
        $remainder = $subNs % $unitNs;
        $carry = self::applyRounding($remainder, $unitNs, $roundingMode, $quotient, $sign);
        $rounded = $quotient + $carry;

        $maxFrac = (int) round(10 ** $digits);
        if ($rounded >= $maxFrac) {
            return [0, 1]; // overflow into next second
        }
        return [$rounded, 0];
    }

    /**
     * Determines the increment (0 or 1) to add to the quotient when rounding.
     *
     * @param int    $remainder   Fractional part (0 ≤ remainder < $unitNs).
     * @param int    $unitNs      Size of the rounding unit in nanoseconds.
     * @param string $mode        TC39 rounding mode.
     * @param int    $quotient    Truncated quotient (used by halfEven).
     * @param int    $sign        Duration sign (1 or -1).
     * @return int<0, 1>
     */
    private static function applyRounding(int $remainder, int $unitNs, string $mode, int $quotient, int $sign): int
    {
        if ($remainder === 0) {
            return 0;
        }
        $positive = $sign >= 0;
        $doubled = $remainder * 2;
        // Half toward -∞: tie rounds up only for the negative branch.
        if ($mode === 'halfFloor') {
            if ($positive) {
                return $doubled > $unitNs ? 1 : 0;
            }
            return $doubled >= $unitNs ? 1 : 0;
        }
        // Half toward +∞: tie rounds up only for the positive branch.
        if ($mode === 'halfCeil') {
            if ($positive) {
                return $doubled >= $unitNs ? 1 : 0;
            }
            return $doubled > $unitNs ? 1 : 0;
        }
        return match ($mode) {
            // Toward zero
            'trunc' => 0,
            // Floor = toward -∞: expand for negative, trunc for positive.
            'floor' => $positive ? 0 : 1,
            // Ceil = toward +∞: expand for positive, trunc for negative.
            'ceil' => $positive ? 1 : 0,
            // Always away from zero.
            'expand' => 1,
            // Half away from zero (standard rounding).
            'halfExpand' => $doubled >= $unitNs ? 1 : 0,
            // Half toward zero.
            'halfTrunc' => $doubled > $unitNs ? 1 : 0,
            // Half to even.
            'halfEven' => self::halfEvenRound($remainder, $unitNs, $quotient),
            default => throw new RangeError("Unknown rounding mode \"{$mode}\"."),
        };
    }

    /**
     * Half-to-even (banker's rounding) helper.
     *
     * @param int $remainder 0 ≤ remainder < $unitNs.
     * @param int $unitNs    Size of the rounding unit.
     * @param int $quotient  Truncated quotient (to check parity).
     * @return int<0, 1>
     */
    private static function halfEvenRound(int $remainder, int $unitNs, int $quotient): int
    {
        $double = $remainder * 2;
        if ($double < $unitNs) {
            return 0;
        }
        if ($double > $unitNs) {
            return 1;
        }
        // Exactly half — round to even.
        return ($quotient % 2) !== 0 ? 1 : 0;
    }

    // -------------------------------------------------------------------------
    // compare() and round()
    // -------------------------------------------------------------------------

    /**
     * Compares two durations by total elapsed time.
     *
     * For time-only durations (no calendar fields): convert to nanoseconds and compare.
     * For calendar fields without relativeTo: throws RangeError.
     * For calendar fields with valid relativeTo: normalizes both sides via the relative
     * anchor (DST-aware when relativeTo is a ZonedDateTime with an IANA timezone).
     *
     * @param self|string|array<array-key, mixed>|object $one     Duration, ISO 8601 string, or property-bag array.
     * @param self|string|array<array-key, mixed>|object $two     Duration, ISO 8601 string, or property-bag array.
     * @param array<array-key, mixed>|object $options null or options array (may contain 'relativeTo').
     * @return int -1, 0, or 1.
     * @throws RangeError when calendar units are present without relativeTo.
     * @psalm-api
     */
    public static function compare(string|array|object $one, string|array|object $two, mixed $options = []): int
    {
        $opts = Options::requireObject($options, ['relativeTo']);

        $d1 = self::from($one);
        $d2 = self::from($two);

        $hasCalendar =
            $d1->years !== 0
            || $d1->months !== 0
            || $d1->weeks !== 0
            || $d2->years !== 0
            || $d2->months !== 0
            || $d2->weeks !== 0;

        // Always validate relativeTo before any early return (invalid values must throw).
        $relativeToProvided = RelativeTo::isPresent($opts);

        // TC39 §7.3.22: if both Duration records have identical internal slots, return 0.
        // This applies even for calendar durations (relativeTo is not required for identical inputs).
        // However, relativeTo is validated first so that invalid values still throw.
        if ($d1->equals($d2)) {
            return 0;
        }

        if ($hasCalendar && !$relativeToProvided) {
            throw new RangeError(
                'Duration::compare() with calendar units (years, months, or weeks) requires a relativeTo option.',
            );
        }
        if ($hasCalendar) {
            /** @var mixed $rt */
            $rt = $opts['relativeTo'] ?? null;
            $ns1 = AnchorMath::totalNsFromRelativeTo($d1, $rt);
            $ns2 = AnchorMath::totalNsFromRelativeTo($d2, $rt);
            return $ns1 <=> $ns2;
        }

        // When relativeTo is a ZDT with IANA timezone, compare using actual epoch offsets.
        /** @var mixed $rtForCompare */
        $rtForCompare = $opts['relativeTo'] ?? null;
        $zdtInfoCompare = $rtForCompare !== null ? RelativeTo::resolveZdt($rtForCompare) : null;

        if ($zdtInfoCompare !== null) {
            $epoch1 = AnchorMath::durationToEpochOffsetSec($d1, $zdtInfoCompare);
            $epoch2 = AnchorMath::durationToEpochOffsetSec($d2, $zdtInfoCompare);
            return $epoch1 <=> $epoch2;
        }

        // For a ZonedDateTime anchor in a UTC/fixed-offset zone (RelativeTo::resolveZdt() returns
        // null for those, so we never reach the DST-aware branch above), TC39 still anchors each
        // date-category duration to the ZDT epoch via AddZonedDateTime. When either operand has a
        // date-category largestUnit (non-zero days/weeks/months/years — calendar units are handled
        // earlier, so days is the live case here), the resulting target instant must stay within
        // the representable Temporal range (±8.64e12 s). Check both operands independently so the
        // call carrying the out-of-range duration throws regardless of argument order.
        if ($rtForCompare instanceof \Calendrics\Spec\ZonedDateTime) {
            // years/months/weeks are known zero here ($hasCalendar was false above), so the only
            // live date-category field is days.
            $d1IsDateCategory = $d1->days !== 0;
            $d2IsDateCategory = $d2->days !== 0;
            if ($d1IsDateCategory || $d2IsDateCategory) {
                [$rtTrueSec, $rtSubNs] = $rtForCompare->epochParts();
                foreach ([$d1, $d2] as $dCheck) {
                    if (RelativeTo::zdtTargetOutOfRange($rtTrueSec, $rtSubNs, $dCheck)) {
                        throw new RangeError(
                            'relativeTo ZonedDateTime is outside the representable range after applying duration.',
                        );
                    }
                }
            }
        }

        $s1 = $d1->sign;
        $s2 = $d2->sign;
        if ($s1 !== $s2) {
            return $s1 <=> $s2;
        }
        [$days1, $subNs1] = self::balanceToDayNs($d1);
        [$days2, $subNs2] = self::balanceToDayNs($d2);
        $cmp = ($days1 <=> $days2) !== 0 ? $days1 <=> $days2 : $subNs1 <=> $subNs2;
        return $s1 * $cmp;
    }

    /**
     * Returns absolute [days, subDayNs] for comparison purposes.
     * Works with absolute values of the time fields.
     *
     * @return array{0: int, 1: int}
     */
    private static function balanceToDayNs(self $d): array
    {
        $h = (int) abs((float) $d->hours);
        $m = (int) abs((float) $d->minutes);
        $s = (int) abs((float) $d->seconds);
        $ms = (int) abs((float) $d->milliseconds);
        $us = (int) abs((float) $d->microseconds);
        $ns = (int) abs((float) $d->nanoseconds);

        $us += intdiv(num1: $ns, num2: 1_000);
        $ns %= 1_000;
        $ms += intdiv(num1: $us, num2: 1_000);
        $us %= 1_000;
        $s += intdiv(num1: $ms, num2: 1_000);
        $ms %= 1_000;
        $m += intdiv(num1: $s, num2: 60);
        $s %= 60;
        $h += intdiv(num1: $m, num2: 60);
        $m %= 60;
        $days = (int) abs((float) $d->days) + intdiv(num1: $h, num2: 24);
        $h %= 24;

        $subNs =
            ($h * 3_600_000_000_000)
            + ($m * 60_000_000_000)
            + ($s * 1_000_000_000)
            + ($ms * 1_000_000)
            + ($us * 1_000)
            + $ns;

        return [$days, $subNs];
    }

    /**
     * Rounds this duration to the given unit/options.
     *
     * @param string|array<array-key, mixed>|object $roundTo string (smallestUnit) or options array.
     * @return self
     * @throws \TypeError if $roundTo is not a string or array.
     * @throws RangeError if options are invalid or calendar units are used without a relativeTo anchor.
     * @psalm-api
     */
    public function round(string|array|object $roundTo): self
    {
        return DurationRounding::round($this, $roundTo);
    }
}
