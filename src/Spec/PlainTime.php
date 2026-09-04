<?php

declare(strict_types=1);

namespace Calendrics\Spec;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\CalendarMath;
use Calendrics\Spec\Internal\EpochLimits;
use Calendrics\Spec\Internal\EpochRounding;
use Calendrics\Spec\Internal\FieldBag;
use Calendrics\Spec\Internal\HasPlainLocaleString;
use Calendrics\Spec\Internal\HasStringRepresentations;
use Calendrics\Spec\Internal\IsoFraction;
use Calendrics\Spec\Internal\Options;
use Calendrics\Spec\Internal\PlainLocaleFormattable;
use Stringable;

/**
 * A wall-clock time without a date or time zone.
 *
 * Stores the time as total nanoseconds since midnight (00:00:00.000000000).
 * Valid range: 00:00:00.000000000 – 23:59:59.999999999.
 *
 * @see https://tc39.es/proposal-temporal/#sec-temporal-plaintime-objects
 */
final class PlainTime implements PlainLocaleFormattable, Stringable
{
    use HasPlainLocaleString;
    use HasStringRepresentations;

    /**
     * The fields a PlainTime is built from. A time carries no calendar, so this list is
     * fixed — there are no CalendarExtraFields to add.
     *
     * @var list<string>
     */
    private const array TIME_FIELDS = [
        'hour',
        'minute',
        'second',
        'millisecond',
        'microsecond',
        'nanosecond',
    ];

    private const int NS_PER_HOUR = 3_600_000_000_000;
    private const int NS_PER_MINUTE = 60_000_000_000;
    private const int NS_PER_MS = 1_000_000;
    private const int NS_PER_US = 1_000;
    private const int NS_PER_DAY = 86_400_000_000_000;

