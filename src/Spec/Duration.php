<?php

declare(strict_types=1);

namespace Temporal\Spec;

use Stringable;
use Temporal\Exception\RangeError;
use Temporal\Exception\TypeError;
use Temporal\Spec\Internal\Calendar\CalendarFactory;
use Temporal\Spec\Internal\CalendarMath;
use Temporal\Spec\Internal\EpochLimits;
use Temporal\Spec\Internal\FieldBag;
use Temporal\Spec\Internal\Options;
use Temporal\Spec\Internal\TimeZoneHelper;

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
    /**
     * The calendar fields a `relativeTo` property bag is built from — a ZonedDateTime-
     * shaped anchor, so the full date-and-time set. `era`/`eraYear` are added by
     * {@see FieldBag} for calendars that have eras; `offset`/`timeZone` are non-calendar
     * fields, passed alongside.
     *
     * @var list<string>
     */
    private const array RELATIVE_TO_FIELDS = [
        'year',
        'month',
        'monthCode',
        'day',
        'hour',
        'minute',
        'second',
        'millisecond',
        'microsecond',
        'nanosecond',
    ];

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
        // exceed MaxTimeDuration = 2^53 × 10^9 - 1 nanoseconds.
        /** @infection-ignore-all */
        $secI = is_int($seconds) ? $seconds : (int) $seconds;
        if ($secI > 9_007_199_254_740_991 || $secI < -9_007_199_254_740_991) {
            throw new RangeError('Duration time fields exceed the maximum representable range.');
        }

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
            // At the exact boundary (effective seconds == MAX_SAFE_INT), the remaining
            // sub-second nanoseconds must be < 1 s to stay within MaxTimeDuration.
            if (abs($intSecFull) === 9_007_199_254_740_991) {
                $remNs = $nanoseconds - ($carryNs * 1_000);
                $remUs = $usEff - ($carryUs * 1_000);
                $remMs = $msEff - ($carryMs * 1_000);
                $remSubNs = ($remMs * 1_000_000) + ($remUs * 1_000) + $remNs;
                if (abs($remSubNs) >= 1_000_000_000) {
                    throw new RangeError('Duration time fields exceed the maximum representable range.');
                }
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
     * @param array<array-key, mixed>|object|null $options an array of options, or any object (treated as empty options bag).
     * @throws RangeError if options are invalid or rounding causes overflow.
     * @throws \TypeError if $options is an explicit null or a non-array, non-object scalar.
     */
    public function toString(array|object|null $options = []): string
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
     * @param array<array-key, mixed>|object|null $options Intl.DateTimeFormat options bag (ignored in PHP).
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
        $unit = self::normalizeUnit($unit);

        if ($unit === 'years' || $unit === 'months' || $unit === 'weeks') {
            [$rt, $zdtInfoCal] = $this->resolveRelativeToForTotal(
                $totalOf,
                "total() with unit \"{$unit}\" requires a relativeTo option.",
            );
            return $this->totalCalendar($unit, $rt, $zdtInfoCal);
        }

        if ($this->years !== 0 || $this->months !== 0 || $this->weeks !== 0) {
            [$rt, $zdtInfoCal] = $this->resolveRelativeToForTotal(
                $totalOf,
                'total() on a duration with years, months, or weeks requires a relativeTo option.',
            );
            return $this->totalCalendar($unit, $rt, $zdtInfoCal);
        }

        // Validate relativeTo if provided (even for pure-time unit computations).
        if (is_array($totalOf) && array_key_exists('relativeTo', $totalOf)) {
            /** @var mixed $rtRaw */
            $rtRaw = $totalOf['relativeTo'];
            if (is_string($rtRaw)) {
                $parsedRt = $this->parseRelativeToString($rtRaw);
                $rtIsZDT = $parsedRt['_isZDT'] === true;
                // For total('days') with ZDT in UTC/fixed-offset: local time must be midnight.
                // This ensures the start-of-next-day is within the representable instant range.
                // (IANA timezones skip this check because DST-aware total legitimately uses non-midnight.)
                if ($unit === 'days' && $rtIsZDT && $parsedRt['_localTimeSec'] !== 0) {
                    // Check if the timezone is IANA (not UTC/fixed-offset).
                    $tzBracket = null;
                    $_m = null;
                    if (preg_match('/\[([^\]=]+)\]\s*$/', $rtRaw, $_m) === 1) {
                        $tzBracket = $_m[1];
                    }
                    $isIanaTz =
                        $tzBracket !== null
                        && $tzBracket !== 'UTC'
                        && preg_match('/^[+\-]\d{2}:\d{2}$/', $tzBracket) !== 1;
                    if (!$isIanaTz) {
                        throw new RangeError("relativeTo ZonedDateTime for total('days') must be at local midnight.");
                    }
                }
                // For non-blank duration: check epoch overflow.
                if (!$this->blank) {
                    $rtTotalSec =
                        ((float) $this->days * 86_400.0)
                        + ((float) $this->hours * 3_600.0)
                        + ((float) $this->minutes * 60.0)
                        + (float) $this->seconds
                        + (
                            (
                                ((float) $this->milliseconds * 1_000_000.0)
                                + ((float) $this->microseconds * 1_000.0)
                                + (float) $this->nanoseconds
                            )
                            / 1_000_000_000.0
                        );
                    if ($rtIsZDT) {
                        if (
                            ((float) $parsedRt['_utcSec'] + $rtTotalSec) > EpochLimits::MAX_EPOCH_SECONDS
                            || ((float) $parsedRt['_utcSec'] + $rtTotalSec) < -EpochLimits::MAX_EPOCH_SECONDS
                        ) {
                            throw new RangeError(
                                'relativeTo ZonedDateTime is outside the representable range after applying duration.',
                            );
                        }
                    } else {
                        // PlainDate: epoch days must be within ±100 000 000.
                        if (abs((int) $parsedRt['_epochDays']) > 100_000_000) {
                            throw new RangeError(
                                'relativeTo PlainDate is outside the representable range after applying duration.',
                            );
                        }
                    }
                }
            } elseif ($rtRaw instanceof \Temporal\Spec\PlainDate) {
                // PlainDate objects are always valid for pure-time computations; no extra validation needed.
            } elseif ($rtRaw instanceof \Temporal\Spec\ZonedDateTime) {
                // ZonedDateTime objects are valid relativeTo values for pure-time computations.
                // For a non-blank duration, the target instant (anchor epoch + duration) must
                // stay within the representable Temporal range (±8.64e21 ns ≙ ±8.64e12 s).
                // Read the TRUE epoch seconds via epochParts() (sentinel-aware), not the clamped
                // public epochNanoseconds field.
                if (!$this->blank) {
                    [$rtTrueSec, $rtSubNs] = $rtRaw->epochParts();
                    if (self::zdtTargetOutOfRange($rtTrueSec, $rtSubNs, $this)) {
                        throw new RangeError(
                            'relativeTo ZonedDateTime is outside the representable range after applying duration.',
                        );
                    }
                }
            } elseif ($rtRaw !== null) {
                if (is_object($rtRaw)) {
                    $rtForVal = FieldBag::forCalendarType(
                        $rtRaw,
                        self::RELATIVE_TO_FIELDS,
                        ['offset', 'timeZone'],
                        'relativeTo',
                    );
                } elseif (is_array($rtRaw)) {
                    $rtForVal = $rtRaw;
                } else {
                    throw new TypeError('relativeTo must be a string or property bag array.');
                }
                self::validateRelativeToPropertyBag($rtForVal);
            }
        }

        // DST-aware computation when relativeTo is a ZDT with IANA timezone.
        /** @var mixed $rtForZdt */
        $rtForZdt = is_array($totalOf) ? $totalOf['relativeTo'] ?? null : null;
        $zdtInfo = $rtForZdt !== null ? self::resolveRelativeToZdt($rtForZdt) : null;

        if ($zdtInfo !== null) {
            // Time-only fields in seconds (sub-second precision preserved).
            $subNs =
                ((float) $this->milliseconds * 1_000_000.0)
                + ((float) $this->microseconds * 1_000.0)
                + (float) $this->nanoseconds;
            $timeOnlySec =
                ((float) $this->hours * 3_600.0)
                + ((float) $this->minutes * 60.0)
                + (float) $this->seconds
                + ($subNs / 1_000_000_000.0);

            $daysField = (int) $this->days;

            if ($unit === 'days') {
                // Convert days to actual epoch seconds, then add time seconds.
                $daysSec = self::zdtDaysToSec(
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $daysField,
                    $zdtInfo['epochSec'],
                );
                $totalSec = $daysSec + $timeOnlySec;
                // Count fractional days using actual day lengths, starting from the ZDT's real epoch.
                $result = self::zdtTotalDays(
                    $zdtInfo['epochSec'],
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $totalSec,
                );
                return self::toIntIfWhole($result);
            }

            // For hours/minutes/seconds/etc: convert days to actual seconds, then total.
            $daysSec = self::zdtDaysToSec(
                $zdtInfo['year'],
                $zdtInfo['month'],
                $zdtInfo['day'],
                $zdtInfo['hour'],
                $zdtInfo['minute'],
                $zdtInfo['second'],
                $zdtInfo['tzId'],
                $daysField,
                $zdtInfo['epochSec'],
            );
            $totalSec = $daysSec + $timeOnlySec;

            $result = match ($unit) {
                'hours' => $totalSec / 3_600.0,
                'minutes' => $totalSec / 60.0,
                'seconds' => $totalSec,
                'milliseconds' => $totalSec * 1_000.0,
                'microseconds' => $totalSec * 1_000_000.0,
                'nanoseconds' => $totalSec * 1_000_000_000.0,
            };
            return self::toIntIfWhole($result);
        }

        // Compute in seconds. Combine sub-second fields into nanoseconds first, then divide
        // by 1e9 once — this avoids accumulated float64 rounding error from separate divisions
        // (e.g. 2ms/1000 + 31µs/1e6 gives 0.0020310000000000003 instead of 0.002031).
        $subNs =
            ((float) $this->milliseconds * 1_000_000.0)
            + ((float) $this->microseconds * 1_000.0)
            + (float) $this->nanoseconds;
        $totalSec =
            ((float) $this->days * 86_400.0)
            + ((float) $this->hours * 3_600.0)
            + ((float) $this->minutes * 60.0)
            + (float) $this->seconds
            + ($subNs / 1_000_000_000.0);

        $result = match ($unit) {
            'days' => $totalSec / 86_400.0,
            'hours' => $totalSec / 3_600.0,
            'minutes' => $totalSec / 60.0,
            'seconds' => $totalSec,
            'milliseconds' => $totalSec * 1_000.0,
            'microseconds' => $totalSec * 1_000_000.0,
            'nanoseconds' => $totalSec * 1_000_000_000.0,
        };

        // Return int when the result is a whole number (matches JS behavior where
        // e.g. 24 hours total('hours') is 24, not 24.0).
        return self::toIntIfWhole($result);
    }

    /**
     * Tests whether anchoring the given duration to a ZonedDateTime epoch
     * (epochSec, subNs) lands outside the representable Temporal range
     * (±8.64e21 ns ≙ ±8.64e12 s). The comparison is done in (seconds, sub-ns)
     * integer space so that an over-int64 epoch plus a one-nanosecond duration
     * is detected exactly — float seconds would lose the +1 ns at this scale.
     *
     * Only the time-and-day fields contribute (calendar fields are handled on
     * the calendar paths before this is reached).
     */
    private static function zdtTargetOutOfRange(int $epochSec, int $subNs, self $d): bool
    {
        // Duration contribution as whole seconds + residual nanoseconds.
        // Day/hour/minute/second are whole-second contributions; ms/µs/ns are sub-second.
        $durSec =
            ((float) $d->days * 86_400.0)
            + ((float) $d->hours * 3_600.0)
            + ((float) $d->minutes * 60.0)
            + (float) $d->seconds;
        $durNs =
            ((float) $d->milliseconds * 1_000_000.0) + ((float) $d->microseconds * 1_000.0) + (float) $d->nanoseconds;

        // Fold whole seconds out of the nanosecond residual.
        $carrySec = floor($durNs / 1_000_000_000.0);
        $durSec += $carrySec;
        $residualNs = $durNs - ($carrySec * 1_000_000_000.0);

        // Target = (epochSec + durSec) seconds and (subNs + residualNs) sub-seconds.
        $targetSec = (float) $epochSec + $durSec;
        $targetSubNs = (float) $subNs + $residualNs;
        $carry = floor($targetSubNs / 1_000_000_000.0);
        $targetSec += $carry;
        $targetSubNs -= $carry * 1_000_000_000.0;

        // Out of range when target > (8.64e12 s, 0 ns) or < (-8.64e12 s, 0 ns).
        if ($targetSec > EpochLimits::MAX_EPOCH_SECONDS) {
            return true;
        }
        if ($targetSec === (float) EpochLimits::MAX_EPOCH_SECONDS && $targetSubNs > 0.0) {
            return true;
        }
        if ($targetSec < -EpochLimits::MAX_EPOCH_SECONDS) {
            return true;
        }
        if ($targetSec === -(float) EpochLimits::MAX_EPOCH_SECONDS && $targetSubNs < 0.0) {
            return true;
        }

        return false;
    }

    /**
     * Validates and resolves the `relativeTo` option for `Duration::total()`'s
     * calendar paths. Returns the property-bag form of the anchor (with `year`,
     * `month`/`monthCode`, `day` keys guaranteed) plus the ZonedDateTime info
     * record when the original input was a ZDT.
     *
     * @param mixed  $totalOf  the options bag passed to `total()` (string-form
     *     totalOf is invalid here — calendar units require an options object,
     *     never a bare smallestUnit string).
     * @param string $missingMsg  message for RangeError when the
     *     `relativeTo` key is absent. The TypeError thrown for an explicit null
     *     and the field-shape errors are spec-mandated and identical across
     *     calling sites.
     * @return array{0: array<array-key, mixed>, 1: array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int}|null}
     *     [resolvedBag, zdtInfo].
     * @throws RangeError if relativeTo is absent.
     * @throws \TypeError if relativeTo is null, not String/Object, or the
     *     resolved bag lacks year, month/monthCode, or day.
     */
    private function resolveRelativeToForTotal(mixed $totalOf, string $missingMsg): array
    {
        if (!is_array($totalOf) || !array_key_exists('relativeTo', $totalOf)) {
            throw new RangeError($missingMsg);
        }
        // Per TC39 GetTemporalRelativeToOption: present-but-null relativeTo is
        // not a String or Object → TypeError. (Distinct from the absent case,
        // which is RangeError above.) test262
        // Duration/prototype/total/does-not-accept-non-string-primitives-for-relativeTo
        // pins this distinction.
        if ($totalOf['relativeTo'] === null) {
            throw new TypeError('relativeTo must be a string, property bag, or Temporal date/datetime.');
        }
        /** @var mixed $rt */
        $rt = $totalOf['relativeTo'];
        // PlainDate and ZonedDateTime objects are valid relativeTo values; convert to property bag.
        if ($rt instanceof \Temporal\Spec\ZonedDateTime) {
            $rt = self::zdtToPlainDateBag($rt);
        } elseif ($rt instanceof \Temporal\Spec\PlainDate) {
            $rt = ['year' => $rt->isoYear, 'month' => $rt->isoMonth, 'day' => $rt->isoDay];
        } elseif (is_string($rt)) {
            $rt = $this->parseRelativeToString($rt);
        } else {
            if (is_object($rt)) {
                $rt = FieldBag::forCalendarType($rt, self::RELATIVE_TO_FIELDS, ['offset', 'timeZone'], 'relativeTo');
            }
            if (is_array($rt)) {
                self::validateRelativeToPropertyBag($rt);
            } else {
                throw new TypeError('relativeTo must be a string or property bag.');
            }
        }
        // Both 'month' and 'monthCode' are valid month specifiers per TC39.
        $hasYear = array_key_exists('year', $rt);
        $hasMonth = array_key_exists('month', $rt) || array_key_exists('monthCode', $rt);
        $hasDay = array_key_exists('day', $rt);
        if (!$hasYear || !$hasMonth || !$hasDay) {
            throw new TypeError('relativeTo property bag must have year, month/monthCode, and day fields.');
        }
        return [$rt, self::resolveRelativeToZdt($totalOf['relativeTo'])];
    }

    /**
     * Parses a relativeTo ISO date string into a property bag.
     * Validates format, calendar, bracket offsets, and ZonedDateTime/PlainDate range limits.
     *
     * ZonedDateTime strings (have both an inline Z/offset AND a timezone bracket):
     *   - Local date must be ≥ -271821-04-20 (epoch-days ≥ −100 000 000).
     *   - UTC instant must be at midnight (offsetSec must exactly cancel localTimeSec mod 86400).
     *   - UTC instant must be within ±8 640 000 000 000 seconds.
     *
     * PlainDate strings (no inline offset or no timezone bracket):
     *   - Date must be within [−271821-04-19, +275760-09-13] (epoch-days in [−100 000 001, +100 000 000]).
     *
     * @return array<string,int|bool> Bag with 'year', 'month', 'day', '_epochDays', '_isZDT', '_utcSec', '_localTimeSec'.
     * @throws RangeError for invalid or unsupported strings.
     */
    private function parseRelativeToString(string $s): array
    {
        if ($s === '') {
            throw new RangeError('relativeTo string must not be empty.');
        }
        // Fractional hours: T12.5 or fractional minutes: T12:34.5 are not allowed.
        if (preg_match('/T\d{2}\.\d/', $s) === 1 || preg_match('/T\d{2}:\d{2}\.\d{1,3}(?:Z|[+\-\[]|$)/i', $s) === 1) {
            throw new RangeError('relativeTo string must not have fractional hours or minutes.');
        }
        // Validate calendar annotation.
        $calMatch = null;
        if (preg_match('/\[u-ca=([^\]]+)\]/', $s, $calMatch) === 1) {
            if (!CalendarFactory::isKnownCalendar($calMatch[1])) {
                throw new RangeError("Unknown calendar \"{$calMatch[1]}\".");
            }
        }
        // Reject minus-zero extended year (-000000).
        if (preg_match('/^-0{6}(?:[^0-9]|$)/', $s) === 1) {
            throw new RangeError('Cannot use negative zero as extended year.');
        }

        // Detect inline Z/offset and timezone bracket annotation.
        $hasInlineOffset = preg_match('/T\d{2}:?\d{2}(?::?\d{2}(?:\.\d+)?)?([+\-]|Z)/i', $s) === 1;
        $hasTzBracket = preg_match('/\[(?!u-ca=)[^\]]+\]/', $s) === 1;

        // TC39: ToTemporalRelativeTo:
        // - Z + no bracket → invalid (must have a timezone bracket for ZonedDateTime).
        // - Numeric offset with VALID format (±HH:MM[:SS]) + no bracket → treat as PlainDate.
        // - Numeric offset with INVALID format + no bracket → throw.
        if ($hasInlineOffset && !$hasTzBracket) {
            $hasZOffset = preg_match('/T\d{2}:?\d{2}(?::?\d{2}(?:\.\d+)?)?Z(?!\s*\[)/i', $s) === 1;
            if ($hasZOffset) {
                throw new RangeError(
                    "relativeTo string \"{$s}\" has a UTC (Z) offset but no timezone bracket annotation.",
                );
            }
            // Numeric offset: validate that the offset format is ±HH:MM[:SS[.frac]] followed by
            // end-of-string, '[', or whitespace (not extra digits).  Invalid formats (e.g. +00:0000) must throw.
            $offMatch = null;
            if (
                preg_match(
                    '/T\d{2}:?\d{2}(?::?\d{2}(?:\.\d+)?)?([+\-]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)(?:\[|$)/i',
                    $s,
                    $offMatch,
                ) !== 1
            ) {
                throw new RangeError("relativeTo string \"{$s}\" has an invalid UTC offset format.");
            }
            // Valid numeric offset without bracket: treat as PlainDate (ignore the time+offset part).
            $hasInlineOffset = false;
        }

        // Validate the timezone bracket annotation.
        $bracketMatch = null;
        if (preg_match('/\[([^\]]+)\]/', $s, $bracketMatch) === 1 && !str_starts_with($bracketMatch[1], 'u-ca=')) {
            $bracket = $bracketMatch[1];
            // Sub-minute bracket offset (has seconds component): invalid.
            if (preg_match('/^[+\-]\d{2}:\d{2}:\d{2}/', $bracket) === 1) {
                throw new RangeError('relativeTo string must not have sub-minute offset in bracket annotation.');
            }
            // Bracket is a numeric UTC offset (±HH:MM or ±HHMM): must match the inline offset
            // UNLESS the inline offset is Z (UTC instant — any timezone bracket is allowed).
            $bOff = null;
            if (preg_match('/^([+\-])(\d{2}):?(\d{2})$/', $bracket, $bOff) === 1) {
                $bMin = ((int) $bOff[2] * 60) + (int) $bOff[3];
                $bMin = $bOff[1] === '-' ? -$bMin : $bMin;
                $iOff = null;
                if (preg_match('/T\d{2}:?\d{2}(?::?\d{2})?([+\-]\d{2}:?\d{2}|Z)/i', $s, $iOff) === 1) {
                    if ($iOff[1] === 'Z' || $iOff[1] === 'z') {
                        // Z inline offset: any bracket timezone is allowed (no matching required).
                    } else {
                        $iOffParts = null;
                        preg_match('/^([+\-])(\d{2}):?(\d{2})/', $iOff[1], $iOffParts);
                        /**
                         * @var array{non-falsy-string, '+'|'-', non-falsy-string, non-falsy-string} $iOffParts
                         */
                        $iMin = ((int) $iOffParts[2] * 60) + (int) $iOffParts[3];
                        $iMin = $iOffParts[1] === '-' ? -$iMin : $iMin;
                        if ($bMin !== $iMin) {
                            throw new RangeError('relativeTo string bracket offset does not match inline UTC offset.');
                        }
                    }
                }
            } elseif (strtoupper($bracket) === 'UTC') {
                $iOff = null;
                if (preg_match('/T\d{2}:?\d{2}(?::?\d{2})?([+\-]\d{2}:?\d{2}|Z)/i', $s, $iOff) === 1) {
                    if ($iOff[1] !== 'Z' && $iOff[1] !== 'z') {
                        $iOffParts = null;
                        preg_match('/^([+\-])(\d{2}):?(\d{2})/', $iOff[1], $iOffParts);
                        /** @var array{non-falsy-string, '+'|'-', non-falsy-string, non-falsy-string} $iOffParts */
                        $iMin = ((int) $iOffParts[2] * 60) + (int) $iOffParts[3];
                        if ($iMin !== 0) {
                            throw new RangeError('relativeTo string bracket offset does not match inline UTC offset.');
                        }
                    }
                }
            }
        }

        // Extract date part: ±YYYY-MM-DD or YYYYMMDD.
        $dateMatch = null;
        if (
            preg_match('/^([+\-]?\d{4,6})-(\d{2})-(\d{2})/', $s, $dateMatch) !== 1
            && preg_match('/^(\d{4})(\d{2})(\d{2})/', $s, $dateMatch) !== 1
        ) {
            throw new RangeError("Invalid relativeTo date string \"{$s}\".");
        }
        $year = (int) $dateMatch[1];
        $month = (int) $dateMatch[2];
        $day = (int) $dateMatch[3];

        // Compute the proleptic Gregorian epoch-day count.
        $epochDays = self::isoDateToEpochDays($year, $month, $day);

        // Defaults for the extended return metadata (set inside ZDT branch only).
        $localTimeSec = 0;
        $hasFracSec = false;
        $utcSec = 0;

        if ($hasInlineOffset) {
            // ZonedDateTime string: validate local date range.

            // Local date must be at or after -271821-04-20 (epochDays ≥ -100 000 000).
            if ($epochDays < -100_000_000) {
                throw new RangeError(
                    "relativeTo ZonedDateTime \"{$s}\" local date is before the minimum (-271821-04-20).",
                );
            }

            // Extract local time (hours, minutes, seconds) and detect sub-second fraction.
            $tm = null;
            if (preg_match('/T(\d{2}):?(\d{2})(?::?(\d{2})(\.\d+)?)?/i', $s, $tm) === 1) {
                $localTimeSec =
                    ((int) $tm[1] * 3_600) + ((int) $tm[2] * 60) + (array_key_exists(3, $tm) ? (int) $tm[3] : 0);
                // @phpstan-ignore notIdentical.alwaysTrue
                $hasFracSec = array_key_exists(4, $tm) && $tm[4] !== '';
            }

            // Extract the inline UTC offset in seconds.
            $offsetSec = 0;
            $iOff = null;
            if (preg_match('/T\d{2}:?\d{2}(?::?\d{2}(?:\.\d+)?)?([+\-]\d{2}:?\d{2}|Z)/i', $s, $iOff) === 1) {
                if ($iOff[1] !== 'Z' && $iOff[1] !== 'z') {
                    $offParts = null;
                    preg_match('/^([+\-])(\d{2}):?(\d{2})/', $iOff[1], $offParts);
                    /** @var array{non-falsy-string, '+'|'-', non-falsy-string, non-falsy-string} $offParts */
                    $offsetSec = ((int) $offParts[2] * 3_600) + ((int) $offParts[3] * 60);
                    if ($offParts[1] === '-') {
                        $offsetSec = -$offsetSec;
                    }
                }
            }

            // Sub-second fractional components are not allowed.
            if ($hasFracSec) {
                throw new RangeError("relativeTo ZonedDateTime \"{$s}\" has a sub-second component.");
            }

            // Compute UTC instant.
            $utcSec = ($epochDays * 86_400) + $localTimeSec - $offsetSec;

            // UTC instant must be within ±8 640 000 000 000 seconds.
            if ($utcSec > EpochLimits::MAX_EPOCH_SECONDS || $utcSec < -EpochLimits::MAX_EPOCH_SECONDS) {
                throw new RangeError(
                    "relativeTo ZonedDateTime \"{$s}\" UTC instant is outside the representable range.",
                );
            }
        } else {
            // PlainDate string: valid range is [-271821-04-19, +275760-09-13]
            // (epoch-days in [-100 000 001, +100 000 000]).
            if ($epochDays < -100_000_001 || $epochDays > 100_000_000) {
                throw new RangeError("relativeTo PlainDate \"{$s}\" is outside the representable range.");
            }
        }

        $isZDT = $hasInlineOffset;
        return [
            'year' => $year,
            'month' => $month,
            'day' => $day,
            '_epochDays' => $epochDays,
            '_isZDT' => $isZDT,
            '_utcSec' => $utcSec,
            '_localTimeSec' => $localTimeSec,
        ];
    }

    /**
     * Extracts the ISO year/month/day anchor from a validated relativeTo property
     * bag. The bag is guaranteed to carry 'year', 'month' or 'monthCode', and 'day'
     * (see {@see self::validateRelativeToPropertyBag()}); its numeric fields have
     * already been checked for Infinity/NaN, so each value only needs
     * ToIntegerWithTruncation — an int passes through unchanged, every other finite
     * numeric/coercible value goes through PHP's `(int)` cast, which truncates toward
     * zero exactly as the spec requires. When only 'monthCode' is present the month
     * number is the digits after the leading 'M' (a trailing leap-marker 'L', as in
     * "M05L", is dropped by the `(int)` cast).
     *
     * @param array<array-key,mixed> $bag
     * @return array{int, int, int} [year, month, day]
     */
    private static function resolveAnchorYmd(array $bag): array
    {
        $year = self::truncateToInteger($bag['year']);
        if (array_key_exists('month', $bag)) {
            $month = self::truncateToInteger($bag['month']);
        } else {
            /** @var mixed $monthCodeRaw */
            $monthCodeRaw = $bag['monthCode'];
            /** @phpstan-ignore cast.string */
            $month = (int) substr(string: is_string($monthCodeRaw) ? $monthCodeRaw : (string) $monthCodeRaw, offset: 1);
        }
        $day = self::truncateToInteger($bag['day']);
        return [$year, $month, $day];
    }

    /**
     * TC39 ToIntegerWithTruncation for an already-finiteness-validated property-bag
     * field: an int passes through unchanged, every other finite numeric/coercible
     * value goes through PHP's `(int)` cast, which truncates toward zero exactly as
     * the spec requires. Used only by {@see self::resolveAnchorYmd()}.
     */
    private static function truncateToInteger(mixed $value): int
    {
        /** @phpstan-ignore cast.int */
        return is_int($value) ? $value : (int) $value;
    }

    /**
     * Implements total() for calendar units (years/months/weeks) given an ISO PlainDate
     * relativeTo bag. Unknown keys in the bag are silently ignored per TC39.
     *
     * @param 'years'|'months'|'weeks'|'days'|'hours'|'minutes'|'seconds'|'milliseconds'|'microseconds'|'nanoseconds' $unit
     * @param array<array-key,mixed> $relativeTo Validated plain-date property bag.
     * @param null|array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int} $zdtInfo Optional ZDT info for DST-aware day lengths.
     */
    private function totalCalendar(string $unit, array $relativeTo, ?array $zdtInfo = null): int|float
    {
        [$year, $month, $day] = self::resolveAnchorYmd($relativeTo);

        $tz = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable('now', $tz)
            ->setDate($year, $month, $day)
            ->setTime(0, 0, 0);

        // Compute calendar days: apply years/months/weeks to get endDate, count days.
        // Use TC39-compliant clamped arithmetic to avoid PHP month-overflow (e.g. Jan 31 + 1M = Mar 2 in PHP).
        $calendarDateEnd = $start;
        $calSign = $this->sign;
        if ((int) $this->years !== 0) {
            $calendarDateEnd = self::addYearsClamped($calendarDateEnd, $calSign * abs((int) $this->years));
        }
        if ((int) $this->months !== 0) {
            $calendarDateEnd = self::addMonthsClamped($calendarDateEnd, $calSign * abs((int) $this->months));
        }
        if ((int) $this->weeks !== 0) {
            $aw = $calSign * abs((int) $this->weeks) * 7;
            $calendarDateEnd = $calendarDateEnd->modify(sprintf('%+d days', $aw));
        }
        $calendarDays = (int) $start->diff($calendarDateEnd)->format('%r%a');

        // Total days = calendar days from calendar fields + the 'days' field.
        $nsPerDay = 86_400_000_000_000;
        $daysField = (int) $this->days;
        $totalWholeDays = $calendarDays + $daysField;

        // Sub-day nanoseconds (hours..nanoseconds fields only).
        $fracNs =
            ((int) $this->hours * 3_600_000_000_000)
            + ((int) $this->minutes * 60_000_000_000)
            + ((int) $this->seconds * 1_000_000_000)
            + ((int) $this->milliseconds * 1_000_000)
            + ((int) $this->microseconds * 1_000)
            + (int) $this->nanoseconds;

        // Validate that the effective end (startDate + totalWholeDays + time) is within range.
        $startEpochDay = self::isoDateToEpochDays($year, $month, $day);
        $endEpochDay = $startEpochDay + $totalWholeDays;

        if (
            abs($endEpochDay) > 100_000_000
            || $endEpochDay === 100_000_000 && $fracNs > 0
            || $endEpochDay === -100_000_000 && $fracNs < 0
        ) {
            throw new RangeError('Duration with relativeTo exceeds the maximum representable date range.');
        }

        $fracDay = (float) $fracNs / (float) $nsPerDay;

        // For every unit (including years/months): validate that the total fractional days don't
        // exceed the maximum representable range (±100 000 000 days). This catches cases where
        // large time fields (e.g. seconds = 2^53 - 1) push the total far beyond the limit. TC39
        // AdjustDateDurationRecord (total §13.d) raises RangeError here; without this guard the
        // years/months paths reach totalCalendar{Years,Months}() with an overflowed float $fracNs
        // and throw a TypeError instead.
        $totalDaysF = (float) $totalWholeDays + $fracDay;
        if (abs($totalDaysF) > 100_000_000.0) {
            throw new RangeError('Duration with relativeTo exceeds the maximum representable date range.');
        }

        // For ZDT with IANA timezone: use DST-aware day lengths for days/hours/etc.
        if ($zdtInfo !== null && $unit !== 'months' && $unit !== 'years' && $unit !== 'weeks') {
            // Convert totalWholeDays + fracNs to actual epoch seconds using DST-aware arithmetic.
            $daysActualSec = self::zdtDaysToSec(
                $zdtInfo['year'],
                $zdtInfo['month'],
                $zdtInfo['day'],
                $zdtInfo['hour'],
                $zdtInfo['minute'],
                $zdtInfo['second'],
                $zdtInfo['tzId'],
                $totalWholeDays,
            );
            $timeOnlySec = (float) $fracNs / 1_000_000_000.0;
            $totalActualSec = $daysActualSec + $timeOnlySec;

            if ($unit === 'days') {
                $result = self::zdtTotalDays(
                    $zdtInfo['epochSec'],
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $totalActualSec,
                );
                return self::toIntIfWhole($result);
            }

            $result = match ($unit) {
                'hours' => $totalActualSec / 3_600.0,
                'minutes' => $totalActualSec / 60.0,
                'seconds' => $totalActualSec,
                'milliseconds' => $totalActualSec * 1_000.0,
                'microseconds' => $totalActualSec * 1_000_000.0,
                'nanoseconds' => $totalActualSec * 1_000_000_000.0,
            };
            return self::toIntIfWhole($result);
        }

        return match ($unit) {
            'months' => $this->totalCalendarMonths($start, $totalWholeDays, $fracNs, $nsPerDay, $zdtInfo),
            'years' => $this->totalCalendarYears($start, $totalWholeDays, $fracNs, $zdtInfo),
            // For weeks: use floor(days/7) + ((days%7 + fracDay)/7) to match TC39 test precision.
            // Non-associative float: (totalDays+fracDay)/7 ≠ floor(totalDays/7)+((rem+fracDay)/7).
            'weeks' => self::toIntIfWhole(
                (float) intdiv(num1: $totalWholeDays, num2: 7) + (((float) ($totalWholeDays % 7) + $fracDay) / 7.0),
            ),
            'days' => self::toIntIfWhole((float) $totalWholeDays + $fracDay),
            'hours' => self::toIntIfWhole(((float) $totalWholeDays * 24.0) + ((float) $fracNs / 3_600_000_000_000.0)),
            'minutes' => self::toIntIfWhole(((float) $totalWholeDays * 1_440.0) + ((float) $fracNs / 60_000_000_000.0)),
            'seconds' => self::toIntIfWhole(((float) $totalWholeDays * 86_400.0) + ((float) $fracNs / 1_000_000_000.0)),
            'milliseconds' => self::toIntIfWhole(((float) $totalWholeDays * 86_400_000.0)
            + ((float) $fracNs / 1_000_000.0)),
            'microseconds' => self::toIntIfWhole(((float) $totalWholeDays * 86_400_000_000.0)
            + ((float) $fracNs / 1_000.0)),
            'nanoseconds' => self::toIntIfWhole(((float) $totalWholeDays * 86_400_000_000_000.0) + (float) $fracNs),
        };
    }

    /**
     * Counts fractional months from $start spanning $wholeDays days + $fracNs nanoseconds.
     * Implements TC39 RoundDuration for unit = "months".
     *
     * @param null|array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int} $zdtInfo Optional ZDT info for DST-aware day lengths.
     */
    private function totalCalendarMonths(
        \DateTimeImmutable $start,
        int $wholeDays,
        int $fracNs,
        int $nsPerDay,
        ?array $zdtInfo = null,
    ): int|float {
        // Balance time (fracNs) into wholeDays so the month-counting loop crosses calendar boundaries
        // contained in the time portion (e.g. an until() result of N hours that spans a full month).
        $extraDays = intdiv(num1: $fracNs, num2: $nsPerDay);
        $wholeDays += $extraDays;
        $fracNs -= $extraDays * $nsPerDay;

        $absWholeDays = abs($wholeDays);
        $dir = $wholeDays >= 0 ? '+' : '-';
        $sign = $wholeDays >= 0 ? 1 : -1;
        $end = $start->modify("{$dir}{$absWholeDays} days");

        $months = 0;
        $current = $start;
        while (true) {
            $next = self::addMonthsClamped($current, $sign);
            if ($sign > 0 ? $next > $end : $next < $end) {
                break;
            }
            $months++;
            $current = $next;
        }

        $remainingDays = $current->diff($end)->days;
        // Use start-anchored r2 to match TC39 spec (daysUntil(r1, r2) where
        // r2 = start + (months+1) months, not current + 1 month).
        $r2 = self::addMonthsClamped($start, $sign * ($months + 1));
        // The r2 boundary may fall beyond the representable ISO date-time range
        // when the anchor sits near the limit; per TC39 RoundDuration this is a
        // RangeError.
        self::assertCalendarBoundaryInRange($r2);
        $daysInNextMonth = $current->diff($r2)->days;

        if ($zdtInfo !== null) {
            // DST-aware: compute the actual epoch seconds spanning from $current to $r2
            // to get the real time length of the fractional month period.
            $currentY = (int) $current->format('Y');
            $currentM = (int) $current->format('n');
            $currentD = (int) $current->format('j');
            $actualSpanSec = self::zdtDaysToSec(
                $currentY,
                $currentM,
                $currentD,
                $zdtInfo['hour'],
                $zdtInfo['minute'],
                $zdtInfo['second'],
                $zdtInfo['tzId'],
                $sign * $daysInNextMonth,
            );
            $actualRemainingSec = self::zdtDaysToSec(
                $currentY,
                $currentM,
                $currentD,
                $zdtInfo['hour'],
                $zdtInfo['minute'],
                $zdtInfo['second'],
                $zdtInfo['tzId'],
                $sign * $remainingDays,
            );
            // Work in seconds to avoid float precision loss from nanosecond conversion.
            $fracSec = (float) $fracNs / 1_000_000_000.0;
            $absSpan = abs($actualSpanSec);
            $progress = (abs($actualRemainingSec) + abs($fracSec)) / $absSpan;
            $result = (float) ($months * $sign) + ($sign > 0 ? $progress : -$progress);
            return self::toIntIfWhole($result);
        }

        $result =
            (float) ($months * $sign)
            + ((float) ($sign * $remainingDays) / (float) $daysInNextMonth)
            + ((float) ($sign * $fracNs) / ((float) $nsPerDay * (float) $daysInNextMonth));

        return self::toIntIfWhole($result);
    }

    /**
     * Counts fractional years from $start spanning $wholeDays days + $fracNs nanoseconds.
     * Implements TC39 RoundDuration for unit = "years".
     */
    /**
     * @param array{epochSec:int,subNs:int,tzId:string,year:int,month:int,day:int,hour:int,minute:int,second:int}|null $zdtInfo
     */
    private function totalCalendarYears(
        \DateTimeImmutable $start,
        int $wholeDays,
        int $fracNs,
        ?array $zdtInfo = null,
    ): int|float {
        unset($zdtInfo); // Not currently used; reserved for future ZDT-aware year total.
        // Balance time (fracNs) into wholeDays so the year-counting loop crosses calendar boundaries
        // contained in the time portion (e.g. an until() result of 13152 hours = 548 days that spans
        // a full year). Without this, time-only durations always report 0 whole years.
        $nsPerDay = 86_400_000_000_000;
        $extraDays = intdiv(num1: $fracNs, num2: $nsPerDay);
        $wholeDays += $extraDays;
        $fracNs -= $extraDays * $nsPerDay;

        $absWholeDays = abs($wholeDays);
        $dir = $wholeDays >= 0 ? '+' : '-';
        $sign = $wholeDays >= 0 ? 1 : -1;
        $end = $start->modify("{$dir}{$absWholeDays} days");

        $years = 0;
        $current = $start;
        while (true) {
            $next = self::addYearsClamped($current, $sign);
            if ($sign > 0 ? $next > $end : $next < $end) {
                break;
            }
            $years++;
            $current = $next;
        }

        $remainingDays = $current->diff($end)->days;
        // Use start-anchored r2 to match TC39 spec (daysUntil(r1, r2) where
        // r2 = start + (years+1) years, not current + 1 year).
        $r2 = self::addYearsClamped($start, $sign * ($years + 1));
        // The r2 boundary may fall beyond the representable ISO date-time range
        // when the anchor sits near the limit; per TC39 RoundDuration this is a
        // RangeError.
        self::assertCalendarBoundaryInRange($r2);
        $daysInNextYear = $current->diff($r2)->days;
        // Convert fracNs → ms → fracDays via two exact divisions.
        // Direct division fracNs / (nsPerDay * 365) loses precision (86400e9 * 365 > 2^53).
        // Dividing fracNs by 1e6 first (ns → ms) gives the same float64 as the JS test's
        // ms-level computation (fracMs / dayMs), avoiding the 1-ULP rounding difference.
        $fracDays = ((float) ($sign * $fracNs) / 1_000_000.0) / 86_400_000.0;
        // Compute fractional part first (matching TC39 test evaluation order):
        // test: $fractionalYear = $partialYearDays / 365 + ($fractionalDay / 365)
        // then: $fullYears + $fractionalYear
        // Float addition is non-associative: (a+b)+c ≠ a+(b+c) at this precision.
        $fracPart =
            ((float) ($sign * $remainingDays) / (float) $daysInNextYear) + ($fracDays / (float) $daysInNextYear);
        $result = (float) ($years * $sign) + $fracPart;

        return self::toIntIfWhole($result);
    }

    private static function toIntIfWhole(float $result): int|float
    {
        return fmod(num1: $result, num2: 1.0) === 0.0 ? (int) $result : $result;
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
            // (a Symbol-like sentinel's __toString throws Temporal\Exception\TypeError);
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
     * Normalises a singular or plural Temporal unit name to its canonical plural form.
     *
     * @return 'years'|'months'|'weeks'|'days'|'hours'|'minutes'|'seconds'|'milliseconds'|'microseconds'|'nanoseconds'
     * @throws RangeError for unknown unit names.
     */
    private static function normalizeUnit(string $unit): string
    {
        return match ($unit) {
            'year', 'years' => 'years',
            'month', 'months' => 'months',
            'week', 'weeks' => 'weeks',
            'day', 'days' => 'days',
            'hour', 'hours' => 'hours',
            'minute', 'minutes' => 'minutes',
            'second', 'seconds' => 'seconds',
            'millisecond', 'milliseconds' => 'milliseconds',
            'microsecond', 'microseconds' => 'microseconds',
            'nanosecond', 'nanoseconds' => 'nanoseconds',
            default => throw new RangeError("Unknown duration unit: \"{$unit}\"."),
        };
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
     * @param array<array-key, mixed>|object|null $options null or options array (may contain 'relativeTo').
     * @return int -1, 0, or 1.
     * @throws RangeError when calendar units are present without relativeTo.
     * @psalm-api
     */
    public static function compare(
        string|array|object $one,
        string|array|object $two,
        array|object|null $options = null,
    ): int {
        $opts = Options::normalizeOptions($options, ['relativeTo']);

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
        $relativeToProvided = self::extractRelativeTo($opts);

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
            $ns1 = $d1->totalNsFromRelativeTo($rt);
            $ns2 = $d2->totalNsFromRelativeTo($rt);
            return $ns1 <=> $ns2;
        }

        // When relativeTo is a ZDT with IANA timezone, compare using actual epoch offsets.
        /** @var mixed $rtForCompare */
        $rtForCompare = $opts['relativeTo'] ?? null;
        $zdtInfoCompare = $rtForCompare !== null ? self::resolveRelativeToZdt($rtForCompare) : null;

        if ($zdtInfoCompare !== null) {
            $epoch1 = self::durationToEpochOffsetSec($d1, $zdtInfoCompare);
            $epoch2 = self::durationToEpochOffsetSec($d2, $zdtInfoCompare);
            return $epoch1 <=> $epoch2;
        }

        // For a ZonedDateTime anchor in a UTC/fixed-offset zone (resolveRelativeToZdt() returns
        // null for those, so we never reach the DST-aware branch above), TC39 still anchors each
        // date-category duration to the ZDT epoch via AddZonedDateTime. When either operand has a
        // date-category largestUnit (non-zero days/weeks/months/years — calendar units are handled
        // earlier, so days is the live case here), the resulting target instant must stay within
        // the representable Temporal range (±8.64e12 s). Check both operands independently so the
        // call carrying the out-of-range duration throws regardless of argument order.
        if ($rtForCompare instanceof \Temporal\Spec\ZonedDateTime) {
            // years/months/weeks are known zero here ($hasCalendar was false above), so the only
            // live date-category field is days.
            $d1IsDateCategory = $d1->days !== 0;
            $d2IsDateCategory = $d2->days !== 0;
            if ($d1IsDateCategory || $d2IsDateCategory) {
                [$rtTrueSec, $rtSubNs] = $rtForCompare->epochParts();
                foreach ([$d1, $d2] as $dCheck) {
                    if (self::zdtTargetOutOfRange($rtTrueSec, $rtSubNs, $dCheck)) {
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
        if ($s1 === 0) {
            return 0;
        }
        [$days1, $subNs1] = self::balanceToDayNs($d1);
        [$days2, $subNs2] = self::balanceToDayNs($d2);
        $cmp = ($days1 <=> $days2) !== 0 ? $days1 <=> $days2 : $subNs1 <=> $subNs2;
        return $s1 * $cmp;
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
        if (is_string($roundTo)) {
            $roundTo = ['smallestUnit' => $roundTo];
        } else {
            // TC39: if roundTo is undefined, throw TypeError (required arg).
            // GetOptionsObject: a Symbol sentinel object => TypeError (via Stringable cast).
            // JsUndefined sentinel: Stringable returning 'undefined' → TypeError (undefined is not an Object).
            if (is_object($roundTo) && $roundTo instanceof \Stringable) {
                $str = (string) $roundTo; // JsSymbol: throws TypeError; JsUndefined: returns 'undefined'
                if ($str === 'undefined') {
                    throw new TypeError('Duration::round() requires a non-undefined options argument.');
                }
            }
            $roundTo = Options::requireObject($roundTo, [
                'largestUnit',
                'relativeTo',
                'roundingIncrement',
                'roundingMode',
                'smallestUnit',
            ]);
        }

        /** @var mixed $suRaw */
        $suRaw = $roundTo['smallestUnit'] ?? null;
        /** @var mixed $luRaw */
        $luRaw = $roundTo['largestUnit'] ?? null;
        /** @var mixed $rmRaw */
        $rmRaw = $roundTo['roundingMode'] ?? 'halfExpand';
        /** @var mixed $incRaw */
        $incRaw = $roundTo['roundingIncrement'] ?? 1;

        // Validate roundingIncrement. The universal coerce + finite + ≥1 core lives in
        // Options::roundingIncrement(); only Duration's operation-specific upper bound
        // stays here.
        $increment = Options::roundingIncrement($incRaw);
        if ($increment > 1_000_000_000) {
            throw new RangeError('roundingIncrement must not exceed 10^9.');
        }

        $roundingMode = $rmRaw === null
            ? 'halfExpand'
            : Options::roundingMode(Options::coerceEnumOption($rmRaw, 'roundingMode'));

        // At least one of smallestUnit or largestUnit must be provided.
        $suProvided = $suRaw !== null;
        $luProvided = $luRaw !== null;
        if (!$suProvided && !$luProvided) {
            throw new RangeError('Duration::round() requires at least one of smallestUnit or largestUnit.');
        }

        // Validate and normalize units.
        /** @var array<string,int> Unit index (0=nanosecond, 9=year). */
        static $UNIT_IDX = [
            'nanoseconds' => 0,
            'microseconds' => 1,
            'milliseconds' => 2,
            'seconds' => 3,
            'minutes' => 4,
            'hours' => 5,
            'days' => 6,
            'weeks' => 7,
            'months' => 8,
            'years' => 9,
        ];

        $suNorm = $suProvided ? self::normalizeUnit(Options::coerceEnumOption($suRaw, 'smallestUnit')) : null;
        $luIsAuto = !$luProvided || $luRaw === 'auto';
        $luNorm = $luIsAuto ? null : self::normalizeUnit(Options::coerceEnumOption($luRaw, 'largestUnit'));

        // Calendar smallestUnit or largestUnit require relativeTo.
        $suIsCalendar = $suNorm !== null && array_key_exists($suNorm, $UNIT_IDX) && $UNIT_IDX[$suNorm] >= 7;
        $luIsCalendar = $luNorm !== null && array_key_exists($luNorm, $UNIT_IDX) && $UNIT_IDX[$luNorm] >= 7;

        // Duration itself has calendar units.
        $durationHasCalendar = $this->years !== 0 || $this->months !== 0 || $this->weeks !== 0;

        $needsRelativeTo = $suIsCalendar || $luIsCalendar || $durationHasCalendar;

        $relativeToProvided = self::extractRelativeTo($roundTo);

        // Detect ZonedDateTime relativeTo for sub-day rounding behavior.
        // $roundTo is always an array at this point (strings/objects normalized above).
        /** @var mixed $rtRawForZdt */
        $rtRawForZdt = $roundTo['relativeTo'] ?? null;
        $zdtRelativeTo = $rtRawForZdt instanceof \Temporal\Spec\ZonedDateTime;
        $zdtInfoRound = $rtRawForZdt !== null ? self::resolveRelativeToZdt($rtRawForZdt) : null;

        if ($needsRelativeTo && !$relativeToProvided) {
            // Distinguish "key absent" (= JS undefined → RangeError) from "key
            // present with PHP null" (= JS null → TypeError per
            // GetTemporalRelativeToOption "If value is not a String or an Object,
            // throw a TypeError"). extractRelativeTo collapses both to false
            // for the unneeded path; only re-inspect here when the option matters.
            if (array_key_exists('relativeTo', $roundTo) && $roundTo['relativeTo'] === null) {
                throw new TypeError('relativeTo must be a string, property bag, or Temporal date/datetime.');
            }
            throw new RangeError(
                'Duration::round() with calendar units (years, months, weeks) requires a relativeTo option.',
            );
        }
        if ($needsRelativeTo) {
            /** @var mixed $rtRaw */
            $rtRaw = $roundTo['relativeTo'] ?? null;
            return $this->roundWithRelativeTo(
                $rtRaw,
                $suNorm,
                $luIsAuto,
                $luNorm,
                $increment,
                $roundingMode,
                $UNIT_IDX,
            );
        }

        // For pure-time rounds: validate relativeTo and check overflow for non-blank durations.
        if ($relativeToProvided && !$this->blank) {
            /** @var mixed $rtRaw */
            $rtRaw = $roundTo['relativeTo'] ?? null;
            if (is_string($rtRaw)) {
                $parsedRt = $this->parseRelativeToString($rtRaw);
                $rtIsZDT = $parsedRt['_isZDT'] === true;
                $rtTotalSec =
                    ((float) $this->days * 86_400.0)
                    + ((float) $this->hours * 3_600.0)
                    + ((float) $this->minutes * 60.0)
                    + (float) $this->seconds
                    + (
                        (
                            ((float) $this->milliseconds * 1_000_000.0)
                            + ((float) $this->microseconds * 1_000.0)
                            + (float) $this->nanoseconds
                        )
                        / 1_000_000_000.0
                    );
                if ($rtIsZDT) {
                    if (
                        ((float) $parsedRt['_utcSec'] + $rtTotalSec) > EpochLimits::MAX_EPOCH_SECONDS
                        || ((float) $parsedRt['_utcSec'] + $rtTotalSec) < -EpochLimits::MAX_EPOCH_SECONDS
                    ) {
                        throw new RangeError(
                            'relativeTo ZonedDateTime is outside the representable range after applying duration.',
                        );
                    }
                } else {
                    // PlainDate: epoch days must be within ±100 000 000.
                    if (abs((int) $parsedRt['_epochDays']) > 100_000_000) {
                        throw new RangeError(
                            'relativeTo PlainDate is outside the representable range after applying duration.',
                        );
                    }
                }
            }
        }

        // Default smallestUnit is 'nanoseconds'.
        $suIdx = $suNorm !== null ? $UNIT_IDX[$suNorm] : 0;

        // Resolve 'auto' largestUnit: largest non-zero time field (days..ns), or if all zero, use smallestUnit.
        if ($luIsAuto) {
            $luIdx = $this->autoLargestUnit($suIdx);
            // 'auto' must be at least as large as smallestUnit.
            if ($luIdx < $suIdx) {
                $luIdx = $suIdx;
            }
        } else {
            $luIdx = $UNIT_IDX[$luNorm ?? 'nanoseconds'];
        }

        // largestUnit must be >= smallestUnit.
        if ($luIdx < $suIdx) {
            throw new RangeError('largestUnit must be at least as large as smallestUnit.');
        }

        // Prevent undefined behavior from (int) cast on float Duration fields > PHP int64.
        // This can occur with very large float microseconds/nanoseconds values.
        foreach ([
            $this->days,
            $this->hours,
            $this->minutes,
            $this->seconds,
            $this->milliseconds,
            $this->microseconds,
            $this->nanoseconds,
        ] as $_field) {
            if (is_float($_field) && abs($_field) >= 9.223_372_036_854_776e18) {
                throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
            }
        }

        // Compute total absolute nanoseconds, balancing all sub-day fields first.
        $sign = $this->sign;
        $absNs = (int) abs((float) $this->nanoseconds);
        $absUs = (int) abs((float) $this->microseconds);
        $absMs = (int) abs((float) $this->milliseconds);
        $absS = (int) abs((float) $this->seconds);
        $absM = (int) abs((float) $this->minutes);
        $absH = (int) abs((float) $this->hours);
        $absD = (int) abs((float) $this->days);

        // Balance up to get exact integers.
        $absUs += intdiv(num1: $absNs, num2: 1_000);
        $absNs %= 1_000;
        $absMs += intdiv(num1: $absUs, num2: 1_000);
        $absUs %= 1_000;
        $absS += intdiv(num1: $absMs, num2: 1_000);
        $absMs %= 1_000;
        $absM += intdiv(num1: $absS, num2: 60);
        $absS %= 60;
        $absH += intdiv(num1: $absM, num2: 60);
        $absM %= 60;

        // Balance hours into days: DST-aware when ZDT IANA relativeTo is present.
        if ($zdtInfoRound !== null) {
            $timeOnlyNs =
                ($absH * 3_600_000_000_000)
                + ($absM * 60_000_000_000)
                + ($absS * 1_000_000_000)
                + ($absMs * 1_000_000)
                + ($absUs * 1_000)
                + $absNs;
            [$absD, $timeOnlyNs] = self::zdtBalanceTimeToDays(
                $zdtInfoRound['year'],
                $zdtInfoRound['month'],
                $zdtInfoRound['day'],
                $zdtInfoRound['hour'],
                $zdtInfoRound['minute'],
                $zdtInfoRound['second'],
                $zdtInfoRound['tzId'],
                $timeOnlyNs,
                $absD,
                $sign,
                $zdtInfoRound['epochSec'],
            );
            // Re-extract sub-fields from the balanced time nanoseconds.
            $absH = intdiv($timeOnlyNs, num2: 3_600_000_000_000);
            $timeOnlyNs -= $absH * 3_600_000_000_000;
            $absM = intdiv($timeOnlyNs, num2: 60_000_000_000);
            $timeOnlyNs -= $absM * 60_000_000_000;
            $absS = intdiv($timeOnlyNs, num2: 1_000_000_000);
            $timeOnlyNs -= $absS * 1_000_000_000;
            $absMs = intdiv($timeOnlyNs, num2: 1_000_000);
            $timeOnlyNs -= $absMs * 1_000_000;
            $absUs = intdiv($timeOnlyNs, num2: 1_000);
            $absNs = $timeOnlyNs - ($absUs * 1_000);
        } else {
            $absD += intdiv(num1: $absH, num2: 24);
            $absH %= 24;
        }

        // Compute totalNs, guarding against int64 overflow for large day counts.
        $subDayNs =
            ($absH * 3_600_000_000_000)
            + ($absM * 60_000_000_000)
            + ($absS * 1_000_000_000)
            + ($absMs * 1_000_000)
            + ($absUs * 1_000)
            + $absNs;

        // Validate: total seconds must not exceed MaxTimeDuration (MAX_SAFE_INT seconds).
        // Use float arithmetic to avoid int64 overflow in the check.
        $totalAbsSec =
            ((float) $absD * 86_400.0)
            + ((float) $absH * 3_600.0)
            + ((float) $absM * 60.0)
            + (float) $absS
            + ((float) $absMs / 1_000.0)
            + ((float) $absUs / 1_000_000.0)
            + ((float) $absNs / 1_000_000_000.0);
        if ($totalAbsSec > 9_007_199_254_740_992.0) {
            throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
        }

        // For ZonedDateTime relativeTo: the result instant must stay within ±8.64e21 ns
        // (the valid Temporal.Instant range). Check zdtEpoch ± duration in seconds.
        if ($rtRawForZdt instanceof \Temporal\Spec\ZonedDateTime) {
            // Use the true-parts accessor (not the clamped public epochNanoseconds field)
            // so the range guard reflects the real instant for over-int64 anchors.
            [$zdtTrueSec] = $rtRawForZdt->epochParts();
            $zdtEpochSec = (float) $zdtTrueSec;
            $zdtResultSec = $zdtEpochSec + ((float) $sign * $totalAbsSec);
            if ($zdtResultSec > EpochLimits::MAX_EPOCH_SECONDS || $zdtResultSec < -EpochLimits::MAX_EPOCH_SECONDS) {
                throw new RangeError(
                    'Duration with ZonedDateTime relativeTo would move the instant outside the valid range.',
                );
            }

            // Next-day boundary guard (mirrors TC39's hoursInDay / day-length computation).
            // When the largestUnit is days-or-coarser, RoundRelativeDuration must determine the
            // length of the current day, which requires AddZonedDateTime to find the start of the
            // NEXT day (and, on the negative edge, the start of the current/prior day). If that
            // boundary instant falls outside the representable Temporal range it must throw —
            // independent of the duration's magnitude (so this fires even for a blank duration,
            // unlike the magnitude-gated guard above).
            if ($luIdx >= 6) {
                // For UTC/fixed-offset zones (resolveRelativeToZdt() returns null) the day length
                // is a fixed 86_400 s, so the next-day start is the day-floored epoch + 86_400 s
                // and the day start is the day-floor itself. For IANA zones the resolved info
                // would route through the DST-aware path above; the fixed-offset edge is what the
                // over-int64 fixtures exercise here.
                $dayFloorSec = floor($zdtEpochSec / 86_400.0) * 86_400.0;
                $nextDayStartSec = $dayFloorSec + 86_400.0;
                if (
                    $nextDayStartSec > EpochLimits::MAX_EPOCH_SECONDS
                    || $dayFloorSec < -EpochLimits::MAX_EPOCH_SECONDS
                ) {
                    throw new RangeError(
                        'Duration with ZonedDateTime relativeTo: the day boundary falls outside the valid range.',
                    );
                }
            }
        }

        // Nanoseconds per unit (time units only; days and above handled separately).
        /** @var array<string,int> */
        static $NS_PER_UNIT = [
            'nanoseconds' => 1,
            'microseconds' => 1_000,
            'milliseconds' => 1_000_000,
            'seconds' => 1_000_000_000,
            'minutes' => 60_000_000_000,
            'hours' => 3_600_000_000_000,
        ];

        // Sub-day smallest unit: compute nanoseconds per increment and validate.
        // The 'days' case is handled separately below (early return) to avoid int64 overflow.
        $suNormResolved = $suNorm ?? 'nanoseconds';
        if ($suNormResolved !== 'days') {
            $nsPerSmallest = $NS_PER_UNIT[$suNormResolved] ?? 1;
            $nsIncrement = $nsPerSmallest * $increment;
        } else {
            $nsIncrement = 0; // placeholder; the 'days' path returns early below before using this.
        }

        // Validate increment: must be strictly less than the next-higher-unit count and divide it evenly.
        // Per TC39: e.g. minutes increment must be < 60 and divide 60 evenly.
        if ($suNormResolved !== 'days' && $suIdx < 6) {
            /** @var array<string,int> */
            static $MAX_PER_UNIT = [
                'nanoseconds' => 1_000,
                'microseconds' => 1_000,
                'milliseconds' => 1_000,
                'seconds' => 60,
                'minutes' => 60,
                'hours' => 24,
            ];
            $maxPerUnit = $MAX_PER_UNIT[$suNormResolved] ?? 1;
            if ($increment >= $maxPerUnit) {
                throw new RangeError("roundingIncrement {$increment} is too large for unit \"{$suNormResolved}\".");
            }
            if (($maxPerUnit % $increment) !== 0) {
                throw new RangeError(
                    "roundingIncrement {$increment} does not evenly divide into the next unit for \"{$suNormResolved}\".",
                );
            }
        }

        // ZDT sub-day rounding: for ZonedDateTime relativeTo with a time smallestUnit and
        // largestUnit >= days, keep whole days intact and round only the sub-day portion.
        // This differs from PlainDate behavior (which rounds the total nanoseconds).
        if (($zdtRelativeTo || $zdtInfoRound !== null) && $suNormResolved !== 'days' && $luIdx >= 6) {
            $roundedSubDayNs = self::roundNsPositive($subDayNs, $nsIncrement, $roundingMode);
            // If rounding carried the sub-day portion beyond one full day, add extra days.
            // Use DST-aware day length when available.
            if ($zdtInfoRound !== null) {
                [$absD, $roundedSubDayNs] = self::zdtBalanceTimeToDays(
                    $zdtInfoRound['year'],
                    $zdtInfoRound['month'],
                    $zdtInfoRound['day'],
                    $zdtInfoRound['hour'],
                    $zdtInfoRound['minute'],
                    $zdtInfoRound['second'],
                    $zdtInfoRound['tzId'],
                    $roundedSubDayNs,
                    $absD,
                    $sign,
                    $zdtInfoRound['epochSec'],
                );
            } else {
                $extraDays = intdiv(num1: $roundedSubDayNs, num2: 86_400_000_000_000);
                $roundedSubDayNs -= $extraDays * 86_400_000_000_000;
                $absD += $extraDays;
            }
            // Balance the sub-day ns to fields. When ZDT IANA is present, cap at hours
            // (luIdx=5) since days were already computed by zdtBalanceTimeToDays with
            // actual day lengths — re-balancing at 24h/day would produce incorrect results.
            // $luIdx is guaranteed >= 6 by the enclosing branch.
            $balanceIdx = $zdtInfoRound !== null ? 5 : $luIdx;
            [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::balanceNsToFields($roundedSubDayNs, $balanceIdx);
            /** @psalm-suppress InvalidOperand — balanceNsToFields returns int|float; $absD is int */
            $rDays += $absD;
            /** @psalm-suppress InvalidOperand — $sign (int) * int|float fields */
            return new self(
                0,
                0,
                0,
                $sign * $rDays,
                $sign * $rH,
                $sign * $rM,
                $sign * $rS,
                $sign * $rMs,
                $sign * $rUs,
                $sign * $rNs,
            );
        }

        // For 'days' smallest unit: work in day units to avoid int64 overflow for large increments.
        // roundingIncrement=1e9 would give nsIncrement=8.64e22 > PHP_INT_MAX, breaking integer math.
        // In the pure-time path largestUnit is always 'days' when smallestUnit='days'
        // (weeks/months/years require relativeTo → calendar path via roundWithRelativeTo).
        if ($suNormResolved === 'days') {
            // For ZDT IANA: use the actual day length for fractional day computation.
            if ($zdtInfoRound !== null) {
                $dayLenSec = abs(self::zdtDayLengthSec(
                    $zdtInfoRound['year'],
                    $zdtInfoRound['month'],
                    $zdtInfoRound['day'],
                    $zdtInfoRound['hour'],
                    $zdtInfoRound['minute'],
                    $zdtInfoRound['second'],
                    $zdtInfoRound['tzId'],
                ));
                // After DST-aware balancing, $absD days are consumed and $subDayNs remains.
                // Compute the fractional day using the actual day length at the current position.
                // Walk forward from the ZDT by $absD days to find the relevant day.
                if ($absD > 0) {
                    $baseDt = new \DateTimeImmutable(sprintf(
                        '%04d-%02d-%02d',
                        $zdtInfoRound['year'],
                        $zdtInfoRound['month'],
                        $zdtInfoRound['day'],
                    ));
                    $afterDays = $baseDt->modify(sprintf('%+d days', $sign * $absD));
                    $dayLenSec = abs(self::zdtDayLengthSec(
                        (int) $afterDays->format('Y'),
                        (int) $afterDays->format('n'),
                        (int) $afterDays->format('j'),
                        $zdtInfoRound['hour'],
                        $zdtInfoRound['minute'],
                        $zdtInfoRound['second'],
                        $zdtInfoRound['tzId'],
                    ));
                }
                $dayLenNs = (float) $dayLenSec * 1_000_000_000.0;
                $totalAbsDaysF = (float) $absD + ($dayLenNs > 0 ? (float) $subDayNs / $dayLenNs : 0.0);
            } else {
                $totalAbsDaysF = (float) $absD + ((float) $subDayNs / 86_400_000_000_000.0);
            }
            $roundedAbsDays = (int) self::roundNsFloat($totalAbsDaysF, (float) $increment, $roundingMode);
            if (((float) $roundedAbsDays * 86_400.0) >= 9_007_199_254_740_992.0) {
                throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
            }
            /** @psalm-suppress InvalidOperand */
            return new self(0, 0, 0, $sign * $roundedAbsDays, 0, 0, 0, 0, 0, 0);
        }

        // Compute totalNs as int when it fits in int64, float otherwise.
        // Safe threshold: 106_750 * 86_400_000_000_000 + 86_399_999_999_999 < PHP_INT_MAX.
        // Direct comparison (not a bool variable) lets Psalm narrow $absD's range inside the block.
        // For ZDT IANA: convert days to actual nanoseconds using DST-aware day lengths.
        if ($zdtInfoRound !== null && $absD > 0) {
            // Pass the ZDT's actual epoch so that sub-minute offsets (e.g. Pacific/Niue
            // -11:19:40 vs -11:20:00) are preserved instead of being re-resolved from
            // the wall time via compatible disambiguation.
            $actualDaysSec = (int) self::zdtDaysToSec(
                $zdtInfoRound['year'],
                $zdtInfoRound['month'],
                $zdtInfoRound['day'],
                $zdtInfoRound['hour'],
                $zdtInfoRound['minute'],
                $zdtInfoRound['second'],
                $zdtInfoRound['tzId'],
                $sign * $absD,
                $zdtInfoRound['epochSec'],
            );
            $dayNsActual = abs($actualDaysSec) * 1_000_000_000;
            if ($absD <= 106_750) {
                $totalNsInt = $dayNsActual + $subDayNs;
                $roundedNsInt = self::roundNsPositive($totalNsInt, $nsIncrement, $roundingMode);
                if (((float) $roundedNsInt / 1_000_000_000.0) >= 9_007_199_254_740_992.0) {
                    throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
                }
                [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::balanceNsToFields($roundedNsInt, $luIdx);
            } else {
                $totalNsFloat = (float) $dayNsActual + (float) $subDayNs;
                $roundedNsFloat = self::roundNsFloat($totalNsFloat, (float) $nsIncrement, $roundingMode);
                if (($roundedNsFloat / 1_000_000_000.0) >= 9_007_199_254_740_992.0) {
                    throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
                }
                [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::balanceNsToFields((int) $roundedNsFloat, $luIdx);
            }
            $signF = (float) $sign;
            return new self(
                0,
                0,
                0,
                $signF * (float) $rDays,
                $signF * (float) $rH,
                $signF * (float) $rM,
                $signF * (float) $rS,
                $signF * (float) $rMs,
                $signF * (float) $rUs,
                $signF * (float) $rNs,
            );
        }
        if ($absD <= 106_750) {
            $totalNsInt = ($absD * 86_400_000_000_000) + $subDayNs;
            // Round the total nanoseconds (int path).
            $roundedNsInt = self::roundNsPositive($totalNsInt, $nsIncrement, $roundingMode);
            // Validate rounded result is within MaxTimeDuration (MAX_SAFE_INT seconds).
            // MaxTimeDuration = 9_007_199_254_740_991 seconds + 999_999_999 ns.
            // 9_007_199_254_740_992 * 1e9 exceeds MaxTimeDuration, so use >=.
            if (((float) $roundedNsInt / 1_000_000_000.0) >= 9_007_199_254_740_992.0) {
                throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
            }
            // Balance the rounded ns into fields up to largestUnit.
            [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::balanceNsToFields($roundedNsInt, $luIdx);
        } else {
            // Float path: totalNs > PHP_INT_MAX.
            $totalNsFloat = ((float) $absD * 86_400_000_000_000.0) + (float) $subDayNs;
            $roundedNsFloat = self::roundNsFloat($totalNsFloat, (float) $nsIncrement, $roundingMode);
            // Validate rounded result.
            if (($roundedNsFloat / 1_000_000_000.0) >= 9_007_199_254_740_992.0) {
                throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
            }
            // When no rounding occurred (increment=1 or value was already aligned), use exact
            // integer field accumulation to avoid PHP x87 extended-precision errors in balance.
            if ($roundedNsFloat === $totalNsFloat) {
                // Boundary check for largestUnit=nanoseconds: PHP's float arithmetic may round
                // the total ns value DOWN where IEEE 754 requires rounding UP (ties-to-even).
                // This happens when totalSeconds=MAX_SAFE_INT and subNs >= 463_129_088.
                // The constant 463_129_088 = halfUlp(float64(MAX_SAFE_INT * 1e9)) − offset,
                // where offset = exact(MAX_SAFE_INT * 1e9) − float64(MAX_SAFE_INT * 1e9).
                // Derivation: float64(MAX_SAFE_INT * 1e9) = 9007199254740990926258176,
                // exact = 9007199254740991000000000, offset = 73741824,
                // halfUlp = 536870912, threshold = 536870912 − 73741824 = 463129088.
                if ($luIdx === 0) {
                    $totalSecondsExact = ($absD * 86_400) + ($absH * 3_600) + ($absM * 60) + $absS;
                    $subNsExact = ($absMs * 1_000_000) + ($absUs * 1_000) + $absNs;
                    if ($totalSecondsExact === 9_007_199_254_740_991 && $subNsExact >= 463_129_088) {
                        throw new RangeError(
                            'Duration time fields exceed the maximum representable range after rounding.',
                        );
                    }
                }
                [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::accumulateFieldsToUnit(
                    $absD,
                    $absH,
                    $absM,
                    $absS,
                    $absMs,
                    $absUs,
                    $absNs,
                    $luIdx,
                );
                // After accumulation, the top field may have overflowed int64 and been promoted
                // to float by PHP. When the float64-rounded value exceeds MaxTimeDuration, throw.
                // This catches cases like seconds=MAX_SAFE_INT + ms=488 with largestUnit=nanoseconds
                // where the nanoseconds field overflows int64 and rounds up past the limit.
                // The divisors convert the top-field unit back to seconds for comparison.
                $topField = match ($luIdx) {
                    0 => $rNs,
                    1 => $rUs,
                    2 => $rMs,
                    3 => $rS,
                    4 => $rM,
                    5 => $rH,
                    default => $rDays,
                };
                /** @var array<int,float> $TOP_UNIT_TO_NS */
                static $TOP_UNIT_TO_NS = [
                    1_000_000_000.0, // ns: divide by 1e9 to get seconds
                    1_000_000.0, // us: divide by 1e6
                    1_000.0, // ms: divide by 1e3
                    1.0, // s:  no conversion
                    1.0 / 60.0, // min: multiply by 60 → skip (cannot exceed in minutes alone)
                    1.0 / 3_600.0, // h
                    1.0 / 86_400.0, // day
                ];
                if (is_float($topField) && (abs($topField) / $TOP_UNIT_TO_NS[$luIdx]) >= 9_007_199_254_740_992.0) {
                    throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
                }
            } else {
                // Rounding occurred in float path. Attempt exact integer arithmetic at the
                // coarsest unit level that divides nsIncrement, to avoid float64 precision loss.
                // The spec uses BigInt internally; we simulate by working in a larger unit
                // (µs or ms) where the total fits in int64.
                $result = self::tryRoundExact(
                    $absD,
                    $absH,
                    $absM,
                    $absS,
                    $absMs,
                    $absUs,
                    $absNs,
                    $nsIncrement,
                    $roundingMode,
                    $luIdx,
                );
                if ($result !== null) {
                    [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = $result;
                } else {
                    [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::balanceNsFloatToFields($roundedNsFloat, $luIdx);
                }
            }
        }

        // Apply sign and return.
        /** @psalm-suppress InvalidOperand — $sign (int) * int|float fields */
        return new self(
            0,
            0,
            0,
            $sign * $rDays,
            $sign * $rH,
            $sign * $rM,
            $sign * $rS,
            $sign * $rMs,
            $sign * $rUs,
            $sign * $rNs,
        );
    }

    /**
     * Extracts and validates the relativeTo option from an options array/null.
     * Returns true if a non-null relativeTo was found (and is valid).
     *
     * @param array<array-key, mixed>|object|null $options
     * @throws RangeError for invalid relativeTo strings or property bags.
     * @throws \TypeError for invalid relativeTo types.
     */
    private static function extractRelativeTo(array|object|null $options): bool
    {
        if (is_object($options)) {
            $options = Options::bagSnapshot($options, ['relativeTo']);
        }
        if ($options === null || !array_key_exists('relativeTo', $options)) {
            return false;
        }
        /** @var mixed $rt */
        $rt = $options['relativeTo'];
        // PHP null is treated as the option being absent. test262 fixtures pass `null`
        // in `[null, plainRelativeTo, zonedRelativeTo]` parametric tables (for
        // non-calendar Durations) and expect the round to succeed — collapsing
        // PHP null to "absent" matches that. Genuinely-typed wrong-type fixtures
        // (relativeto-wrong-type) cover the same path: when the calling context
        // does require an anchor, the absent-relativeTo branch raises
        // RangeError ≡ JS RangeError.
        if ($rt === null) {
            return false;
        }
        if ($rt instanceof \Temporal\Spec\PlainDate || $rt instanceof \Temporal\Spec\ZonedDateTime) {
            return true; // PlainDate and ZonedDateTime objects are valid relativeTo values
        }
        if (is_string($rt)) {
            // Reuse the instance-method parser via a temporary instance.
            $dummy = new self(0);
            $dummy->parseRelativeToString($rt); // throws on invalid
            return true;
        }
        if (is_object($rt)) {
            $rt = FieldBag::forCalendarType($rt, self::RELATIVE_TO_FIELDS, ['offset', 'timeZone'], 'relativeTo');
        }
        if (is_array($rt)) {
            self::validateRelativeToPropertyBag($rt);
            return true;
        }
        throw new TypeError('relativeTo must be a string or property bag array.');
    }

    /**
     * Converts a relativeTo value (PlainDate, string, or array property bag) into
     * an array with integer 'year', 'month', 'day' keys.
     *
     * @param mixed $rt
     * @return array{year: int, month: int, day: int}
     */
    private function relativeToPlainDateBag(mixed $rt): array
    {
        if ($rt instanceof \Temporal\Spec\ZonedDateTime) {
            return self::zdtToPlainDateBag($rt);
        }
        if ($rt instanceof \Temporal\Spec\PlainDate) {
            return ['year' => $rt->isoYear, 'month' => $rt->isoMonth, 'day' => $rt->isoDay];
        }
        if (is_string($rt)) {
            $parsed = $this->parseRelativeToString($rt);
            return ['year' => (int) $parsed['year'], 'month' => (int) $parsed['month'], 'day' => (int) $parsed['day']];
        }
        // Property bag — normalize generic objects to arrays first.
        if (is_object($rt)) {
            $rt = FieldBag::forCalendarType($rt, self::RELATIVE_TO_FIELDS, ['offset', 'timeZone'], 'relativeTo');
        }
        assert(is_array($rt), description: 'non-string $rt must be a property-bag array at this point');
        [$year, $month, $day] = self::resolveAnchorYmd($rt);
        return ['year' => $year, 'month' => $month, 'day' => $day];
    }

    /**
     * Validates a relativeTo property bag.
     *
     * @param array<array-key,mixed> $rt
     * @throws \TypeError if required fields (year, month/monthCode, day) are missing.
     * @throws RangeError if calendar is not iso8601.
     */
    private static function validateRelativeToPropertyBag(array $rt): void
    {
        $hasYear = array_key_exists('year', $rt) || array_key_exists('era', $rt) && array_key_exists('eraYear', $rt);
        $hasMonth = array_key_exists('month', $rt) || array_key_exists('monthCode', $rt);
        $hasDay = array_key_exists('day', $rt);
        if (!$hasYear || !$hasMonth || !$hasDay) {
            throw new TypeError('relativeTo property bag must have year, month/monthCode, and day fields.');
        }
        // Validate Infinity/NaN in numeric fields.
        foreach ([
            'year',
            'eraYear',
            'month',
            'day',
            'hour',
            'minute',
            'second',
            'millisecond',
            'microsecond',
            'nanosecond',
        ] as $field) {
            if (!array_key_exists($field, $rt)) {
                continue;
            }
            /** @var mixed $v */
            $v = $rt[$field];
            if (is_float($v) && is_infinite($v)) {
                throw new RangeError("relativeTo field \"{$field}\" must be a finite number.");
            }
        }
        if (array_key_exists('calendar', $rt)) {
            // TC39 ToTemporalCalendarSlotValue: a calendar in a property bag may be:
            //   - A Temporal object with a calendarId slot (fast-path: read the slot directly).
            //   - A string or Stringable (coerce via ToString, then validate).
            //   - Any other type → TypeError.
            // Only an unknown calendar string is a RangeError (raised by canonicalize()).
            /** @var mixed $calVal */
            $calVal = $rt['calendar'];
            // Fast path: Temporal objects carry their calendar as a string slot.
            if (is_object($calVal) && property_exists($calVal, 'calendarId') && is_string($calVal->calendarId)) {
                $calVal = $calVal->calendarId;
            } elseif ($calVal instanceof \Stringable) {
                $calVal = (string) $calVal;
            }
            if (!is_string($calVal)) {
                throw new TypeError('relativeTo calendar must be a string.');
            }
            CalendarFactory::canonicalize($calVal);
        }
        // timeZone: if present must be a string; null or non-string → TypeError.
        if (array_key_exists('timeZone', $rt)) {
            /** @var mixed $tzVal */
            $tzVal = $rt['timeZone'];
            if (!is_string($tzVal)) {
                throw new TypeError('relativeTo timeZone must be a string.');
            }
            self::validateTimeZoneString($tzVal);
        }
        // offset: if present must be a string in ±HH:MM[[:SS[.nnnnnnnnn]]] format
        // where optional seconds and sub-seconds must be zero.
        if (array_key_exists('offset', $rt)) {
            /** @var mixed $offVal */
            $offVal = $rt['offset'];
            if (!is_string($offVal)) {
                throw new TypeError('relativeTo offset must be a string.');
            }
            // Allow ±HH:MM or ±HH:MM:00[.000...] (seconds and sub-seconds zero).
            $offM = null;
            if (preg_match('/^([+\-])(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?$/', $offVal, $offM) !== 1) {
                throw new RangeError("Invalid relativeTo offset string \"{$offVal}\".");
            }
            // Reject non-zero seconds or sub-seconds.
            if (array_key_exists(4, $offM) && (int) $offM[4] !== 0) {
                throw new RangeError("Invalid relativeTo offset string \"{$offVal}\": non-zero seconds.");
            }
            if (array_key_exists(5, $offM) && ltrim($offM[5], characters: '0') !== '') {
                throw new RangeError("Invalid relativeTo offset string \"{$offVal}\": non-zero sub-seconds.");
            }
        }
    }

    /**
     * Converts a ZonedDateTime to a year/month/day property bag.
     *
     * Uses the ZDT's epochNanoseconds and timezone offset to determine the local date.
     * Only UTC ("UTC", "Z") and fixed-offset timezones (±HH:MM) are supported.
     *
     * @return array{year: int, month: int, day: int}
     * @throws RangeError for IANA timezone names (not implemented).
     */
    private static function zdtToPlainDateBag(\Temporal\Spec\ZonedDateTime $zdt): array
    {
        // Compute local date from the TRUE epoch parts (sentinel-aware) + timezone
        // offset. Reading the clamped epochNanoseconds field would anchor over-int64
        // relativeTo instants at the year-2262 clamp instead of their real date.
        [$epochSec] = $zdt->epochParts();
        $tzId = $zdt->timeZoneId;
        $m = null;
        if ($tzId === 'UTC') {
            $offsetSec = 0;
        } elseif (preg_match('/^([+\-])(\d{2}):(\d{2})$/', $tzId, $m) === 1) {
            $sign = $m[1] === '+' ? 1 : -1;
            $offsetSec = $sign * (((int) $m[2] * 3600) + ((int) $m[3] * 60));
        } else {
            assert($tzId !== '', description: 'caller guarantees a non-empty timezone id for this branch');
            $tz = new \DateTimeZone($tzId);
            $offsetSec = $tz->getOffset(new \DateTimeImmutable(sprintf('@%d', $epochSec)));
        }
        $localSec = $epochSec + $offsetSec;
        $dt = new \DateTimeImmutable(sprintf('@%d', $localSec));
        return [
            'year' => (int) $dt->format('Y'),
            'month' => (int) $dt->format('n'),
            'day' => (int) $dt->format('j'),
        ];
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
     * Balances total absolute nanoseconds into time fields up to largestUnit.
     *
     * Field values that exceed 2^53 (Number.MAX_SAFE_INTEGER) are cast to float to
     * simulate JS's float64 storage behavior, matching spec-required precision loss.
     *
     * @param int $totalAbsNs Total non-negative nanoseconds.
     * @param int $largestUnitIdx Unit index (0=ns, 1=us, 2=ms, 3=s, 4=min, 5=h, 6=day).
     * @return array{0: int|float, 1: int|float, 2: int|float, 3: int|float, 4: int|float, 5: int|float, 6: int|float}
     */
    private static function balanceNsToFields(int $totalAbsNs, int $largestUnitIdx): array
    {
        $ns = $totalAbsNs % 1_000;
        $rem = intdiv(num1: $totalAbsNs, num2: 1_000);
        $us = $rem % 1_000;
        $rem = intdiv(num1: $rem, num2: 1_000);
        $ms = $rem % 1_000;
        $rem = intdiv(num1: $rem, num2: 1_000);
        $s = $rem % 60;
        $rem = intdiv(num1: $rem, num2: 60);
        $m = $rem % 60;
        $rem = intdiv(num1: $rem, num2: 60);
        $h = $rem % 24;
        $days = intdiv(num1: $rem, num2: 24);

        // Bubble excess upward when largestUnit is smaller than 'day' (idx 6).
        if ($largestUnitIdx < 6) {
            $h += $days * 24;
            $days = 0;
        }
        if ($largestUnitIdx < 5) {
            $m += $h * 60;
            $h = 0;
        }
        if ($largestUnitIdx < 4) {
            $s += $m * 60;
            $m = 0;
        }
        if ($largestUnitIdx < 3) {
            $ms += $s * 1_000;
            $s = 0;
        }
        if ($largestUnitIdx < 2) {
            $us += $ms * 1_000;
            $ms = 0;
        }
        if ($largestUnitIdx < 1) {
            $ns += $us * 1_000;
            $us = 0;
        }

        // Apply float64 rounding to field values that exceed 2^53 (MAX_SAFE_INTEGER).
        // JS stores Duration fields as float64; integers > 2^53 lose precision when stored.
        // We simulate this by casting to float, which PHP performs with float64 rounding.
        $floatMax = 9_007_199_254_740_992;
        /** @return int|float */
        $f64 = static function (int|float $v) use ($floatMax): int|float {
            if (is_float($v)) {
                return $v;
            }
            return $v >= $floatMax || $v <= -$floatMax ? (float) $v : $v;
        };

        return [$f64($days), $f64($h), $f64($m), $f64($s), $f64($ms), $f64($us), $f64($ns)];
    }

    /**
     * Rounds a non-negative nanosecond total to the given increment using the specified rounding mode.
     *
     * @param int    $ns        Non-negative nanoseconds.
     * @param int    $increment Rounding increment in nanoseconds (>= 1).
     * @param string $mode      TC39 rounding mode name.
     * @return int Rounded nanoseconds (a multiple of $increment).
     * @throws RangeError for unknown rounding modes.
     */
    private static function roundNsPositive(int $ns, int $increment, string $mode): int
    {
        $q = intdiv(num1: $ns, num2: $increment);
        $d1 = $ns - ($q * $increment); // remainder, >= 0
        $r2 = $q + 1;
        if ($mode === 'halfEven') {
            $cmp = $d1 * 2;
            if ($cmp < $increment) {
                $rounded = $q;
            } elseif ($cmp > $increment) {
                $rounded = $r2;
            } else {
                $rounded = ($q % 2) === 0 ? $q : $r2;
            }
        } else {
            $rounded = match ($mode) {
                'trunc', 'floor' => $q,
                'ceil', 'expand' => $d1 === 0 ? $q : $r2,
                'halfExpand', 'halfCeil' => ($d1 * 2) >= $increment ? $r2 : $q,
                'halfTrunc', 'halfFloor' => ($d1 * 2) > $increment ? $r2 : $q,
                default => throw new RangeError("Invalid roundingMode \"{$mode}\"."),
            };
        }
        return $rounded * $increment;
    }

    /**
     * Accumulates exact-integer time fields into a single target-unit representation.
     *
     * Takes already-balanced fields (each within its normal range: h<24, m<60, etc.)
     * and accumulates them upward to largestUnitIdx using exact integer arithmetic.
     * Field values that exceed 2^53 are cast to float64 to simulate JS number storage.
     *
     * @return array{0: int|float, 1: int|float, 2: int|float, 3: int|float, 4: int|float, 5: int|float, 6: int|float}
     */
    private static function accumulateFieldsToUnit(
        int $absD,
        int $absH,
        int $absM,
        int $absS,
        int $absMs,
        int $absUs,
        int $absNs,
        int $largestUnitIdx,
    ): array {
        $floatMax = 9_007_199_254_740_992;
        /** @return int|float */
        $f64 = static function (int|float $v) use ($floatMax): int|float {
            if (is_float($v)) {
                return $v;
            }
            return $v >= $floatMax || $v <= -$floatMax ? (float) $v : $v;
        };

        // Compute the result by distributing all fields into their positions relative to largestUnit.
        // For the top field (largestUnit), accumulate all coarser units into it.
        // For fields below largestUnit, keep the remainder within their normal range.

        // All intermediates fit in int64 for valid durations with absD <= MaxTimeDuration days
        // and fields within their normal ranges after balancing.

        // Nanosecond remainder.
        $ns = $absNs % 1_000;
        $carryUs = intdiv(num1: $absNs, num2: 1_000) + $absUs;

        // Microsecond level.
        $us = $carryUs % 1_000;
        $carryMs = intdiv(num1: $carryUs, num2: 1_000) + $absMs;

        // Millisecond level.
        $ms = $carryMs % 1_000;
        $carryS = intdiv(num1: $carryMs, num2: 1_000) + $absS;

        // Second level.
        $s = $carryS % 60;
        $carryM = intdiv(num1: $carryS, num2: 60) + $absM;

        // Minute level.
        $m = $carryM % 60;
        $carryH = intdiv(num1: $carryM, num2: 60) + $absH;

        // Hour level.
        $h = $carryH % 24;
        $days = intdiv(num1: $carryH, num2: 24) + $absD;

        // Now: days, h(0-23), m(0-59), s(0-59), ms(0-999), us(0-999), ns(0-999).
        // Bubble up: if largestUnit is smaller than 'day', fold days into h, etc.
        if ($largestUnitIdx < 6) {
            $h += $days * 24;
            $days = 0;
        }
        if ($largestUnitIdx < 5) {
            $m += $h * 60;
            $h = 0;
        }
        if ($largestUnitIdx < 4) {
            $s += $m * 60;
            $m = 0;
        }
        if ($largestUnitIdx < 3) {
            $ms += $s * 1_000;
            $s = 0;
        }
        if ($largestUnitIdx < 2) {
            $us += $ms * 1_000;
            $ms = 0;
        }
        if ($largestUnitIdx < 1) {
            $ns += $us * 1_000;
            $us = 0;
        }

        return [$f64($days), $f64($h), $f64($m), $f64($s), $f64($ms), $f64($us), $f64($ns)];
    }

    /**
     * Attempts to round and balance using exact int64 arithmetic by working in a coarser unit.
     *
     * The spec uses BigInt for NormalizedTimeDuration. This method simulates that by finding
     * the coarsest unit (µs or ms) whose per-unit nanosecond count evenly divides nsIncrement,
     * computing the total in that unit as an exact int64, rounding, and balancing back to fields.
     *
     * Returns null if integer arithmetic is not feasible (totalInUnit overflows int64, or no
     * suitable coarser unit evenly divides nsIncrement).
     *
     * @param int    $absD         Absolute days after balance.
     * @param int    $absH         Absolute hours (0-23).
     * @param int    $absM         Absolute minutes (0-59).
     * @param int    $absS         Absolute seconds (0-59).
     * @param int    $absMs        Absolute milliseconds (0-999).
     * @param int    $absUs        Absolute microseconds (0-999).
     * @param int    $absNs        Absolute nanoseconds (0-999).
     * @param int    $nsIncrement  Rounding increment in nanoseconds.
     * @param string $roundingMode TC39 rounding mode.
     * @param int    $luIdx        Largest unit index (0=ns … 6=day).
     * @return array{0:int|float,1:int|float,2:int|float,3:int|float,4:int|float,5:int|float,6:int|float}|null
     */
    private static function tryRoundExact(
        int $absD,
        int $absH,
        int $absM,
        int $absS,
        int $absMs,
        int $absUs,
        int $absNs,
        int $nsIncrement,
        string $roundingMode,
        int $luIdx,
    ): ?array {
        // The float path is taken because the total nanosecond count overflows int64.
        // Attempt integer arithmetic at a coarser level (ms or µs) to avoid float64 precision loss.
        // The spec uses BigInt; this is an approximation valid when nsIncrement is divisible by the
        // working unit's nanosecond count and no sub-unit remainder exists.

        $floatMax = 9_007_199_254_740_992;
        /** @return int|float */
        $f64 = static function (int|float $v) use ($floatMax): int|float {
            if (is_float($v)) {
                return $v;
            }
            return $v >= $floatMax || $v <= -$floatMax ? (float) $v : $v;
        };

        // Try ms level first (coarser), then µs level.
        // Entry: [nsPerWorkUnit, d-coeff, h-coeff, m-coeff, s-coeff, ms-coeff, us-coeff-in-work-unit]
        foreach ([
            [1_000_000, 86_400_000,     3_600_000,     60_000,     1_000,     1,     0], // ms level
            [1_000,     86_400_000_000, 3_600_000_000, 60_000_000, 1_000_000, 1_000, 1], // µs level
        ] as [$nsPerWu, $dC, $hC, $mC, $sC, $msC, $usC]) {
            if (($nsIncrement % $nsPerWu) !== 0) {
                continue;
            }
            $incWu = intdiv(num1: $nsIncrement, num2: $nsPerWu);

            // Verify that no precision is lost by working at this level.
            // For ms: sub-ms fields (us, ns) must be zero.
            // For µs: sub-µs field (ns) must be zero.
            if ($nsPerWu === 1_000_000 && ($absUs !== 0 || $absNs !== 0)) {
                continue;
            }
            if ($nsPerWu === 1_000 && $absNs !== 0) {
                continue;
            }

            // Guard against int64 overflow in the total computation.
            $floatTotal =
                ((float) $absD * (float) $dC)
                + ((float) $absH * (float) $hC)
                + ((float) $absM * (float) $mC)
                + ((float) $absS * (float) $sC)
                + ((float) $absMs * (float) $msC)
                + ((float) $absUs * (float) $usC);
            if ($floatTotal >= (float) PHP_INT_MAX || $floatTotal <= (float) PHP_INT_MIN) {
                continue;
            }

            $totalWu =
                ($absD * $dC) + ($absH * $hC) + ($absM * $mC) + ($absS * $sC) + ($absMs * $msC) + ($absUs * $usC);
            $roundedWu = self::roundNsPositive($totalWu, $incWu, $roundingMode);

            // Decompose roundedWu back into fields.
            // First separate the sub-ms parts (us, ns are always zero at this point since
            // the rounded value is a multiple of incWu which is >= 1 ms or >= 1 µs).
            if ($nsPerWu === 1_000_000) {
                // Working in ms: us and ns remainders are zero.
                $rNs = 0;
                $rUs = 0;
                $rMs = $roundedWu % 1_000;
                $carry = intdiv(num1: $roundedWu, num2: 1_000);
            } else {
                // Working in µs: ns remainder is zero. Separate us and ms.
                $rNs = 0;
                $rUs = $roundedWu % 1_000;
                $rMs = intdiv(num1: $roundedWu, num2: 1_000) % 1_000;
                $carry = intdiv(num1: $roundedWu, num2: 1_000_000);
            }
            $rS = $carry % 60;
            $carry = intdiv(num1: $carry, num2: 60);
            $rM = $carry % 60;
            $carry = intdiv(num1: $carry, num2: 60);
            $rH = $carry % 24;
            $rD = intdiv(num1: $carry, num2: 24);

            // Bubble up for largestUnit < day.
            if ($luIdx < 6) {
                $rH += $rD * 24;
                $rD = 0;
            }
            if ($luIdx < 5) {
                $rM += $rH * 60;
                $rH = 0;
            }
            if ($luIdx < 4) {
                $rS += $rM * 60;
                $rM = 0;
            }
            if ($luIdx < 3) {
                $rMs += $rS * 1_000;
                $rS = 0;
            }
            if ($luIdx < 2) {
                $rUs += $rMs * 1_000;
                $rMs = 0;
            }
            if ($luIdx < 1) {
                $rNs += $rUs * 1_000;
                $rUs = 0;
            }

            return [$f64($rD), $f64($rH), $f64($rM), $f64($rS), $f64($rMs), $f64($rUs), $f64($rNs)];
        }

        return null;
    }

    /**
     * Float-based rounding for very large nanosecond totals (> PHP_INT_MAX).
     * Uses float64 arithmetic to match JS's Number semantics for large values.
     *
     * @param float  $ns        Non-negative nanoseconds as float.
     * @param float  $increment Rounding increment (nanoseconds).
     * @param string $mode      TC39 rounding mode name.
     */
    private static function roundNsFloat(float $ns, float $increment, string $mode): float
    {
        $q = floor($ns / $increment);
        $d1 = $ns - ($q * $increment); // >= 0
        $r2 = $q + 1.0;
        if ($mode === 'halfEven') {
            $cmp = $d1 * 2.0;
            if ($cmp < $increment) {
                $rounded = $q;
            } elseif ($cmp > $increment) {
                $rounded = $r2;
            } else {
                $rounded = fmod(num1: $q, num2: 2.0) === 0.0 ? $q : $r2;
            }
        } else {
            $rounded = match ($mode) {
                'trunc', 'floor' => $q,
                'ceil', 'expand' => $d1 === 0.0 ? $q : $r2,
                'halfExpand', 'halfCeil' => ($d1 * 2.0) >= $increment ? $r2 : $q,
                'halfTrunc', 'halfFloor' => ($d1 * 2.0) > $increment ? $r2 : $q,
                default => throw new RangeError("Invalid roundingMode \"{$mode}\"."),
            };
        }
        return $rounded * $increment;
    }

    /**
     * Float-based balance of nanoseconds into time fields up to largestUnit.
     * Produces float64-rounded field values, matching JS Number semantics.
     *
     * @param float $totalAbsNs Non-negative nanoseconds as float.
     * @param int   $largestUnitIdx Unit index (0=ns, 1=us, 2=ms, 3=s, 4=min, 5=h, 6=day).
     * @return array{0: int|float, 1: int|float, 2: int|float, 3: int|float, 4: int|float, 5: int|float, 6: int|float}
     */
    private static function balanceNsFloatToFields(float $totalAbsNs, int $largestUnitIdx): array
    {
        // Convert to the target largest unit using float division, then distribute downward.
        // This matches JS's approach of computing the balance via float64 arithmetic.
        $floatMax = (float) PHP_INT_MAX;
        $toIntSafe = static fn(float $v): int|float => abs($v) < $floatMax ? (int) $v : $v;

        $days = 0;
        $ns = $totalAbsNs;

        if ($largestUnitIdx >= 6) {
            $days = floor($ns / 86_400_000_000_000.0);
            $ns -= $days * 86_400_000_000_000.0;
        }
        $h = 0;
        if ($largestUnitIdx >= 5) {
            $h = floor($ns / 3_600_000_000_000.0);
            $ns -= $h * 3_600_000_000_000.0;
        }
        $m = 0;
        if ($largestUnitIdx >= 4) {
            $m = floor($ns / 60_000_000_000.0);
            $ns -= $m * 60_000_000_000.0;
        }
        $s = 0;
        if ($largestUnitIdx >= 3) {
            $s = floor($ns / 1_000_000_000.0);
            $ns -= $s * 1_000_000_000.0;
        }
        $ms = 0;
        if ($largestUnitIdx >= 2) {
            $ms = floor($ns / 1_000_000.0);
            $ns -= $ms * 1_000_000.0;
        }
        $us = 0;
        if ($largestUnitIdx >= 1) {
            $us = floor($ns / 1_000.0);
            $ns -= $us * 1_000.0;
        }

        return [
            $toIntSafe($days),
            $toIntSafe($h),
            $toIntSafe($m),
            $toIntSafe($s),
            $toIntSafe($ms),
            $toIntSafe($us),
            $toIntSafe($ns),
        ];
    }

    /**
     * Validates a timezone identifier string (used for the timeZone option and property-bag field).
     *
     * Rules (from TC39 Temporal spec):
     *   - Minus-zero extended year (-000000) → reject.
     *   - Bracket annotation with a seconds offset (e.g. [+23:59:60]) → reject.
     *   - Pure UTC-offset strings (start with ±HH, no T): must be ±HH:MM or ±HHMM (no seconds).
     *   - Datetime strings (contain T): must have Z, an inline offset, or a bracket annotation;
     *     inline offsets must not include a seconds component (e.g. -07:00:01 is invalid).
     *
     * @throws RangeError for invalid timezone strings.
     */
    private static function validateTimeZoneString(string $tz): void
    {
        // Reject empty string.
        if ($tz === '') {
            throw new RangeError('Invalid timeZone "": empty string is not a valid timezone identifier.');
        }
        // Reject minus-zero extended year.
        if (preg_match('/^-0{6}(?:[^0-9]|$)/', $tz) === 1) {
            throw new RangeError("Invalid timeZone \"{$tz}\": minus-zero year.");
        }
        // Reject bracket annotation with a seconds component (e.g. [+23:59:60]).
        $bm = null;
        if (preg_match('/\[([^\]]+)\]/', $tz, $bm) === 1) {
            if (preg_match('/^[+\-]\d{2}:\d{2}:\d{2}/', $bm[1]) === 1) {
                throw new RangeError("Invalid timeZone \"{$tz}\": sub-minute seconds in bracket annotation.");
            }
        }
        // Pure UTC-offset strings (no T date/time part): must be ±HH:MM or ±HHMM.
        if (preg_match('/^[+\-]\d{2}/', $tz) === 1 && !str_contains($tz, 'T') && !str_contains($tz, 't')) {
            if (preg_match('/^[+\-]\d{2}:\d{2}(?:$|[^:\d])/', $tz) !== 1 && preg_match('/^[+\-]\d{4}$/', $tz) !== 1) {
                throw new RangeError("Invalid timeZone \"{$tz}\": offset contains seconds or is in an invalid format.");
            }
            return;
        }
        // Datetime strings: must have Z, an inline offset, or a bracket annotation.
        if (preg_match('/\d{4,}-\d{2}-\d{2}[Tt]|\d{8}[Tt]/', $tz) === 1) {
            if (preg_match('/T\d{2}:?\d{2}(?::?\d{2})?(?:\.\d+)?(?:Z|[+\-]|\[)/i', $tz) !== 1) {
                throw new RangeError("Invalid timeZone \"{$tz}\": bare datetime without Z, offset, or bracket.");
            }
            // Inline offset must not include a seconds component (e.g. -07:00:01).
            if (preg_match('/[+\-]\d{2}:\d{2}:\d{2}(?!\])/i', $tz) === 1) {
                throw new RangeError("Invalid timeZone \"{$tz}\": inline offset contains a seconds component.");
            }
        }
    }

    /**
     * Floor division for a positive integer divisor.
     *
     * Unlike PHP's intdiv() (which truncates towards zero), this returns the mathematical
     * floor: the largest integer ≤ a/b. Required for the Julian Day Number formula to
     * work correctly with negative years.
     */
    private static function floorDivInt(int $a, int $b): int
    {
        $q = intdiv(num1: $a, num2: $b);
        return $a < 0 && ($a % $b) !== 0 ? $q - 1 : $q;
    }

    /**
     * Adds $months months to $date using TC39 month arithmetic (clamp to last day of month).
     * PHP's modify('+N months') overflows (e.g. Jan 31 + 1 month = Mar 2); TC39 clamps to Feb 29.
     *
     * @param \DateTimeImmutable $date Base date (UTC midnight).
     * @param int $months Signed number of months to add (may be negative).
     */
    private static function addMonthsClamped(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        if ($months === 0) {
            return $date;
        }
        $y = (int) $date->format('Y');
        $m = (int) $date->format('n');
        $d = (int) $date->format('j');

        $m += $months;
        // Normalize month into 1-12 range, carrying into years.
        if ($m > 12) {
            $y += intdiv(num1: $m - 1, num2: 12);
            $m = (($m - 1) % 12) + 1;
        } elseif ($m < 1) {
            // For negative: m-1 makes the -1 offset work for intdiv.
            $y += self::floorDivInt($m - 1, 12);
            $m = (((($m - 1) % 12) + 12) % 12) + 1;
        }
        // Days in the target month (handles leap years). Computed via CalendarMath
        // rather than a string-built DateTimeImmutable so extended (5-/6-digit) years
        // do not trip "Double timezone specification" parse errors.
        $daysInMonth = CalendarMath::calcDaysInMonth($y, $m);
        $clampedDay = min($d, $daysInMonth);
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            ->setDate($y, $m, $clampedDay)
            ->setTime(0, 0, 0);
    }

    /**
     * Adds $years years to $date using TC39 year arithmetic (clamp Feb 29 to Feb 28 in non-leap years).
     *
     * @param \DateTimeImmutable $date Base date (UTC midnight).
     * @param int $years Signed number of years to add.
     */
    private static function addYearsClamped(\DateTimeImmutable $date, int $years): \DateTimeImmutable
    {
        if ($years === 0) {
            return $date;
        }
        $y = (int) $date->format('Y') + $years;
        $m = (int) $date->format('n');
        $d = (int) $date->format('j');
        // Computed via CalendarMath (not a string-built DateTimeImmutable) so extended
        // (5-/6-digit) years do not trip "Double timezone specification" parse errors.
        $daysInMonth = CalendarMath::calcDaysInMonth($y, $m);
        $clampedDay = min($d, $daysInMonth);
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            ->setDate($y, $m, $clampedDay)
            ->setTime(0, 0, 0);
    }

    /**
     * Resolves a relativeTo value to ZDT info if it represents a ZonedDateTime with
     * an IANA timezone (i.e. one that may observe DST). Returns null for PlainDate,
     * UTC, or fixed-offset timezones.
     *
     * @return null|array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int}
     */
    private static function resolveRelativeToZdt(mixed $rt): ?array
    {
        // Normalize plain-object property bags (but keep Temporal instances intact).
        if (is_object($rt) && !$rt instanceof ZonedDateTime && !$rt instanceof PlainDate) {
            $rt = FieldBag::forCalendarType($rt, self::RELATIVE_TO_FIELDS, ['offset', 'timeZone'], 'relativeTo');
        }
        if ($rt instanceof ZonedDateTime) {
            $tzId = $rt->timeZoneId;
            if ($tzId === '' || $tzId === 'UTC' || preg_match('/^[+\-]\d{2}:\d{2}$/', $tzId) === 1) {
                return null;
            }
            // Read the TRUE epoch parts (sentinel-aware) rather than the clamped
            // epochNanoseconds field, so over-int64 relativeTo anchors resolve to
            // their real calendar date instead of the year-2262 clamp.
            [$epochSec, $subNs] = $rt->epochParts();
            // Compute local components via offset.
            $tz = new \DateTimeZone($tzId);
            $offsetSec = $tz->getOffset(new \DateTimeImmutable(sprintf('@%d', $epochSec)));
            $localSec = $epochSec + $offsetSec;
            $dt = new \DateTimeImmutable(sprintf('@%d', $localSec));
            return [
                'epochSec' => $epochSec,
                'subNs' => $subNs,
                'tzId' => $tzId,
                'year' => (int) $dt->format('Y'),
                'month' => (int) $dt->format('n'),
                'day' => (int) $dt->format('j'),
                'hour' => (int) $dt->format('G'),
                'minute' => (int) $dt->format('i'),
                'second' => (int) $dt->format('s'),
            ];
        }
        if (is_string($rt)) {
            // Parse the string to check for IANA timezone.
            $m = null;
            if (preg_match('/\[([^\]=]+)\]\s*$/', $rt, $m) === 1) {
                $tzId = $m[1];
                if ($tzId !== 'UTC' && preg_match('/^[+\-]\d{2}:\d{2}$/', $tzId) !== 1) {
                    // It's an IANA timezone string. Construct a ZDT and recurse.
                    $zdt = ZonedDateTime::from($rt);
                    return self::resolveRelativeToZdt($zdt);
                }
            }
            return null;
        }
        if (is_array($rt) && array_key_exists('timeZone', $rt)) {
            /** @var mixed $tzVal */
            $tzVal = $rt['timeZone'];
            // Only treat as IANA timezone if it looks like one (contains '/' or is a known single-word zone).
            // Datetime strings passed as timeZone (e.g. "2021-08-19T17:30-0700") are not IANA.
            $isIanaTz = false;
            if (
                is_string($tzVal)
                && $tzVal !== ''
                && $tzVal !== 'UTC'
                && preg_match('/^[+\-]\d{2}:\d{2}$/', $tzVal) !== 1
                && !str_contains($tzVal, 'T')
                && preg_match('/^\d{4}-/', $tzVal) !== 1
            ) {
                try {
                    $isIanaTz = new \DateTimeZone($tzVal)->getName() === $tzVal;
                } catch (\Exception) {
                    $isIanaTz = false;
                }
            }
            if ($isIanaTz) {
                // Property bag with IANA timezone. Use ZonedDateTime::from() so offset
                // validation (including sub-minute mismatch rejection) is handled.
                $zdt = ZonedDateTime::from($rt);
                return self::resolveRelativeToZdt($zdt);
            }
            return null;
        }
        return null;
    }

    /**
     * Computes the epoch seconds for a given local date/time in an IANA timezone.
     * Uses 'compatible' disambiguation (earlier for overlaps, post-transition for gaps).
     */
    private static function localToEpochSec(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        string $tzId,
    ): int {
        // Compute wall seconds (seconds since epoch if interpreted as UTC).
        // gmmktime() normalizes out-of-range fields instead of rejecting them, so for
        // valid components it never returns false on a 64-bit platform. Callers must
        // pass already-validated date/time fields: invalid fields would silently roll
        // over (e.g. month 13 -> next January), not throw. The false branch is thus
        // unreachable here and the assert exists only to narrow gmmktime()'s int|false
        // return for static analysis. On 32-bit builds gmmktime() can return false for
        // years outside 1901-2038, where the assert would surface as an error rather
        // than a clean result -- see the platform note in README.md.
        $wallSec = gmmktime($hour, $minute, $second, $month, $day, $year);
        assert($wallSec !== false);
        return TimeZoneHelper::wallSecToEpochSec($wallSec, $tzId);
    }

    /**
     * Computes the length (in seconds) of one calendar day starting from the given
     * local date/time in an IANA timezone. This is the epoch difference between
     * the same local time tomorrow and today.
     */
    private static function zdtDayLengthSec(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        string $tzId,
    ): int {
        $todayEpoch = self::localToEpochSec($year, $month, $day, $hour, $minute, $second, $tzId);
        // Add 1 calendar day to the local date.
        $dt = new \DateTimeImmutable(sprintf(
            '%04d-%02d-%02dT%02d:%02d:%02d',
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second,
        ));
        $next = $dt->modify('+1 day');
        $tomorrowEpoch = self::localToEpochSec(
            (int) $next->format('Y'),
            (int) $next->format('n'),
            (int) $next->format('j'),
            $hour,
            $minute,
            $second,
            $tzId,
        );
        return $tomorrowEpoch - $todayEpoch;
    }

    /**
     * For total('days') with ZDT: computes the fractional day count for a given
     * number of total seconds starting from the ZDT's actual epoch.
     * Uses the ZDT's real epoch for the start point (preserving ambiguous-time
     * offset) and 'compatible' disambiguation for subsequent day boundaries.
     *
     * @param int $startEpochSec The ZDT's actual epoch seconds.
     */
    private static function zdtTotalDays(
        int $startEpochSec,
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        string $tzId,
        float $totalSec,
    ): float {
        $sign = $totalSec >= 0 ? 1 : -1;
        $remaining = abs($totalSec);
        $wholeDays = 0;

        $curEpoch = $startEpochSec;
        $curYear = $year;
        $curMonth = $month;
        $curDay = $day;

        while (true) {
            $dt = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $curYear, $curMonth, $curDay));
            $next = $dt->modify($sign > 0 ? '+1 day' : '-1 day');
            $nextYear = (int) $next->format('Y');
            $nextMonth = (int) $next->format('n');
            $nextDay = (int) $next->format('j');
            $nextEpoch = self::localToEpochSec($nextYear, $nextMonth, $nextDay, $hour, $minute, $second, $tzId);
            $dayLengthSec = (float) abs($nextEpoch - $curEpoch);

            if ($remaining < $dayLengthSec) {
                $frac = $dayLengthSec > 0 ? $remaining / $dayLengthSec : 0.0;
                return (float) $sign * ((float) $wholeDays + $frac);
            }

            $remaining -= $dayLengthSec;
            $wholeDays++;
            $curEpoch = $nextEpoch;
            $curYear = $nextYear;
            $curMonth = $nextMonth;
            $curDay = $nextDay;
        }
    }

    /**
     * For total('hours'/'minutes'/etc) with ZDT: computes the total seconds
     * for a duration that has days, by adding days as calendar days to the ZDT
     * and measuring the actual epoch difference.
     */
    /**
     * @param int|null $knownStartEpoch When provided, used as the start epoch instead of
     *        re-resolving from wall time. This preserves the correct offset for ambiguous times.
     */
    private static function zdtDaysToSec(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        string $tzId,
        int $days,
        ?int $knownStartEpoch = null,
    ): float {
        if ($days === 0) {
            return 0.0;
        }
        $startEpoch = $knownStartEpoch ?? self::localToEpochSec($year, $month, $day, $hour, $minute, $second, $tzId);
        $dt = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        $end = $dt->modify(sprintf('%+d days', $days));
        $endEpoch = self::localToEpochSec(
            (int) $end->format('Y'),
            (int) $end->format('n'),
            (int) $end->format('j'),
            $hour,
            $minute,
            $second,
            $tzId,
        );
        return (float) ($endEpoch - $startEpoch);
    }

    /**
     * For round() with ZDT: balances time nanoseconds into whole days + remaining ns,
     * using actual day lengths. Returns [wholeDays, remainingNs].
     *
     * @return array{int, int}
     */
    /**
     * @param int $direction 1 for positive durations (forward), -1 for negative (backward).
     * @param int|null $knownStartEpoch Use the ZDT's actual epoch for the initial date to
     *        preserve the correct offset for ambiguous times.
     * @return array{int, int}
     */
    private static function zdtBalanceTimeToDays(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        string $tzId,
        int $absTimeNs,
        int $absDays,
        int $direction = 1,
        ?int $knownStartEpoch = null,
    ): array {
        // Start from the date after adding absDays calendar days in the given direction.
        $dt = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        if ($absDays > 0) {
            $dtAfterDays = $dt->modify(sprintf('%+d days', $direction * $absDays));
        } else {
            $dtAfterDays = $dt;
        }
        $curYear = (int) $dtAfterDays->format('Y');
        $curMonth = (int) $dtAfterDays->format('n');
        $curDay = (int) $dtAfterDays->format('j');

        $totalDays = $absDays;
        $remaining = $absTimeNs;

        $step = $direction >= 0 ? '+1 day' : '-1 day';
        // Use the known epoch only for the first iteration when no days were added.
        $useKnownEpoch = $knownStartEpoch !== null && $absDays === 0;

        while (true) {
            $curEpoch = $useKnownEpoch && $knownStartEpoch !== null
                ? $knownStartEpoch
                : self::localToEpochSec($curYear, $curMonth, $curDay, $hour, $minute, $second, $tzId);
            $useKnownEpoch = false;
            $nextDt = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $curYear, $curMonth, $curDay))->modify($step);
            $nextYear = (int) $nextDt->format('Y');
            $nextMonth = (int) $nextDt->format('n');
            $nextDay = (int) $nextDt->format('j');
            $nextEpoch = self::localToEpochSec($nextYear, $nextMonth, $nextDay, $hour, $minute, $second, $tzId);
            $dayLengthNs = abs($nextEpoch - $curEpoch) * 1_000_000_000;

            if ($remaining < $dayLengthNs) {
                return [$totalDays, $remaining];
            }

            $remaining -= $dayLengthNs;
            $totalDays++;
            $curYear = $nextYear;
            $curMonth = $nextMonth;
            $curDay = $nextDay;
        }
    }

    /**
     * Computes the total epoch-second offset for a duration added to a ZDT.
     * Days are added as calendar days (DST-aware), time fields as seconds.
     *
     * @param array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int} $zdtInfo
     */
    private static function durationToEpochOffsetSec(self $d, array $zdtInfo): float
    {
        // Pass the ZDT's actual epoch so that sub-minute offsets (e.g. Pacific/Niue
        // -11:19:40 vs -11:20:00) are preserved instead of being re-resolved via
        // compatible disambiguation.
        $daysSec = self::zdtDaysToSec(
            $zdtInfo['year'],
            $zdtInfo['month'],
            $zdtInfo['day'],
            $zdtInfo['hour'],
            $zdtInfo['minute'],
            $zdtInfo['second'],
            $zdtInfo['tzId'],
            (int) $d->days,
            $zdtInfo['epochSec'],
        );
        $timeSec =
            ((float) $d->hours * 3_600.0)
            + ((float) $d->minutes * 60.0)
            + (float) $d->seconds
            + (
                (
                    ((float) $d->milliseconds * 1_000_000.0)
                    + ((float) $d->microseconds * 1_000.0)
                    + (float) $d->nanoseconds
                )
                / 1_000_000_000.0
            );
        return $daysSec + $timeSec;
    }

    /**
     * Converts a proleptic Gregorian calendar date to an epoch-day count
     * (days since 1970-01-01 = day 0), using the Julian Day Number formula.
     *
     * Works correctly for dates outside the PHP DateTimeImmutable range, including
     * extended years up to ±999999.
     */
    private static function isoDateToEpochDays(int $y, int $m, int $d): int
    {
        $a = self::floorDivInt(14 - $m, 12);
        $yp = $y + 4800 - $a;
        $mp = $m + (12 * $a) - 3;
        $jdn =
            $d + self::floorDivInt((153 * $mp) + 2, 5) + (365 * $yp) + self::floorDivInt($yp, 4)
                - self::floorDivInt($yp, 100)
                + self::floorDivInt($yp, 400)
            - 32_045;
        return $jdn - 2_440_588;
    }

    /**
     * Throws if the given (extended-year) date lies outside the representable
     * ISO date-time range (epoch-days in [-100 000 000, +100 000 000]).
     *
     * Used to guard the calendar-unit nudge boundaries in totalCalendarYears /
     * totalCalendarMonths: per TC39 RoundDuration, snapping a near-limit anchor
     * to the next year/month boundary (r2 = start + (n+1) units) can produce a
     * date beyond the ISO limit, which must raise a RangeError.
     */
    private static function assertCalendarBoundaryInRange(\DateTimeImmutable $boundary): void
    {
        $epochDays = self::isoDateToEpochDays(
            (int) $boundary->format('Y'),
            (int) $boundary->format('n'),
            (int) $boundary->format('j'),
        );
        if (abs($epochDays) > 100_000_000) {
            throw new RangeError('Duration with relativeTo exceeds the maximum representable date range.');
        }
    }

    /**
     * Determines the 'auto' largestUnit index: largest non-zero time field
     * among days(6), hours(5), minutes(4), seconds(3), ms(2), us(1), ns(0).
     * Falls back to $suIdx when all fields are zero.
     */
    private function autoLargestUnit(int $suIdx): int
    {
        if ($this->days !== 0) {
            return 6;
        }
        if ($this->hours !== 0) {
            return 5;
        }
        if ($this->minutes !== 0) {
            return 4;
        }
        if ($this->seconds !== 0) {
            return 3;
        }
        if ($this->milliseconds !== 0) {
            return 2;
        }
        if ($this->microseconds !== 0) {
            return 1;
        }
        if ($this->nanoseconds !== 0) {
            return 0;
        }
        return $suIdx;
    }

    /**
     * Determines the largest non-zero unit index including calendar fields.
     * Used when largestUnit is 'auto' for calendar rounding.
     * Returns 0 (nanoseconds) when all fields are zero.
     */
    private function autoLargestUnitCalendar(int $suIdx): int
    {
        if ($this->years !== 0) {
            return 9;
        }
        if ($this->months !== 0) {
            return 8;
        }
        if ($this->weeks !== 0) {
            return 7;
        }
        return $this->autoLargestUnit($suIdx);
    }

    /**
     * Applies this duration's calendar fields (years, months, weeks) and day fields
     * to a start date, returning [endDate, calendarDays] where calendarDays is the
     * signed day-count between start and end (from calendar fields only).
     *
     * @param \DateTimeImmutable $startDate UTC midnight on the start date.
     * @return array{0: \DateTimeImmutable, 1: int}
     */
    private function applyCalendarToDate(\DateTimeImmutable $startDate): array
    {
        $endDate = $startDate;
        // Apply years, months, weeks with TC39-compliant clamped arithmetic.
        $applySign = $this->sign;
        if ((int) $this->years !== 0) {
            $endDate = self::addYearsClamped($endDate, $applySign * abs((int) $this->years));
        }
        if ((int) $this->months !== 0) {
            $endDate = self::addMonthsClamped($endDate, $applySign * abs((int) $this->months));
        }
        if ((int) $this->weeks !== 0) {
            $awDays = $applySign * abs((int) $this->weeks) * 7;
            $endDate = $endDate->modify(sprintf('%+d days', $awDays));
        }
        // Apply days.
        $calDays = (int) $this->days;
        if ($calDays !== 0) {
            $absD = abs($calDays);
            $endDate = $calDays > 0 ? $endDate->modify("+{$absD} days") : $endDate->modify("-{$absD} days");
        }
        $calendarDays = (int) $startDate->diff($endDate)->format('%r%a');
        // Validate: epoch-day range ±100 000 000 (matches Temporal spec PlainDate limits).
        if (abs($calendarDays) > 100_000_000) {
            throw new RangeError('Duration applied to relativeTo produces a date outside the representable range.');
        }
        return [$endDate, $calendarDays];
    }

    /**
     * Computes the signed total nanoseconds this duration represents when anchored
     * to a PlainDate relativeTo. Used for Duration::compare() with calendar units.
     *
     * @param mixed $rt Validated relativeTo value.
     */
    private function totalNsFromRelativeTo(mixed $rt): int
    {
        $bag = $this->relativeToPlainDateBag($rt);
        $tz = new \DateTimeZone('UTC');
        $startDate = new \DateTimeImmutable('now', $tz)
            ->setDate($bag['year'], $bag['month'], $bag['day'])
            ->setTime(0, 0, 0);

        // applyCalendarToDate throws RangeError if totalDays > ±100M.
        [, $calendarDays] = $this->applyCalendarToDate($startDate);

        $timeNs =
            ((int) $this->hours * 3_600_000_000_000)
            + ((int) $this->minutes * 60_000_000_000)
            + ((int) $this->seconds * 1_000_000_000)
            + ((int) $this->milliseconds * 1_000_000)
            + ((int) $this->microseconds * 1_000)
            + (int) $this->nanoseconds;

        // For ZDT with IANA timezone: use actual epoch seconds for calendar days.
        $zdtInfo = self::resolveRelativeToZdt($rt);
        if ($zdtInfo !== null) {
            $actualDaysSec = (int) self::zdtDaysToSec(
                $zdtInfo['year'],
                $zdtInfo['month'],
                $zdtInfo['day'],
                $zdtInfo['hour'],
                $zdtInfo['minute'],
                $zdtInfo['second'],
                $zdtInfo['tzId'],
                $calendarDays,
            );
            $dayNs = $actualDaysSec * 1_000_000_000;
            $totalNsF = (float) $dayNs + (float) $timeNs;
            if ($totalNsF > (float) PHP_INT_MAX || $totalNsF < (float) PHP_INT_MIN) {
                throw new RangeError('Duration nanosecond total overflows the 64-bit range.');
            }
            return $dayNs + $timeNs;
        }

        $nsPerDay = 86_400_000_000_000;
        // Guard against int64 overflow when combining calendar days and time nanoseconds.
        $totalNsF = ((float) $calendarDays * (float) $nsPerDay) + (float) $timeNs;
        if ($totalNsF > (float) PHP_INT_MAX || $totalNsF < (float) PHP_INT_MIN) {
            throw new RangeError('Duration nanosecond total overflows the 64-bit range.');
        }

        return ($calendarDays * $nsPerDay) + $timeNs;
    }

    /**
     * Applies calendar rounding to select either $r1 or $r2 based on progress and mode.
     * For NudgeToCalendarUnit: $r1 and $r2 are the lower and upper calendar boundaries,
     * $progress is (total - r1) / (r2 - r1) in [0, 1].
     *
     * @param int    $r1       Lower boundary count (in the calendar unit).
     * @param int    $r2       Upper boundary count (= $r1 + $increment).
     * @param float  $progress Fractional progress from r1 to r2 (0 = at r1, 1 = at r2).
     * @param string $mode     TC39 rounding mode.
     * @param bool   $positive Whether the duration is positive.
     */
    private static function applyCalendarRounding(int $r1, int $r2, float $progress, string $mode, bool $positive): int
    {
        if ($progress >= 1.0) {
            return $r2;
        }
        // When progress = 0, the value is exactly at r1; all rounding modes return r1.
        if ($progress === 0.0) {
            return $r1;
        }
        if ($mode === 'halfFloor') {
            if ($positive) {
                return $progress > 0.5 ? $r2 : $r1;
            }
            return $progress >= 0.5 ? $r2 : $r1;
        }
        if ($mode === 'halfCeil') {
            if ($positive) {
                return $progress >= 0.5 ? $r2 : $r1;
            }
            return $progress > 0.5 ? $r2 : $r1;
        }
        if ($mode === 'halfEven') {
            if ($progress > 0.5) {
                return $r2;
            }
            if ($progress < 0.5) {
                return $r1;
            }
            return ($r1 % 2) === 0 ? $r1 : $r2;
        }
        return match ($mode) {
            'trunc' => $r1,
            'floor' => $positive ? $r1 : $r2,
            'ceil' => $positive ? $r2 : $r1,
            'expand' => $r2,
            'halfExpand' => $progress >= 0.5 ? $r2 : $r1,
            'halfTrunc' => $progress > 0.5 ? $r2 : $r1,
            default => throw new RangeError("Invalid roundingMode \"{$mode}\"."),
        };
    }

    /**
     * Balances signed total days into years, months, weeks, days relative to $startDate.
     * Implements BalanceDateDurationRelative for PlainDate.
     *
     * @param \DateTimeImmutable $startDate UTC midnight on relativeTo date.
     * @param int $totalDays Signed total days to balance.
     * @param int $luIdx Largest unit index (6=days, 7=weeks, 8=months, 9=years).
     * @param int $suIdx Smallest unit index.
     * @return array{0: int, 1: int, 2: int, 3: int} [years, months, weeks, days]
     */
    private static function balanceDateDuration(
        \DateTimeImmutable $startDate,
        int $totalDays,
        int $luIdx,
        int $suIdx,
    ): array {
        if ($totalDays === 0) {
            return [0, 0, 0, 0];
        }
        $sign = $totalDays > 0 ? 1 : -1;
        $dir = $sign > 0 ? '+' : '-';
        $absDays = abs($totalDays);
        $endDate = $startDate->modify("{$dir}{$absDays} days");

        $years = 0;
        $months = 0;
        $weeks = 0;

        $current = $startDate;

        // Accumulate full years when largestUnit >= 'years'.
        if ($luIdx >= 9) {
            while (true) {
                $next = self::addYearsClamped($current, $sign);
                if ($sign > 0 ? $next > $endDate : $next < $endDate) {
                    break;
                }
                $years++;
                $current = $next;
            }
        }

        // Accumulate full months when largestUnit >= 'months' and smallestUnit != 'years'.
        if ($luIdx >= 8 && $suIdx < 9) {
            while (true) {
                $next = self::addMonthsClamped($current, $sign);
                if ($sign > 0 ? $next > $endDate : $next < $endDate) {
                    break;
                }
                $months++;
                $current = $next;
            }
        }

        // remainingDays is signed (negative when direction is negative).
        $remainingDays = (int) $current->diff($endDate)->format('%r%a');

        // Distribute remaining days into weeks when:
        // - largestUnit is exactly 'weeks' (idx=7): weeks are the top unit, so split remaining into weeks+days.
        // - smallestUnit is 'weeks' (suIdx=7): weeks must appear in the output (e.g. 5Y 7M 4W).
        //   In this case days would be 0 (since we rounded to a week boundary).
        // When largestUnit > 'weeks' (months/years) and smallestUnit < 'weeks' (days/hours/...),
        // remaining days stay as plain days (no weeks distribution).
        if ($luIdx === 7 || $suIdx === 7) {
            $weeks = intdiv(num1: $remainingDays, num2: 7);
            $remainingDays %= 7;
        }

        // $years and $months are unsigned counters; apply sign. $weeks and $remainingDays
        // are already signed (derived from the signed diff), so return them directly.
        return [$sign * $years, $sign * $months, $weeks, $remainingDays];
    }

    /**
     * Implements Duration::round() when calendar arithmetic is needed (relativeTo is a PlainDate).
     *
     * @param mixed $rtRaw Already-validated relativeTo value (PlainDate, string, or array).
     * @param ?string $suNorm Normalized smallestUnit or null.
     * @param bool $luIsAuto Whether largestUnit is 'auto'.
     * @param ?string $luNorm Normalized largestUnit or null.
     * @param int $increment Rounding increment.
     * @param string $roundingMode TC39 rounding mode.
     * @param array<string,int> $UNIT_IDX Unit name → index mapping.
     */
    private function roundWithRelativeTo(
        mixed $rtRaw,
        ?string $suNorm,
        bool $luIsAuto,
        ?string $luNorm,
        int $increment,
        string $roundingMode,
        array $UNIT_IDX,
    ): self {
        $bag = $this->relativeToPlainDateBag($rtRaw);
        $zdtInfoRWR = self::resolveRelativeToZdt($rtRaw);
        // When relativeTo resolves to a ZonedDateTime, use the ZDT's local date
        // (which accounts for UTC offset to local time conversion, e.g. Z+IANA strings).
        if ($zdtInfoRWR !== null) {
            $bag = [
                'year' => $zdtInfoRWR['year'],
                'month' => $zdtInfoRWR['month'],
                'day' => $zdtInfoRWR['day'],
            ];
        }
        $tz = new \DateTimeZone('UTC');
        $startDate = new \DateTimeImmutable('now', $tz)
            ->setDate($bag['year'], $bag['month'], $bag['day'])
            ->setTime(0, 0, 0);

        // Compute effective largestUnit index.
        $suIdx = $suNorm !== null ? $UNIT_IDX[$suNorm] : 0;
        if ($luIsAuto) {
            $luIdx = $this->autoLargestUnitCalendar($suIdx);
            if ($luIdx < $suIdx) {
                $luIdx = $suIdx;
            }
        } else {
            $luIdx = $UNIT_IDX[$luNorm ?? 'nanoseconds'];
        }

        if ($luIdx < $suIdx) {
            throw new RangeError('largestUnit must be at least as large as smallestUnit.');
        }

        // TC39: disallow increment > 1 when balancing to a larger calendar-or-day unit.
        // e.g. smallestUnit='months', largestUnit='years', increment=8 → RangeError.
        // e.g. smallestUnit='days', largestUnit='weeks', increment=30 → RangeError.
        if ($increment > 1 && $luIdx > $suIdx && $suIdx >= 6) {
            throw new RangeError(
                "roundingIncrement > 1 is not allowed when smallestUnit is \"{$suNorm}\" and largestUnit is a larger unit.",
            );
        }

        // Apply the full duration to the start date to get end date + calendar day count.
        // applyCalendarToDate throws RangeError if totalDays > ±100M.
        [, $calendarDays] = $this->applyCalendarToDate($startDate);

        $timeNs =
            ((int) $this->hours * 3_600_000_000_000)
            + ((int) $this->minutes * 60_000_000_000)
            + ((int) $this->seconds * 1_000_000_000)
            + ((int) $this->milliseconds * 1_000_000)
            + ((int) $this->microseconds * 1_000)
            + (int) $this->nanoseconds;

        $nsPerDay = 86_400_000_000_000;

        // Total nanoseconds = calendar days * nsPerDay + time fields.
        $totalNs = ($calendarDays * $nsPerDay) + $timeNs;
        $isPositive = $totalNs >= 0;

        // Guard: a time component so large it balances into a date beyond the representable
        // range (±100 000 000 days) must throw RangeError up front — TC39 AdjustDateDurationRecord
        // (round §28.d) — rather than spin the calendar-nudge loop forever. $totalNs may have
        // overflowed to a float (e.g. seconds = 2^53 - 1), so divide as float.
        if (abs((float) $totalNs / (float) $nsPerDay) > 100_000_000.0) {
            throw new RangeError('Duration with relativeTo exceeds the maximum representable date range.');
        }

        // -----------------------------------------------------------------------
        // Round based on smallestUnit
        // -----------------------------------------------------------------------

        if ($suIdx >= 8) {
            // Smallest unit is months or years: NudgeToCalendarUnit
            return $this->nudgeToCalendarMonthsOrYears(
                $startDate,
                $totalNs,
                $nsPerDay,
                $suIdx,
                $luIdx,
                $increment,
                $roundingMode,
                $isPositive,
                $zdtInfoRWR,
            );
        }

        if ($suIdx === 7) {
            // Smallest unit is weeks: NudgeToCalendarUnit for weeks
            return $this->nudgeToCalendarWeeks(
                $startDate,
                $totalNs,
                $nsPerDay,
                $luIdx,
                $increment,
                $roundingMode,
                $isPositive,
            );
        }

        // Smallest unit is days or smaller: NudgeToTimeUnit
        /** @var array<string,int> */
        static $NS_PER_UNIT = [
            'nanoseconds' => 1,
            'microseconds' => 1_000,
            'milliseconds' => 1_000_000,
            'seconds' => 1_000_000_000,
            'minutes' => 60_000_000_000,
            'hours' => 3_600_000_000_000,
        ];
        $suNormResolved = $suNorm ?? 'nanoseconds';

        // Validate sub-day increment: must be strictly less than next-higher-unit count and divide it evenly.
        // Per TC39: e.g. minutes increment must be < 60 and divide 60 evenly.
        if ($suIdx < 6) {
            /** @var array<string,int> */
            static $MAX_PER_UNIT_RWR = [
                'nanoseconds' => 1_000,
                'microseconds' => 1_000,
                'milliseconds' => 1_000,
                'seconds' => 60,
                'minutes' => 60,
                'hours' => 24,
            ];
            $maxPerUnit = $MAX_PER_UNIT_RWR[$suNormResolved] ?? 1;
            if ($increment >= $maxPerUnit) {
                throw new RangeError("roundingIncrement {$increment} is too large for unit \"{$suNormResolved}\".");
            }
            if (($maxPerUnit % $increment) !== 0) {
                throw new RangeError(
                    "roundingIncrement {$increment} does not evenly divide into the next unit for \"{$suNormResolved}\".",
                );
            }
        }

        // Round the signed total nanoseconds.
        // TC39 uses signed (ApplyUnsignedRoundingMode on signed fractional value), so for negative
        // durations floor rounds toward -∞ (larger abs) and ceil rounds toward zero (smaller abs).
        // Since roundNsPositive works on absolute values, swap floor↔ceil and halfFloor↔halfCeil
        // when the duration is negative so the absolute-value rounding matches signed semantics.
        $sign = $totalNs >= 0 ? 1 : -1;
        $absNs = abs($totalNs);
        $signedMode = $sign < 0
            ? match ($roundingMode) {
                'floor' => 'ceil',
                'ceil' => 'floor',
                'halfFloor' => 'halfCeil',
                'halfCeil' => 'halfFloor',
                default => $roundingMode,
            }
            : $roundingMode;

        // For 'days' smallest unit: work in day units to avoid int64 overflow when increment is large
        // (e.g. roundingIncrement=1e9 days → nsIncrement=8.64e22 would overflow PHP_INT_MAX=9.2e18).
        // For sub-day units: round in nanoseconds using integer arithmetic.
        $roundedAbsNs = 0; // initialised here; only used in the sub-day path (luIdx < 6)
        if ($suNormResolved === 'days') {
            if ($zdtInfoRWR !== null) {
                // For ZDT: use DST-aware day lengths to compute fractional days.
                // Balance the time portion into days using actual day lengths first,
                // then compute the fractional remainder for rounding.
                $calDateEnd = $startDate;
                $applySign = $this->sign;
                if ((int) $this->years !== 0) {
                    $calDateEnd = self::addYearsClamped($calDateEnd, $applySign * abs((int) $this->years));
                }
                if ((int) $this->months !== 0) {
                    $calDateEnd = self::addMonthsClamped($calDateEnd, $applySign * abs((int) $this->months));
                }
                if ((int) $this->weeks !== 0) {
                    $awDays = $applySign * abs((int) $this->weeks) * 7;
                    $calDateEnd = $calDateEnd->modify(sprintf('%+d days', $awDays));
                }
                $absRawDays = abs((int) $this->days);
                $absTimeOnlyNs = abs($timeNs);
                $calEndY = (int) $calDateEnd->format('Y');
                $calEndM = (int) $calDateEnd->format('n');
                $calEndD = (int) $calDateEnd->format('j');
                // Balance raw time ns into DST-aware days.
                [$timeDays, $remainNs] = self::zdtBalanceTimeToDays(
                    $calEndY,
                    $calEndM,
                    $calEndD,
                    $zdtInfoRWR['hour'],
                    $zdtInfoRWR['minute'],
                    $zdtInfoRWR['second'],
                    $zdtInfoRWR['tzId'],
                    $absTimeOnlyNs,
                    $absRawDays,
                    $sign,
                );
                $totalAbsDays = $timeDays;
                // Compute fractional day from the remainder using the next day's actual length.
                $afterDaysDate = $calDateEnd->modify(sprintf('%+d days', $sign * $totalAbsDays));
                $nextDayLengthSec = abs(self::zdtDayLengthSec(
                    (int) $afterDaysDate->format('Y'),
                    (int) $afterDaysDate->format('n'),
                    (int) $afterDaysDate->format('j'),
                    $zdtInfoRWR['hour'],
                    $zdtInfoRWR['minute'],
                    $zdtInfoRWR['second'],
                    $zdtInfoRWR['tzId'],
                ));
                $nextDayLengthNs = $nextDayLengthSec * 1_000_000_000;
                $fracDay = $nextDayLengthNs > 0 ? (float) $remainNs / (float) $nextDayLengthNs : 0.0;
                $totalAbsDaysF = (float) $totalAbsDays + $fracDay;
                $roundedAbsDays = (int) self::roundNsFloat($totalAbsDaysF, (float) $increment, $signedMode);
                // Re-add calendar-only days.
                $roundedDays = $sign * ($roundedAbsDays + abs($calendarDays) - $absRawDays);
            } else {
                // Express total as fractional days and round using float arithmetic.
                $totalAbsDaysF = (float) $absNs / (float) $nsPerDay;
                $roundedAbsDays = (int) self::roundNsFloat($totalAbsDaysF, (float) $increment, $signedMode);
                $roundedDays = $sign * $roundedAbsDays;
            }
            $subDayNs = 0;
        } else {
            $nsPerSmallest = $NS_PER_UNIT[$suNormResolved] ?? 1;
            $nsIncrement = $nsPerSmallest * $increment;
            // For ZDT IANA: balance time into DST-aware days BEFORE rounding,
            // round only the sub-day remainder, then check for day overflow.
            if ($zdtInfoRWR !== null) {
                // Compute the date after adding calendar fields (years/months/weeks) only.
                $calDateEnd = $startDate;
                $applySign = $this->sign;
                if ((int) $this->years !== 0) {
                    $calDateEnd = self::addYearsClamped($calDateEnd, $applySign * abs((int) $this->years));
                }
                if ((int) $this->months !== 0) {
                    $calDateEnd = self::addMonthsClamped($calDateEnd, $applySign * abs((int) $this->months));
                }
                if ((int) $this->weeks !== 0) {
                    $awDays = $applySign * abs((int) $this->weeks) * 7;
                    $calDateEnd = $calDateEnd->modify(sprintf('%+d days', $awDays));
                }
                // Get the raw time-only nanoseconds (H/M/S/ms/us/ns + day field converted).
                $absTimeOnlyNs = abs($timeNs) + (abs((int) $this->days) * $nsPerDay);
                $calEndY = (int) $calDateEnd->format('Y');
                $calEndM = (int) $calDateEnd->format('n');
                $calEndD = (int) $calDateEnd->format('j');
                // Balance raw time into DST-aware days before rounding.
                [$preDays, $preSubDayNs] = self::zdtBalanceTimeToDays(
                    $calEndY,
                    $calEndM,
                    $calEndD,
                    $zdtInfoRWR['hour'],
                    $zdtInfoRWR['minute'],
                    $zdtInfoRWR['second'],
                    $zdtInfoRWR['tzId'],
                    $absTimeOnlyNs,
                    0,
                    $sign,
                );
                // Round only the sub-day remainder.
                $roundedSubDayNs = self::roundNsPositive($preSubDayNs, $nsIncrement, $signedMode);
                // Check if the rounded value overflows the current day's length.
                $afterPreDaysDate = $calDateEnd->modify(sprintf('%+d days', $sign * $preDays));
                $adY = (int) $afterPreDaysDate->format('Y');
                $adM = (int) $afterPreDaysDate->format('n');
                $adD = (int) $afterPreDaysDate->format('j');
                [$extraDays, $absSubDayNs] = self::zdtBalanceTimeToDays(
                    $adY,
                    $adM,
                    $adD,
                    $zdtInfoRWR['hour'],
                    $zdtInfoRWR['minute'],
                    $zdtInfoRWR['second'],
                    $zdtInfoRWR['tzId'],
                    $roundedSubDayNs,
                    0,
                    $sign,
                );
                $roundedAbsDays = $preDays + $extraDays;
                // If time overflowed into an extra day, re-round the new remainder.
                if ($extraDays > 0) {
                    $absSubDayNs = self::roundNsPositive($absSubDayNs, $nsIncrement, $signedMode);
                    $afterAllDaysDate = $calDateEnd->modify(sprintf('%+d days', $sign * $roundedAbsDays));
                    $aaY = (int) $afterAllDaysDate->format('Y');
                    $aaM = (int) $afterAllDaysDate->format('n');
                    $aaD = (int) $afterAllDaysDate->format('j');
                    [$moreDays, $absSubDayNs] = self::zdtBalanceTimeToDays(
                        $aaY,
                        $aaM,
                        $aaD,
                        $zdtInfoRWR['hour'],
                        $zdtInfoRWR['minute'],
                        $zdtInfoRWR['second'],
                        $zdtInfoRWR['tzId'],
                        $absSubDayNs,
                        0,
                        $sign,
                    );
                    $roundedAbsDays += $moreDays;
                }
                // For the luIdx < 6 path (largestUnit < days), compute total rounded ns.
                $roundedAbsNs = (($roundedAbsDays + abs($calendarDays)) * $nsPerDay) + $absSubDayNs;
                // Re-add the calendar days to get the total day count for balanceDateDuration.
                $roundedDays = $sign * ($roundedAbsDays + abs($calendarDays));
                $subDayNs = $sign * $absSubDayNs;
            } else {
                $roundedAbsNs = self::roundNsPositive($absNs, $nsIncrement, $signedMode);
                $roundedNs = $sign * $roundedAbsNs;
                $roundedDays = intdiv(num1: $roundedNs, num2: $nsPerDay);
                $subDayNs = $roundedNs - ($roundedDays * $nsPerDay);
            }
        }

        // Balance calendar fields (years/months/weeks/days) from roundedDays.
        if ($luIdx >= 7) {
            // largestUnit is weeks, months, or years: split days into calendar units.
            [$ry, $rm, $rw, $rd] = self::balanceDateDuration($startDate, $roundedDays, $luIdx, $suIdx);
            // Distribute sub-day ns into time fields.
            [$rH, $rM, $rS, $rMs, $rUs, $rNs] = self::distributeSubDayNs($subDayNs);
        } elseif ($luIdx === 6) {
            // largestUnit is days: keep as-is, distribute sub-day to time fields.
            $ry = 0;
            $rm = 0;
            $rw = 0;
            $rd = $roundedDays;
            [$rH, $rM, $rS, $rMs, $rUs, $rNs] = self::distributeSubDayNs($subDayNs);
        } else {
            // largestUnit < days (hours, minutes, seconds, …): fold days into time units.
            // Use balanceNsToFields on the absolute rounded nanoseconds so that excess days
            // are absorbed by the largest time unit (e.g. hours = roundedDays * 24 + subDayH).
            [$rDaysFolded, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::balanceNsToFields($roundedAbsNs, $luIdx);
            $ry = 0;
            $rm = 0;
            $rw = 0;
            // Cast sign to float so that float * float avoids Psalm strict InvalidOperand errors.
            $signF = (float) $sign;
            $rd = $signF * (float) $rDaysFolded; // should be 0 when luIdx < 6
            $rH = $signF * (float) $rH;
            $rM = $signF * (float) $rM;
            $rS = $signF * (float) $rS;
            $rMs = $signF * (float) $rMs;
            $rUs = $signF * (float) $rUs;
            $rNs = $signF * (float) $rNs;
        }

        return new self($ry, $rm, $rw, $rd, $rH, $rM, $rS, $rMs, $rUs, $rNs);
    }

    /**
     * Distributes signed sub-day nanoseconds into time fields.
     * When largestUnit < days (idx < 6), folds days back into hours, etc.
     *
     * @param int $subDayNs Signed sub-day nanoseconds (−86_400_000_000_000 < subDayNs < 86_400_000_000_000).
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int} [h, min, s, ms, us, ns]
     */
    private static function distributeSubDayNs(int $subDayNs): array
    {
        $sign = $subDayNs >= 0 ? 1 : -1;
        $abs = abs($subDayNs);

        $rNs = $abs % 1_000;
        $abs = intdiv(num1: $abs, num2: 1_000);
        $rUs = $abs % 1_000;
        $abs = intdiv(num1: $abs, num2: 1_000);
        $rMs = $abs % 1_000;
        $abs = intdiv(num1: $abs, num2: 1_000);
        $rS = $abs % 60;
        $abs = intdiv(num1: $abs, num2: 60);
        $rM = $abs % 60;
        $abs = intdiv(num1: $abs, num2: 60);
        $rH = $abs; // remaining hours (< 24 for valid sub-day ns)

        return [$sign * $rH, $sign * $rM, $sign * $rS, $sign * $rMs, $sign * $rUs, $sign * $rNs];
    }

    /**
     * NudgeToCalendarUnit for smallestUnit = 'weeks'.
     * Finds the nearest week boundary relative to startDate and rounds.
     *
     * @param \DateTimeImmutable $startDate UTC midnight on relativeTo date.
     * @param int $totalNs Signed total nanoseconds from start to end.
     * @param int $nsPerDay Nanoseconds per day (86_400_000_000_000).
     * @param int $luIdx Largest unit index.
     * @param int $increment Rounding increment in weeks.
     * @param string $roundingMode TC39 rounding mode.
     * @param bool $isPositive Whether the duration is positive.
     */
    private function nudgeToCalendarWeeks(
        \DateTimeImmutable $startDate,
        int $totalNs,
        int $nsPerDay,
        int $luIdx,
        int $increment,
        string $roundingMode,
        bool $isPositive,
    ): self {
        $sign = $totalNs >= 0 ? 1 : -1;
        // Work with absolute nanoseconds throughout so that applyCalendarRounding
        // receives unsigned r1/r2 values (same pattern as nudgeToCalendarMonthsOrYears).
        $absNs = abs($totalNs);

        if ($luIdx >= 8) {
            // When largestUnit >= months: first count full calendar months from startDate
            // in the sign direction, then round the remaining fractional weeks.
            // The month count is not stored; only $current (the last whole-month boundary) matters.
            $current = $startDate;
            while (true) {
                $next = self::addMonthsClamped($current, $sign);
                $absNextNs = abs((int) $startDate->diff($next)->format('%r%a')) * $nsPerDay;
                if ($absNextNs > $absNs) {
                    break;
                }
                $current = $next;
            }
            // monthsSignedDays is signed (negative when going backward).
            $monthsSignedDays = (int) $startDate->diff($current)->format('%r%a');
            $absMonthsNs = abs($monthsSignedDays) * $nsPerDay;
            $absRemainingNs = $absNs - $absMonthsNs;

            // Round remaining fractional weeks using unsigned counts.
            $absRemainingDaysF = (float) $absRemainingNs / (float) $nsPerDay;
            $nLow = (int) floor($absRemainingDaysF / (7.0 * (float) $increment)) * $increment;
            $r1Ns = $absMonthsNs + ($nLow * 7 * $nsPerDay);
            $r2Ns = $absMonthsNs + (($nLow + $increment) * 7 * $nsPerDay);
            $denominator = $r2Ns - $r1Ns;
            $progress = $denominator === 0 ? 0.0 : (float) ($absNs - $r1Ns) / (float) $denominator;
            // $roundedWeeks is unsigned; sign is applied below.
            $roundedWeeks = self::applyCalendarRounding(
                $nLow,
                $nLow + $increment,
                $progress,
                $roundingMode,
                $isPositive,
            );
            $roundedDays = $monthsSignedDays + ($sign * $roundedWeeks * 7);

            [$ry, $rm, $rw, $rd] = self::balanceDateDuration($startDate, $roundedDays, $luIdx, 7);
            return new self($ry, $rm, $rw, $rd, 0, 0, 0, 0, 0, 0);
        }

        // largestUnit = weeks: pure week rounding from absolute total days.
        $absTotalDaysF = (float) $absNs / (float) $nsPerDay;
        $nLow = (int) floor($absTotalDaysF / (7.0 * (float) $increment)) * $increment;
        $r1Ns = $nLow * 7 * $nsPerDay;
        $r2Ns = ($nLow + $increment) * 7 * $nsPerDay;
        $denominator = $r2Ns - $r1Ns;
        $progress = $denominator === 0 ? 0.0 : (float) ($absNs - $r1Ns) / (float) $denominator;
        // $roundedWeeks is unsigned; sign applied below.
        $roundedWeeks = self::applyCalendarRounding($nLow, $nLow + $increment, $progress, $roundingMode, $isPositive);
        $roundedDays = $sign * $roundedWeeks * 7;

        [$ry, $rm, $rw, $rd] = self::balanceDateDuration($startDate, $roundedDays, $luIdx, 7);
        return new self($ry, $rm, $rw, $rd, 0, 0, 0, 0, 0, 0);
    }

    /**
     * NudgeToCalendarUnit for smallestUnit = 'months' or 'years'.
     * Finds the nearest month (or year) boundary and rounds.
     *
     * @param \DateTimeImmutable $startDate UTC midnight on relativeTo date.
     * @param int $totalNs Signed total nanoseconds from start to end.
     * @param int $nsPerDay Nanoseconds per day (fixed 86400e9).
     * @param int $suIdx Smallest unit index (8=months, 9=years).
     * @param int $luIdx Largest unit index.
     * @param int $increment Rounding increment in the smallest unit.
     * @param string $roundingMode TC39 rounding mode.
     * @param bool $isPositive Whether the duration is positive.
     * @param ?array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int} $zdtInfo ZDT info for DST-aware computation.
     */
    private function nudgeToCalendarMonthsOrYears(
        \DateTimeImmutable $startDate,
        int|float $totalNs,
        int $nsPerDay,
        int $suIdx,
        int $luIdx,
        int $increment,
        string $roundingMode,
        bool $isPositive,
        ?array $zdtInfo = null,
    ): self {
        // $totalNs is int|float to accommodate calendar progressions across
        // multi-millennium spans where days × NS_PER_DAY exceeds int64. The function
        // never decomposes $totalNs into days/sub-day components except in the ZDT
        // branch (which handles its own conversion against actual epoch seconds);
        // all other uses are sign checks and ratio comparisons that work for both
        // int and float operands.
        $sign = $totalNs >= 0 ? 1 : -1;

        // For ZDT with IANA timezone, recompute totalNs using actual epoch seconds
        // so DST transitions are accounted for.
        if ($zdtInfo !== null) {
            // Decompose $totalNs into (calendarDaysFromNs, timePartNs). The int branch
            // is the common case; the float branch is reached only when calendarDays ×
            // NS_PER_DAY exceeds int64 (~106k days, ~292 years).
            //
            // Static analyzers (Psalm, Mago) infer $totalNs as `int` from the caller's
            // own arithmetic and flag the float branch as dead. The runtime contract is
            // wider — `nudgeToCalendarMonthsOrYears` is widened to `int|float` for exactly
            // the overflow case the spec layer must support — so the suppressions are
            // correct. Keeping the suppressions next to the branch they describe rather
            // than the file head; remove them only if the analyzers learn that PHP's
            // int*int can overflow to float.
            /**
             * @psalm-suppress RedundantCondition
             */
            if (is_int($totalNs)) {
                $calendarDaysFromNs = intdiv($totalNs - ($totalNs % $nsPerDay), $nsPerDay);
                $timePartNs = $totalNs % $nsPerDay;
            } else {
                // @mago-ignore analysis:unreachable-else-clause
                // @mago-ignore analysis:no-value
                $nsPerDayF = (float) $nsPerDay;
                /** @psalm-suppress NoValue */
                $calendarDaysFromNs = (int) (($totalNs - fmod(num1: $totalNs, num2: $nsPerDayF)) / $nsPerDayF);
                /** @psalm-suppress NoValue */
                $timePartNs = (int) fmod(num1: $totalNs, num2: $nsPerDayF);
            }
            $actualDaysSec = (int) self::zdtDaysToSec(
                $zdtInfo['year'],
                $zdtInfo['month'],
                $zdtInfo['day'],
                $zdtInfo['hour'],
                $zdtInfo['minute'],
                $zdtInfo['second'],
                $zdtInfo['tzId'],
                $calendarDaysFromNs,
                $zdtInfo['epochSec'],
            );
            $totalNs = ($actualDaysSec * 1_000_000_000) + $timePartNs;
        }

        // Count full months (or years) from startDate that fit within totalNs.
        $isYears = $suIdx >= 9;

        $totalUnits = 0;
        $current = $startDate;
        while (true) {
            $next = $isYears ? self::addYearsClamped($current, $sign) : self::addMonthsClamped($current, $sign);
            // Check if the next boundary in ns is still <= totalNs (in absolute terms).
            $nextDays = (int) $startDate->diff($next)->format('%r%a');
            if ($zdtInfo !== null) {
                $nextSec = (int) self::zdtDaysToSec(
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $nextDays,
                    $zdtInfo['epochSec'],
                );
                $nextNs = $nextSec * 1_000_000_000;
            } else {
                $nextNs = $nextDays * $nsPerDay;
            }
            // Compare: if moving positive, $nextNs <= $totalNs; if negative, $nextNs >= $totalNs.
            if ($sign > 0 ? $nextNs > $totalNs : $nextNs < $totalNs) {
                break;
            }
            $totalUnits++;
            $current = $next;
        }

        // Snap to lower increment boundary.
        $nLow = intdiv(num1: $totalUnits, num2: $increment) * $increment;
        $r1 = $nLow;
        $r2 = $nLow + $increment;

        // Compute r1 and r2 dates relative to startDate using TC39-compliant clamped arithmetic.
        $r1Date = $isYears
            ? self::addYearsClamped($startDate, $sign * $r1)
            : self::addMonthsClamped($startDate, $sign * $r1);
        $r2Date = $isYears
            ? self::addYearsClamped($startDate, $sign * $r2)
            : self::addMonthsClamped($startDate, $sign * $r2);
        // The upper boundary may fall beyond the representable ISO date-time range
        // when the anchor sits near the limit; per TC39 RoundDuration this is a
        // RangeError. Keeps round() consistent
        // with the total() calendar path.
        self::assertCalendarBoundaryInRange($r2Date);
        $r1Days = (int) $startDate->diff($r1Date)->format('%r%a');
        $r2Days = (int) $startDate->diff($r2Date)->format('%r%a');
        if ($zdtInfo !== null) {
            $r1Ns =
                (int) self::zdtDaysToSec(
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $r1Days,
                    $zdtInfo['epochSec'],
                ) * 1_000_000_000;
            $r2Ns =
                (int) self::zdtDaysToSec(
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $r2Days,
                    $zdtInfo['epochSec'],
                ) * 1_000_000_000;
        } else {
            $r1Ns = $r1Days * $nsPerDay;
            $r2Ns = $r2Days * $nsPerDay;
        }

        $denominator = $r2Ns - $r1Ns;
        $progress = $denominator === 0 ? 0.0 : (float) ($totalNs - $r1Ns) / (float) $denominator;
        $roundedUnits = self::applyCalendarRounding($r1, $r2, $progress, $roundingMode, $isPositive);

        // Balance rounded units into the largestUnit.
        if ($suIdx >= 9) {
            // Rounding to years: result has only years (and possibly months if luIdx > 9, but luIdx max is 9).
            return new self($sign * $roundedUnits, 0, 0, 0, 0, 0, 0, 0, 0, 0);
        }

        // Rounding to months: balance months into years+months if luIdx >= 9.
        if ($luIdx >= 9) {
            // Convert months → years + remaining months.
            $absMonths = abs($roundedUnits);
            $years = intdiv(num1: $absMonths, num2: 12);
            $remainMonths = $absMonths % 12;
            return new self($sign * $years, $sign * $remainMonths, 0, 0, 0, 0, 0, 0, 0, 0);
        }

        return new self(0, $sign * $roundedUnits, 0, 0, 0, 0, 0, 0, 0, 0);
    }
}