    // -------------------------------------------------------------------------
    // Virtual (get-only) properties
    // -------------------------------------------------------------------------

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $hour {
        get => intdiv(num1: $this->ns, num2: self::NS_PER_HOUR);
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $minute {
        get => intdiv(num1: $this->ns % self::NS_PER_HOUR, num2: self::NS_PER_MINUTE);
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $second {
        get => intdiv(num1: $this->ns % self::NS_PER_MINUTE, num2: EpochLimits::NS_PER_SECOND);
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $millisecond {
        get => intdiv(num1: $this->ns % EpochLimits::NS_PER_SECOND, num2: self::NS_PER_MS);
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $microsecond {
        get => intdiv(num1: $this->ns % self::NS_PER_MS, num2: self::NS_PER_US);
    }

    /**
     * @var int<0, 999>
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $nanosecond {
        get => $this->ns % self::NS_PER_US;
    }

    // -------------------------------------------------------------------------
    // Internal storage
    // -------------------------------------------------------------------------

    /**
     * @var int<0, 86399999999999>
     * @psalm-suppress PropertyNotSetInConstructor — set unconditionally in constructor
     */
    private readonly int $ns;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param mixed $hour        0–23 after TC39 ToIntegerWithTruncation; null/omitted → 0.
     * @param mixed $minute      0–59 after coercion; null/omitted → 0.
     * @param mixed $second      0–59 after coercion; null/omitted → 0.
     * @param mixed $millisecond 0–999 after coercion; null/omitted → 0.
     * @param mixed $microsecond 0–999 after coercion; null/omitted → 0.
     * @param mixed $nanosecond  0–999 after coercion; null/omitted → 0.
     * @throws RangeError if any parameter is infinite or out of range.
     */
    public function __construct(
        mixed $hour = null,
        mixed $minute = null,
        mixed $second = null,
        mixed $millisecond = null,
        mixed $microsecond = null,
        mixed $nanosecond = null,
    ) {
        // TC39 ToIntegerWithTruncation: null/omitted → 0, bool → 0/1, string/float → truncated int.
        $h = CalendarMath::toConstructorInt($hour, 'PlainTime hour');
        $min = CalendarMath::toConstructorInt($minute, 'PlainTime minute');
        $sec = CalendarMath::toConstructorInt($second, 'PlainTime second');
        $ms = CalendarMath::toConstructorInt($millisecond, 'PlainTime millisecond');
        $us = CalendarMath::toConstructorInt($microsecond, 'PlainTime microsecond');
        $ns = CalendarMath::toConstructorInt($nanosecond, 'PlainTime nanosecond');

        CalendarMath::validateTimeFields($h, $min, $sec, $ms, $us, $ns);

        $this->ns =
            ($h * self::NS_PER_HOUR)
            + ($min * self::NS_PER_MINUTE)
            + ($sec * EpochLimits::NS_PER_SECOND)
            + ($ms * self::NS_PER_MS)
            + ($us * self::NS_PER_US)
            + $ns;
    }

    // -------------------------------------------------------------------------
    // Static factory / comparison methods
    // -------------------------------------------------------------------------

    /**
     * Creates a PlainTime from another PlainTime, a property-bag array, or an
     * ISO 8601 time string.
     *
     * String parsing rules:
     *   - Optional leading T/t prefix stripped.
     *   - HH:MM[:SS[.frac]] or HHMM[SS[.frac]] — fractional seconds up to 9 digits.
     *   - Full datetime strings (YYYY-MM-DDTHH:MM…): date portion is discarded.
     *   - UTC designator 'Z' in a time string is rejected (RangeError per spec).
     *   - Non-Z UTC offset (+HH:MM, −HH:MM etc.) after the time is ignored.
     *   - Bracket annotations validated (critical unknown, multiple TZ, etc.).
     *
     * Overflow option (default 'constrain'):
     *   - For strings: overflow is ignored; leap second 60 always normalizes to 59.
     *   - For property bags: 'constrain' clamps out-of-range values; 'reject' throws.
     *   - For PlainTime instances: overflow is ignored.
     *
     * @param self|string|array<array-key, mixed>|object $item PlainTime, ISO 8601 time string, or property-bag array.
     * @param mixed $options Options bag, null (omitted), or a primitive (TypeError); supports 'overflow' key.
     * @throws RangeError if the string is invalid or any field is out of range.
     * @throws TypeError if the type cannot be interpreted as a PlainTime.
     * @psalm-api
     */
    public static function from(string|array|object $item, mixed $options = []): self
    {
        if ($item instanceof self) {
            // ToTemporalTime step 2.a: GetOptionsObject then GetTemporalOverflowOption, before cloning.
            Options::overflowFromValue($options);
            return self::fromNs($item->ns);
        }
        if (is_string($item)) {
            // ToTemporalTime "Else" branch: ParseISODateTime (step 3.b) runs BEFORE
            // GetOptionsObject (step 3.d), so a bad string raises RangeError even when
            // the options argument is a bad primitive.
            $result = self::fromString($item);
            Options::overflowFromValue($options);
            return $result;
        }
        $item = FieldBag::forFields($item, self::TIME_FIELDS);
        // ToTemporalTime steps 2.c-2.e: read fields then validate options.
        $overflow = Options::overflowFromValue($options);
        return self::fromPropertyBag($item, $overflow);
    }

    /**
     * Compares two PlainTime values.
     *
     * @param self|string|array<array-key, mixed>|object $one PlainTime or ISO 8601 time string.
     * @param self|string|array<array-key, mixed>|object $two PlainTime or ISO 8601 time string.
     * @return int -1, 0, or 1.
     * @psalm-api
     */
    public static function compare(string|array|object $one, string|array|object $two): int
    {
        $a = $one instanceof self ? $one : self::from($one);
        $b = $two instanceof self ? $two : self::from($two);
        return $a->ns <=> $b->ns;
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Returns a new PlainTime with specified fields replaced.
     *
     * Only time fields (hour, minute, second, millisecond, microsecond, nanosecond)
     * are recognized; unrecognized keys are silently ignored.
     *
     * @param array<array-key, mixed>|object $fields
     * @param mixed        $options Options bag (['overflow' => ...]), null/primitive (TypeError), or omitted.
     * @throws RangeError if a field value is infinite or out of range.
     * @psalm-api
     */
    public function with(array|object $fields, mixed $options = []): self
    {
        // Reject Temporal objects (IsPartialTemporalObject step 2).
        if (
            $fields instanceof self
            || $fields instanceof PlainDate
            || $fields instanceof PlainDateTime
            || $fields instanceof PlainYearMonth
            || $fields instanceof PlainMonthDay
            || $fields instanceof ZonedDateTime
            || $fields instanceof Instant
            || $fields instanceof Duration
        ) {
            throw new TypeError('PlainTime::with() argument must not be a Temporal object.');
        }

        // A Symbol (JsSymbol sentinel) reaching here is a non-object primitive in TC39
        // terms (IsPartialTemporalObject step 2 → TypeError). It is \Stringable in PHP,
        // so reject it explicitly before treating $fields as a property bag.
        if ($fields instanceof Stringable) {
            throw new TypeError('PlainTime::with() argument must be an object.');
        }

        $fields = FieldBag::forPartial($fields, self::TIME_FIELDS, null);

        // RejectObjectWithCalendarOrTimeZone: calendar/timeZone keys are date-specific
        // and must not appear in a PlainTime fields bag.
        if (array_key_exists('calendar', $fields) || array_key_exists('timeZone', $fields)) {
            throw new TypeError('PlainTime::with() fields must not contain a calendar or timeZone property.');
        }

        // PrepareCalendarFields step 10 (partial): at least one recognized time field
        // must be present; an object with no recognized keys is not a valid partial time.
        $hasAnyField =
            array_key_exists('hour', $fields)
            || array_key_exists('minute', $fields)
            || array_key_exists('second', $fields)
            || array_key_exists('millisecond', $fields)
            || array_key_exists('microsecond', $fields)
            || array_key_exists('nanosecond', $fields);
        if (!$hasAnyField) {
            throw new TypeError(
                'PlainTime::with() requires at least one of: hour, minute, second, millisecond, microsecond, nanosecond.',
            );
        }

        // TC39 ToTemporalTimeRecord reads and coerces the partial time fields BEFORE
        // GetOptionsObject validates the options argument's type. So a bad field value
        // (e.g. {minute: Infinity}) raises its RangeError before a primitive options
        // argument's TypeError. The field reading/coercion therefore runs first; the
        // overflow keyword (which only drives regulation) is resolved afterward.
        $h = $this->hour;
        $min = $this->minute;
        $sec = $this->second;
        $ms = $this->millisecond;
        $us = $this->microsecond;
        $ns = $this->nanosecond;

        if (array_key_exists('hour', $fields)) {
            $h = CalendarMath::toFiniteInt($fields['hour'], 'PlainTime::with() hour');
        }
        if (array_key_exists('minute', $fields)) {
            $min = CalendarMath::toFiniteInt($fields['minute'], 'PlainTime::with() minute');
        }
        if (array_key_exists('second', $fields)) {
            $sec = CalendarMath::toFiniteInt($fields['second'], 'PlainTime::with() second');
        }
        if (array_key_exists('millisecond', $fields)) {
            $ms = CalendarMath::toFiniteInt($fields['millisecond'], 'PlainTime::with() millisecond');
        }
        if (array_key_exists('microsecond', $fields)) {
            $us = CalendarMath::toFiniteInt($fields['microsecond'], 'PlainTime::with() microsecond');
        }
        if (array_key_exists('nanosecond', $fields)) {
            $ns = CalendarMath::toFiniteInt($fields['nanosecond'], 'PlainTime::with() nanosecond');
        }

        // GetOptionsObject + GetTemporalOverflowOption: explicit null / non-object
        // primitive / Symbol => TypeError; omitted ([]) defaults to 'constrain';
        // an explicit `overflow => null` value coerces to neither keyword => RangeError.
        $overflow = Options::overflowFromValue($options);

        if ($overflow === 'constrain') {
            $h = max(0, min(23, $h));
            $min = max(0, min(59, $min));
            $sec = max(0, min(59, $sec));
            $ms = max(0, min(999, $ms));
            $us = max(0, min(999, $us));
            $ns = max(0, min(999, $ns));
        } else {
            CalendarMath::validateTimeFields($h, $min, $sec, $ms, $us, $ns);
        }

        return new self($h, $min, $sec, $ms, $us, $ns);
    }

    /**
     * Returns a new PlainTime advanced by the given duration.
     *
     * Calendar fields (years, months, weeks, days) are silently ignored — PlainTime
     * has no calendar context and only time fields are applied.
     *
     * @param Duration|string|array<array-key, mixed>|object $duration Duration, ISO 8601 duration string, or property-bag array.
     * @psalm-api
     */
    public function add(string|array|object $duration): self
    {
        $d = $duration instanceof Duration ? $duration : Duration::from($duration);
        return $this->addTimeFields(1, $d);
    }

    /**
     * Returns a new PlainTime moved back by the given duration.
     *
     * Calendar fields (years, months, weeks, days) are silently ignored.
     *
     * @param Duration|string|array<array-key,mixed>|object $duration Duration, ISO 8601 duration string, or property-bag array.
     * @psalm-api
     */
    public function subtract(string|array|object $duration): self
    {
        $d = $duration instanceof Duration ? $duration : Duration::from($duration);
        return $this->addTimeFields(-1, $d);
    }

    /**
     * Returns the Duration from this time to $other (other − this).
     *
     * The result is always positive (or zero) when $other > $this within the same day.
     * Options: largestUnit, smallestUnit, roundingMode, roundingIncrement.
     *
     * @param self|string|array<array-key, mixed>|object $other   PlainTime or ISO 8601 time string.
     * @param array<array-key, mixed>|object|null $options Options array or null.
     * @throws RangeError for invalid option values.
     * @psalm-api
     */
    public function until(string|array|object $other, mixed $options = []): Duration
    {
        $o = $other instanceof self ? $other : self::from($other);
        $diffNs = $o->ns - $this->ns;
        return self::diffTime($diffNs, $options);
    }

    /**
     * Returns the Duration from $other to this time (this − other).
     *
     * Options: largestUnit, smallestUnit, roundingMode, roundingIncrement.
     *
     * @param self|string|array<array-key, mixed>|object $other   PlainTime or ISO 8601 time string.
     * @param array<array-key, mixed>|object|null $options Options array or null.
     * @throws RangeError for invalid option values.
     * @psalm-api
     */
    public function since(string|array|object $other, mixed $options = []): Duration
    {
        $o = $other instanceof self ? $other : self::from($other);
        $diffNs = $this->ns - $o->ns;
        return self::diffTime($diffNs, $options);
    }

    /**
     * Returns a new PlainTime rounded to the given unit and increment.
     *
     * @param string|array<array-key, mixed>|object $options string smallestUnit or array with keys:
     *   - smallestUnit (required): 'hour'|'minute'|'second'|'millisecond'|'microsecond'|'nanosecond'
     *   - roundingMode (default 'halfExpand'): 'trunc'|'floor'|'ceil'|'expand'|'halfExpand'|
     *                                          'halfTrunc'|'halfFloor'|'halfCeil'|'halfEven'
     *   - roundingIncrement (default 1)
     * @throws TypeError if options are not a string, array, or object.
     * @throws RangeError for invalid option values.
     * @psalm-api
     */
    public function round(string|array|object $options): self
    {
        if (is_string($options)) {
            $options = ['smallestUnit' => $options];
        } elseif (is_object($options)) {
            // TC39: if options is undefined, throw TypeError (required arg).
            if ($options instanceof \Stringable) {
                $str = (string) $options; // JsSymbol: throws; JsUndefined: returns 'undefined'
                if ($str === 'undefined') {
                    throw new TypeError('PlainTime::round() requires a non-undefined options argument.');
                }
            }
            $options = Options::requireObject($options, ['roundingIncrement', 'roundingMode', 'smallestUnit']);
        }

        /** @var mixed $suRaw */
        $suRaw = $options['smallestUnit'] ?? null;
        if ($suRaw === null) {
            throw new RangeError('Calendrics\\PlainTime::round() requires smallestUnit.');
        }
        $suRaw = Options::coerceEnumOption($suRaw, 'smallestUnit');

        // ns-per-unit → max increment (exclusive: must be strictly less than this)
        // The max is the number of units in the next-larger unit (for rounding to divide evenly).
        $unitMap = [
            'nanosecond' => [1, 1_000],
            'nanoseconds' => [1, 1_000],
            'microsecond' => [1_000, 1_000],
            'microseconds' => [1_000, 1_000],
            'millisecond' => [1_000_000, 1_000],
            'milliseconds' => [1_000_000, 1_000],
            'second' => [1_000_000_000, 60],
            'seconds' => [1_000_000_000, 60],
            'minute' => [60_000_000_000, 60],
            'minutes' => [60_000_000_000, 60],
            'hour' => [3_600_000_000_000, 24],
            'hours' => [3_600_000_000_000, 24],
        ];
        if (!array_key_exists($suRaw, $unitMap)) {
            throw new RangeError("Invalid smallestUnit \"{$suRaw}\" for Calendrics\\PlainTime::round().");
        }
        [$nsPerUnit, $maxIncrement] = $unitMap[$suRaw];

        $roundingMode = 'halfExpand';
        if (array_key_exists('roundingMode', $options) && $options['roundingMode'] !== null) {
            $rmRaw = Options::coerceEnumOption($options['roundingMode'], 'roundingMode');
            $roundingMode = $rmRaw;
        }

        $increment = 1;
        if (array_key_exists('roundingIncrement', $options) && $options['roundingIncrement'] !== null) {
            // Per TC39 ToTemporalRoundingIncrement: GetOption with type «Number» calls ToNumber,
            // which coerces booleans/numeric strings. CalendarMath::toFiniteInt mirrors that.
            $rawIncrement = CalendarMath::toFiniteInt($options['roundingIncrement'], 'roundingIncrement');
            if ($rawIncrement < 1) {
                throw new RangeError('roundingIncrement must be a positive integer.');
            }
            $increment = $rawIncrement;
        }
        // Increment must be strictly less than maxIncrement and must divide it evenly.
        if ($increment >= $maxIncrement || ($maxIncrement % $increment) !== 0) {
            throw new RangeError("roundingIncrement {$increment} is invalid for unit \"{$suRaw}\".");
        }

        $nsIncrement = $nsPerUnit * $increment;
        // Round $this->ns (always non-negative) using the given mode.
        $rounded = EpochRounding::roundAsIfPositive($this->ns, $nsIncrement, $roundingMode);
        // Wrap modulo one day (rounded could reach exactly NS_PER_DAY).
        $rounded %= self::NS_PER_DAY;
        return self::fromNs($rounded);
    }

    /**
     * Returns true if this PlainTime represents the same time as $other.
     *
     * @param self|string|array<array-key, mixed>|object $other A PlainTime or ISO 8601 time string.
     * @psalm-api
     */
    public function equals(string|array|object $other): bool
    {
        $o = $other instanceof self ? $other : self::from($other);
        return $this->ns === $o->ns;
    }

    /**
     * Returns an ISO 8601 time string: HH:MM:SS[.fraction].
     *
     * Options (all optional):
     *   - fractionalSecondDigits: 'auto' (default) | 0–9
     *     'auto' strips trailing zeros from the sub-second part, but always includes seconds.
     *   - smallestUnit: 'minute'|'second'|'millisecond'|'microsecond'|'nanosecond'
     *     Overrides fractionalSecondDigits.
     *   - roundingMode: rounding mode (default 'trunc').
     *     When combined with smallestUnit/fractionalSecondDigits, rounds the time before output.
     *
     * @param array<array-key, mixed>|object|null $options null, an array of options, or any object (treated as empty options bag).
     * @throws RangeError if options are invalid.
     * @throws TypeError if $options is a non-null, non-array, non-object scalar.
     * @psalm-api
     */
    #[\Override]
    public function toString(mixed $options = []): string
    {
        // GetOptionsObject: explicit null / non-object primitive / Symbol => TypeError.
        // An omitted options argument arrives as the empty-array default.
        $options = Options::requireObject($options, ['fractionalSecondDigits', 'roundingMode', 'smallestUnit']);

        // $digits: -2 = 'auto', -1 = minute format (no seconds), 0-9 = fixed digits.
        $digits = -2;
        $isMinute = false;
        $roundingMode = 'trunc'; // default: truncate

        if ($options !== []) {
            if (array_key_exists('fractionalSecondDigits', $options)) {
                $fsd = Options::fractionalSecondDigits($options['fractionalSecondDigits']);
                if ($fsd !== null) {
                    $digits = $fsd;
                }
            }

            // smallestUnit overrides fractionalSecondDigits.
            if (array_key_exists('smallestUnit', $options) && $options['smallestUnit'] !== null) {
                $suRaw = Options::coerceEnumOption($options['smallestUnit'], 'smallestUnit');
                $su = $suRaw;
                [$digits, $isMinute] = match ($su) {
                    'minute', 'minutes' => [-1, true],
                    'second', 'seconds' => [0, false],
                    'millisecond', 'milliseconds' => [3, false],
                    'microsecond', 'microseconds' => [6, false],
                    'nanosecond', 'nanoseconds' => [9, false],
                    default => throw new RangeError("Invalid smallestUnit \"{$su}\"."),
                };
            }

            // roundingMode option.
            if (array_key_exists('roundingMode', $options) && $options['roundingMode'] !== null) {
                $rm = Options::coerceEnumOption($options['roundingMode'], 'roundingMode');
                $roundingMode = Options::roundingMode($rm);
            }
        }

        // Determine nsIncrement from $digits: 10^(9-digits) nanoseconds.
        // digits=-2 ('auto'): nanosecond precision (no rounding changes display).
        // digits=-1 ('minute'): minute precision.
        // digits=0..9: second or sub-second precision.
        $nsIncrement = match ($digits) {
            -2, 9 => 1, // nanosecond precision; rounding=trunc is a no-op when -2
            -1 => self::NS_PER_MINUTE,
            0 => 1_000_000_000,
            1 => 100_000_000,
            2 => 10_000_000,
            3 => 1_000_000,
            4 => 100_000,
            5 => 10_000,
            6 => 1_000,
            7 => 100,
            default => 10, // only remaining case is 8
        };

        // Round the nanoseconds (always non-negative).
        $nsToFormat = EpochRounding::roundAsIfPositive($this->ns, $nsIncrement, $roundingMode);
        // Wrap modulo one day (rounding could reach exactly NS_PER_DAY for ceil-like modes).
        $nsToFormat %= self::NS_PER_DAY;

        $h = intdiv(num1: $nsToFormat, num2: self::NS_PER_HOUR);
        $rem = $nsToFormat % self::NS_PER_HOUR;
        $min = intdiv(num1: $rem, num2: self::NS_PER_MINUTE);
        $rem %= self::NS_PER_MINUTE;
        $sec = intdiv(num1: $rem, num2: EpochLimits::NS_PER_SECOND);
        // Sub-second nanoseconds (0–999_999_999).
        $subNs = $rem % EpochLimits::NS_PER_SECOND;

        if ($isMinute) {
            return sprintf('%02d:%02d', $h, $min);
        }

        $base = sprintf('%02d:%02d:%02d', $h, $min, $sec);

        if ($digits === -2) {
            // 'auto': omit sub-seconds when zero, otherwise strip trailing zeros.
            if ($subNs === 0) {
                return $base;
            }
            $fraction = rtrim(sprintf('%09d', $subNs), characters: '0');
            return "{$base}.{$fraction}";
        }

        if ($digits === 0) {
            return $base;
        }

        $fraction = substr(string: sprintf('%09d', $subNs), offset: 0, length: $digits);
        return "{$base}.{$fraction}";
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Internal factory: create a PlainTime from total nanoseconds since midnight.
     * $nsTotal must be in [0, NS_PER_DAY).
     */
    private static function fromNs(int $nsTotal): self
    {
        $h = intdiv(num1: $nsTotal, num2: self::NS_PER_HOUR);
        $rem = $nsTotal % self::NS_PER_HOUR;
        $min = intdiv(num1: $rem, num2: self::NS_PER_MINUTE);
        $rem %= self::NS_PER_MINUTE;
        $sec = intdiv(num1: $rem, num2: EpochLimits::NS_PER_SECOND);
        $rem %= EpochLimits::NS_PER_SECOND;
        $ms = intdiv(num1: $rem, num2: self::NS_PER_MS);
        $rem %= self::NS_PER_MS;
        $us = intdiv(num1: $rem, num2: self::NS_PER_US);
        $ns = $rem % self::NS_PER_US;
        return new self($h, $min, $sec, $ms, $us, $ns);
    }

    /**
     * Parses an ISO 8601 time string (or a full datetime string, discarding the date).
     *
     * Accepted time-only formats:
     *   HH:MM
     *   HH:MM:SS
     *   HH:MM:SS.fraction    (comma or dot; 1–9 digits)
     *   HHMM
     *   HHMMSS
     *   HHMMSS.fraction
     *   HH (hours only)
     *   THH:MM:SS...         (leading T/t stripped)
     *   T/tHHMMSS...         (leading T/t stripped, compact)
     *
     * Full datetime format (date + T + time + optional offset + optional annotations)
     * is also accepted; only the time portion is used.
     *
     * UTC designator 'Z' is REJECTED — strings with a bare 'Z' offset throw.
     *
     * @throws RangeError for invalid strings or out-of-range fields.
     */
    private static function fromString(string $s): self
    {
        if ($s === '') {
            throw new RangeError('PlainTime::from() received an empty string.');
        }
        // Reject non-ASCII minus sign (U+2212 = \xe2\x88\x92).
        if (str_contains($s, "\u{2212}")) {
            throw new RangeError("PlainTime::from() cannot parse \"{$s}\": non-ASCII minus sign is not allowed.");
        }
        // Reject more than 9 fractional-second digits.
        if (preg_match('/[.,]\d{10,}/', $s) === 1) {
            throw new RangeError(
                "PlainTime::from() cannot parse \"{$s}\": fractional seconds may have at most 9 digits.",
            );
        }

        // Try full datetime first (YYYY-MM-DDTHH:...) to extract the time portion.
        // Compact date variants: YYYYMMDD, ±YYYYYYMMDD.
        // After the time, an optional UTC offset is allowed — but NOT bare 'Z'.
        // Offset hours restricted to 00-23; minutes/seconds to 00-59.
        $offsetHH = '(?:[01]\d|2[0-3])';
        $offsetMM = '[0-5]\d';
        $offsetSS = '[0-5]\d';
        $offsetPattern = sprintf(
            '[+-]%s(?::%s(?::%s(?:[.,]\d+)?)?|%s(?:%s(?:[.,]\d+)?)?)?',
            $offsetHH,
            $offsetMM,
            $offsetSS,
            $offsetMM,
            $offsetSS,
        );

        $fullDatetimePattern = sprintf(
            '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2}|\d{4})[T ](\d{2}):?(\d{2})(?::?(\d{2})([.,]\d+)?)?(?:Z|%s)?((?:\[[^\]]*\])*)$/i',
            $offsetPattern,
        );

        /** @var list<string> $m */
        $m = [];
        if (preg_match($fullDatetimePattern, $s, $m) === 1) {
            // Reject minus-zero extended year (-000000).
            if ($m[1] === '-000000') {
                throw new RangeError("PlainTime::from() cannot parse \"{$s}\": -000000 year is not allowed.");
            }
            $annotationSection = $m[7] !== '' ? $m[7] : '';
            // Reject if the string has a bare 'Z' UTC designator (before any bracket annotations).
            // A 'Z' immediately followed by '[', end-of-string, or whitespace in the non-annotation part.
            if (preg_match('/Z(?:\[|$)/i', $s) === 1) {
                throw new RangeError("PlainTime::from() cannot parse \"{$s}\": UTC designator 'Z' is not allowed.");
            }
            CalendarMath::validateAnnotations($annotationSection, $s, false);

            $hourNum = (int) $m[3];
            $minNum = (int) $m[4];
            $secNum = $m[5] !== '' ? (int) $m[5] : 0;
            // Leap second 60 maps to 59.
            if ($secNum === 60) {
                $secNum = 59;
            }
            $fracRaw = $m[6] !== '' ? $m[6] : '';
            $subNs = $fracRaw !== '' ? IsoFraction::toNanoseconds($fracRaw) : 0;

            CalendarMath::validateTimeFields($hourNum, $minNum, $secNum, 0, 0, 0);

            $totalNs =
                ($hourNum * self::NS_PER_HOUR)
                + ($minNum * self::NS_PER_MINUTE)
                + ($secNum * EpochLimits::NS_PER_SECOND)
                + $subNs;

            return self::fromNs($totalNs);
        }

        // Try pure time string (with optional T prefix and optional offset/annotations).
        // Strip leading T/t.
        $timeStr = $s;
        $hasTimeDesignator = false;
        if (str_starts_with($timeStr, 'T') || str_starts_with($timeStr, 't')) {
            $timeStr = substr(string: $timeStr, offset: 1);
            $hasTimeDesignator = true;
        }

        // TC39 13.31.3 (TimeSpecWithOptionalOffsetNotAmbiguous Early Errors): a bare time
        // string without a 'T' designator must NOT be parseable as DateSpecYearMonth or
        // DateSpecMonthDay. Such strings (e.g. "2021-12", "1214", "12-14") are reserved for
        // PlainYearMonth/PlainMonthDay; without the disambiguating 'T' they are a RangeError.
        if (!$hasTimeDesignator && self::isAmbiguousWithDate($timeStr)) {
            throw new RangeError(sprintf(
                'PlainTime::from() cannot parse "%s": this string is ambiguous with a date (PlainYearMonth/PlainMonthDay) and requires a \'T\' time designator.',
                $s,
            ));
        }

        // Pattern: HH:MM[:SS[.frac]][offset][annotations]  (colon-separated)
        //       or HHMM[SS[.frac]][offset][annotations]     (compact, no colons)
        //       or HH[offset][annotations]                  (hours only)
        // offset: NOT bare Z; [+-]HH variants only.
        // For pure time strings, Z is also rejected.

        // Check for bare Z before trying to match (reject first).
        // A 'Z' immediately after the time digits (before any bracket) is invalid.
        if (preg_match('/\d[Zz](\[|$)/', $timeStr) === 1 || preg_match('/\d[Zz]$/', $timeStr) === 1) {
            throw new RangeError("PlainTime::from() cannot parse \"{$s}\": UTC designator 'Z' is not allowed.");
        }

        // Colon-separated format: HH:MM[:SS[.frac]][offset][annotations]
        $colonTimePattern = sprintf(
            '/^(\d{2}):(\d{2})(?::(\d{2})([.,]\d+)?)?(?:%s)?((?:\[[^\]]*\])*)$/i',
            $offsetPattern,
        );

        // Compact format: HHMMSS[.frac][offset][annotations] or HHMM[offset][annotations] or HH[offset][annotations]
        $compactTimePattern = sprintf('/^(\d{2})(\d{2})?(\d{2})?([.,]\d+)?(?:%s)?((?:\[[^\]]*\])*)$/i', $offsetPattern);

        /** @var list<string> $m2 */
        $m2 = [];
        if (preg_match($colonTimePattern, $timeStr, $m2) === 1) {
            $annotationSection = $m2[5] !== '' ? $m2[5] : '';
            CalendarMath::validateAnnotations($annotationSection, $s, false);

            $hourNum = (int) $m2[1];
            $minNum = (int) $m2[2];
            $secNum = $m2[3] !== '' ? (int) $m2[3] : 0;
            if ($secNum === 60) {
                $secNum = 59;
            }
            $fracRaw = $m2[4] !== '' ? $m2[4] : '';
            $subNs = $fracRaw !== '' ? IsoFraction::toNanoseconds($fracRaw) : 0;

            CalendarMath::validateTimeFields($hourNum, $minNum, $secNum, 0, 0, 0);

            $totalNs =
                ($hourNum * self::NS_PER_HOUR)
                + ($minNum * self::NS_PER_MINUTE)
                + ($secNum * EpochLimits::NS_PER_SECOND)
                + $subNs;

            return self::fromNs($totalNs);
        }

        /** @var list<string> $m3 */
        $m3 = [];
        if (preg_match($compactTimePattern, $timeStr, $m3) === 1) {
            // Compact: m3[1]=HH, m3[2]=MM (or ''), m3[3]=SS (or ''), m3[4]=.frac (or ''), m3[5]=annotations
            $hourNum = (int) $m3[1];
            $minNum = $m3[2] !== '' ? (int) $m3[2] : 0;
            $secNum = $m3[3] !== '' ? (int) $m3[3] : 0;
            if ($secNum === 60) {
                $secNum = 59;
            }
            $fracRaw = $m3[4] !== '' ? $m3[4] : '';
            $subNs = $fracRaw !== '' ? IsoFraction::toNanoseconds($fracRaw) : 0;
            $annotationSection = $m3[5] !== '' ? $m3[5] : '';
            CalendarMath::validateAnnotations($annotationSection, $s, false);

            // If MM was not provided (hours-only), minNum stays 0; that's fine.
            // Disallow fractional seconds without HHMMSS (e.g., HH.frac or HHMM.frac is invalid).
            if ($m3[3] === '' && $fracRaw !== '') {
                throw new RangeError("PlainTime::from() cannot parse \"{$s}\": invalid ISO 8601 time string.");
            }

            CalendarMath::validateTimeFields($hourNum, $minNum, $secNum, 0, 0, 0);

            $totalNs =
                ($hourNum * self::NS_PER_HOUR)
                + ($minNum * self::NS_PER_MINUTE)
                + ($secNum * EpochLimits::NS_PER_SECOND)
                + $subNs;

            return self::fromNs($totalNs);
        }

        throw new RangeError("PlainTime::from() cannot parse \"{$s}\": invalid ISO 8601 time string.");
    }

    /**
     * Determines whether a bare (no-'T') time string is also a valid ISO 8601 date
     * specification, in which case it is ambiguous and must be rejected per TC39 13.31.3.
     *
     * Tests the non-annotation prefix against the DateSpecYearMonth and DateSpecMonthDay
     * grammar productions:
     *   - DateSpecYearMonth: YYYY-MM | YYYYMM      (month 01–12)
     *   - DateSpecMonthDay:  --MM-DD | --MMDD | MM-DD | MMDD
     *                        (month 01–12, day valid for that month in a leap year)
     *
     * Any trailing bracketed annotations (e.g. "[UTC]", "[u-ca=iso8601]") are ignored:
     * they do not affect whether the numeric body is a date specification.
     */
    private static function isAmbiguousWithDate(string $timeStr): bool
    {
        // Split off trailing bracketed annotations; only the numeric body is relevant.
        $body = preg_replace(pattern: '/(?:\[[^\]]*\])+$/', replacement: '', subject: $timeStr);
        if ($body === null || $body === '') {
            return false;
        }

        // DateSpecYearMonth: four-digit year, optional hyphen, two-digit month 01–12.
        /** @var list<string> $ym */
        $ym = [];
        if (preg_match('/^\d{4}-?(\d{2})$/', $body, $ym) === 1) {
            $month = (int) $ym[1];
            return $month >= 1 && $month <= 12;
        }

        // DateSpecMonthDay: optional leading "--", two-digit month, optional hyphen, two-digit day.
        /** @var list<string> $md */
        $md = [];
        if (preg_match('/^(?:--)?(\d{2})-?(\d{2})$/', $body, $md) === 1) {
            $month = (int) $md[1];
            $day = (int) $md[2];
            if ($month < 1 || $month > 12 || $day < 1) {
                return false;
            }
            // Reference ISO year 1972 (a leap year), matching PlainMonthDay, so Feb 29 is valid.
            return $day <= CalendarMath::calcDaysInMonth(1972, $month);
        }

        return false;
    }

    /**
     * Creates a PlainTime from a property-bag array.
     *
     * Required key: 'hour'. Optional: 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'.
     *
     * @param array<array-key, mixed> $bag
     * @param string                  $overflow 'constrain' or 'reject'
     * @throws TypeError if required fields are missing or have wrong type.
     * @throws RangeError if field values are out of range (with overflow='reject').
     */
    private static function fromPropertyBag(array $bag, string $overflow): self
    {
        // Per TC39 PrepareCalendarFields with requiredFieldNames=~partial~: at least one
        // recognized time field must be present with a non-undefined value; missing fields
        // default to 0.
        $hasAny = false;
        foreach (['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'] as $field) {
            if (!array_key_exists($field, $bag) || $bag[$field] === null) {
                continue;
            }
            $hasAny = true;
            break;
        }
        if (!$hasAny) {
            throw new TypeError('PlainTime property bag must have at least one time field.');
        }

        $h = CalendarMath::extractIntField($bag, 'hour', 0, 'PlainTime');
        $min = CalendarMath::extractIntField($bag, 'minute', 0, 'PlainTime');
        $sec = CalendarMath::extractIntField($bag, 'second', 0, 'PlainTime');
        $ms = CalendarMath::extractIntField($bag, 'millisecond', 0, 'PlainTime');
        $us = CalendarMath::extractIntField($bag, 'microsecond', 0, 'PlainTime');
        $ns = CalendarMath::extractIntField($bag, 'nanosecond', 0, 'PlainTime');

        if ($overflow === 'constrain') {
            $h = max(0, min(23, $h));
            $min = max(0, min(59, $min));
            $sec = max(0, min(59, $sec));
            $ms = max(0, min(999, $ms));
            $us = max(0, min(999, $us));
            $ns = max(0, min(999, $ns));
        } else {
            CalendarMath::validateTimeFields($h, $min, $sec, $ms, $us, $ns);
        }

        return new self($h, $min, $sec, $ms, $us, $ns);
    }

    /**
     * Adds (sign=+1) or subtracts (sign=-1) Duration time fields to this time,
     * wrapping around the day boundary.
     *
     * Calendar fields (years, months, weeks, days) are ignored per spec.
     * Each time component is reduced modulo its day-count before multiplication to
     * avoid int64 overflow with very large Duration field values.
     */
    private function addTimeFields(int $sign, Duration $d): self
    {
        // Reduce each field modulo its day-cycle count before multiplying, to prevent int64 overflow.
        // NS_PER_DAY / NS_PER_HOUR = 24; / NS_PER_MINUTE = 1440; / NS_PER_SECOND = 86400; etc.
        $hNs = ((int) $d->hours % 24) * self::NS_PER_HOUR;
        $minNs = ((int) $d->minutes % 1_440) * self::NS_PER_MINUTE;
        $secNs = ((int) $d->seconds % 86_400) * EpochLimits::NS_PER_SECOND;
        $msNs = ((int) $d->milliseconds % 86_400_000) * self::NS_PER_MS;
        $usNs = ((int) $d->microseconds % 86_400_000_000) * self::NS_PER_US;
        $nsNs = (int) $d->nanoseconds % self::NS_PER_DAY;

        // Sum each reduced component (each is in (-NS_PER_DAY, NS_PER_DAY)).
        // Use modular addition step-by-step to stay within int64 range.
        $deltaNs = $hNs + $minNs + $secNs + $msNs + $usNs + $nsNs;

        $resultNs = $this->ns + ($sign * $deltaNs);

        // Wrap to [0, NS_PER_DAY) using true modulo (PHP's % can be negative).
        $resultNs = (($resultNs % self::NS_PER_DAY) + self::NS_PER_DAY) % self::NS_PER_DAY;

        return self::fromNs($resultNs);
    }

    /**
     * Core implementation for since() and until().
     *
     * Balances a nanosecond difference (possibly negative) into a Duration according
     * to largestUnit, then rounds to smallestUnit with the given mode and increment.
     *
     * @param array<array-key, mixed>|object|null $options Options array or null. Keys: largestUnit, smallestUnit, roundingMode, roundingIncrement.
     */
    private static function diffTime(int $diffNs, array|object|null $options): Duration
    {
        /** @var list<string> $validUnits */
        static $validUnits = [
            'auto',
            'hour',
            'hours',
            'minute',
            'minutes',
            'second',
            'seconds',
            'millisecond',
            'milliseconds',
            'microsecond',
            'microseconds',
            'nanosecond',
            'nanoseconds',
        ];

        $largestUnit = 'hour'; // default per TC39 for PlainTime
        $smallestUnit = 'nanosecond'; // default: no rounding
        $roundingMode = 'trunc'; // default for since/until
        $roundingIncrement = 1;

        // GetOptionsObject: explicit null / non-object primitive / Symbol => TypeError.
        // An omitted options argument arrives as the empty-array default.
        $opts = Options::requireObject($options, ['largestUnit', 'roundingIncrement', 'roundingMode', 'smallestUnit']);

        if ($opts !== []) {
            if (array_key_exists('largestUnit', $opts)) {
                /** @var mixed $lu */
                $lu = $opts['largestUnit'];
                if ($lu !== null) {
                    $lu = Options::coerceEnumOption($lu, 'largestUnit');
                }
                if (is_string($lu)) {
                    if (!in_array($lu, $validUnits, strict: true)) {
                        throw new RangeError("Invalid largestUnit value: \"{$lu}\".");
                    }
                    $largestUnit = match ($lu) {
                        'auto', 'hours' => 'hour',
                        'minutes' => 'minute',
                        'seconds' => 'second',
                        'milliseconds' => 'millisecond',
                        'microseconds' => 'microsecond',
                        'nanoseconds' => 'nanosecond',
                        default => $lu,
                    };
                }
            }

            if (array_key_exists('smallestUnit', $opts)) {
                /** @var mixed $su */
                $su = $opts['smallestUnit'];
                if ($su !== null) {
                    $su = Options::coerceEnumOption($su, 'smallestUnit');
                }
                if (is_string($su)) {
                    // Valid smallestUnit values for PlainTime (no calendar units).
                    /** @var list<string> $validSmallest */
                    static $validSmallest = [
                        'hour',
                        'hours',
                        'minute',
                        'minutes',
                        'second',
                        'seconds',
                        'millisecond',
                        'milliseconds',
                        'microsecond',
                        'microseconds',
                        'nanosecond',
                        'nanoseconds',
                    ];
                    if (!in_array($su, $validSmallest, strict: true)) {
                        throw new RangeError("Invalid smallestUnit value: \"{$su}\".");
                    }
                    $smallestUnit = match ($su) {
                        'hours' => 'hour',
                        'minutes' => 'minute',
                        'seconds' => 'second',
                        'milliseconds' => 'millisecond',
                        'microseconds' => 'microsecond',
                        'nanoseconds' => 'nanosecond',
                        default => $su,
                    };
                }
            }

            if (array_key_exists('roundingMode', $opts)) {
                /** @var mixed $rm */
                $rm = $opts['roundingMode'];
                if ($rm !== null) {
                    $rm = Options::coerceEnumOption($rm, 'roundingMode');
                }
                if (is_string($rm)) {
                    $roundingMode = Options::roundingMode($rm);
                }
            }

            if (array_key_exists('roundingIncrement', $opts)) {
                /** @var mixed $ri */
                $ri = $opts['roundingIncrement'];
                if ($ri !== null) {
                    $roundingIncrement = CalendarMath::validateRoundingIncrement($ri);
                }
            }
        }

        // Unit rank: higher = larger unit.
        $unitRank = [
            'hour' => 6,
            'minute' => 5,
            'second' => 4,
            'millisecond' => 3,
            'microsecond' => 2,
            'nanosecond' => 1,
        ];

        $luRank = $unitRank[$largestUnit];
        $suRank = $unitRank[$smallestUnit];

        // largestUnit must be >= smallestUnit.
        if ($luRank < $suRank) {
            throw new RangeError(
                "largestUnit \"{$largestUnit}\" must not be smaller than smallestUnit \"{$smallestUnit}\".",
            );
        }

        // Validate roundingIncrement: must evenly divide the next-unit count.
        $maxIncrements = [
            'hour' => 24,
            'minute' => 60,
            'second' => 60,
            'millisecond' => 1_000,
            'microsecond' => 1_000,
            'nanosecond' => 1_000,
        ];
        $maxIncrement = $maxIncrements[$smallestUnit] ?? 1_000_000_000;
        if ($roundingIncrement >= $maxIncrement || ($maxIncrement % $roundingIncrement) !== 0) {
            throw new RangeError(
                "roundingIncrement {$roundingIncrement} is invalid for smallestUnit \"{$smallestUnit}\".",
            );
        }

        // ns-per-unit table for rounding.
        $nsPerUnit = [
            'hour' => self::NS_PER_HOUR,
            'minute' => self::NS_PER_MINUTE,
            'second' => EpochLimits::NS_PER_SECOND,
            'millisecond' => self::NS_PER_MS,
            'microsecond' => self::NS_PER_US,
            'nanosecond' => 1,
        ];

        $sign = $diffNs >= 0 ? 1 : -1;
        $absNs = abs($diffNs);

        // Round diffNs to the nearest multiple of nsIncrement.
        // For floor/ceil/halfFloor/halfCeil, the direction depends on the sign of diffNs.
        $nsIncrement = ($nsPerUnit[$smallestUnit] ?? 1) * $roundingIncrement;
        $roundedAbsNs = self::roundSignedNs($diffNs, $nsIncrement, $roundingMode);
        // roundSignedNs returns a signed value; take abs for balancing, sign already captured.
        unset($absNs); // avoid accidental use
        $roundedAbsNs = abs($roundedAbsNs);

        // Balance the rounded absolute value up to largestUnit.
        $remaining = $roundedAbsNs;

        $hours = 0;
        $minutes = 0;
        $seconds = 0;
        $ms = 0;
        $us = 0;

        if ($luRank >= 6) {
            $hours = intdiv(num1: $remaining, num2: self::NS_PER_HOUR);
            $remaining %= self::NS_PER_HOUR;
        }
        if ($luRank >= 5) {
            $minutes = intdiv(num1: $remaining, num2: self::NS_PER_MINUTE);
            $remaining %= self::NS_PER_MINUTE;
        }
        if ($luRank >= 4) {
            $seconds = intdiv(num1: $remaining, num2: EpochLimits::NS_PER_SECOND);
            $remaining %= EpochLimits::NS_PER_SECOND;
        }
        if ($luRank >= 3) {
            $ms = intdiv(num1: $remaining, num2: self::NS_PER_MS);
            $remaining %= self::NS_PER_MS;
        }
        if ($luRank >= 2) {
            $us = intdiv(num1: $remaining, num2: self::NS_PER_US);
            $remaining %= self::NS_PER_US;
        }
        $ns = $remaining;

        return new Duration(
            hours: $sign * $hours,
            minutes: $sign * $minutes,
            seconds: $sign * $seconds,
            milliseconds: $sign * $ms,
            microseconds: $sign * $us,
            nanoseconds: $sign * $ns,
        );
    }

    /**
     * Rounds a signed nanosecond diff to the nearest multiple of $increment,
     * correctly handling directional modes (floor, ceil, halfFloor, halfCeil) for
     * negative values.
     *
     * Returns a signed result (may be negative).
     *
     * @throws RangeError for unknown rounding modes.
     */
    private static function roundSignedNs(int $ns, int $increment, string $mode): int
    {
        // PHP's intdiv truncates toward zero.
        $q = intdiv(num1: $ns, num2: $increment);
        $rem = $ns - ($q * $increment); // same sign as $ns (or 0)
        $trunc = $q * $increment; // truncated toward zero
        $absRem = abs($rem);

        $expand = $ns >= 0 ? $trunc + $increment : $trunc - $increment;

        switch ($mode) {
            case 'trunc':
                return $trunc;
            case 'floor':
                return $rem < 0 ? $trunc - $increment : $trunc;
            case 'ceil':
                return $rem > 0 ? $trunc + $increment : $trunc;
            case 'expand':
                if ($rem < 0) {
                    return $trunc - $increment;
                }
                return $rem > 0 ? $trunc + $increment : $trunc;
            case 'halfExpand':
                return ($absRem * 2) >= $increment ? $expand : $trunc;
            case 'halfTrunc':
                return ($absRem * 2) > $increment ? $expand : $trunc;
            case 'halfFloor':
                $cmp = $absRem * 2;
                if ($cmp < $increment) {
                    return $trunc;
                }
                if ($cmp > $increment) {
                    return $expand;
                }
                // tie: toward -∞
                return $ns >= 0 ? $trunc : $trunc - $increment;
            case 'halfCeil':
                $cmp = $absRem * 2;
                if ($cmp < $increment) {
                    return $trunc;
                }
                if ($cmp > $increment) {
                    return $expand;
                }
                // tie: toward +∞
                return $ns >= 0 ? $trunc + $increment : $trunc;
            case 'halfEven':
                $cmp = $absRem * 2;
                if ($cmp < $increment) {
                    return $trunc;
                }
                if ($cmp > $increment) {
                    return $expand;
                }
                return ($q % 2) === 0 ? $trunc : $expand;
            default:
                throw new RangeError("Invalid roundingMode \"{$mode}\".");
        }
    }

    #[\Override]
    protected function localeDefaultComponents(): string
    {
        return 'time';
    }

    #[\Override]
    protected function localeIsDateOnly(): bool
    {
        return false;
    }

    #[\Override]
    protected function localeIsTimeOnly(): bool
    {
        return true;
    }

    #[\Override]
    protected function localeCalendarId(): null
    {
        return null;
    }

    #[\Override]
    protected function toLocaleEpochParts(): array
    {
        // Anchored on the Unix epoch date (1970-01-01), which no time-only pattern renders.
        $epochSec = ($this->hour * 3_600) + ($this->minute * 60) + $this->second;
        return [$epochSec, ($this->millisecond * 1_000_000) + ($this->microsecond * 1_000) + $this->nanosecond];
    }
}
