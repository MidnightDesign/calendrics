<?php

declare(strict_types=1);

namespace Calendrics\Spec;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\CalendarMath;
use Calendrics\Spec\Internal\EpochLimits;
use Calendrics\Spec\Internal\EpochRounding;
use Calendrics\Spec\Internal\EpochValue;
use Calendrics\Spec\Internal\HasEpochParts;
use Calendrics\Spec\Internal\IntlFormatter;
use Calendrics\Spec\Internal\IsoFraction;
use Calendrics\Spec\Internal\IsoOffset;
use Calendrics\Spec\Internal\Options;
use Calendrics\Spec\Internal\TimeZoneHelper;
use DateTimeImmutable;
use DateTimeZone;
use Stringable;

/**
 * A fixed point in time with nanosecond precision.
 *
 * Stores the number of nanoseconds since the Unix epoch (1970-01-01T00:00:00Z)
 * as a 64-bit integer, giving a practical range of approximately 1677–2262.
 *
 * @see https://tc39.es/proposal-temporal/#sec-temporal-instant-objects
 */
final class Instant implements Stringable
{
    use HasEpochParts;

    /**
     * Milliseconds since the Unix epoch (floor-divided from nanoseconds).
     *
     * Unlike the JS spec, which returns a Number, PHP returns int since a
     * 64-bit integer has sufficient range for all practical timestamps.
     *
     * @psalm-api
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $epochMilliseconds {
        get {
            [$epochSec, $subNs] = $this->epochParts();
            // ms = floor(trueNs / 1e6); decompose to avoid an int64-overflowing
            // intermediate trueNs for over-int64 instants.
            return ($epochSec * 1_000) + CalendarMath::floorDiv($subNs, EpochLimits::NS_PER_MILLISECOND);
        }
    }

    /**
     * @param int|float|string|bool $epochNanoseconds Nanoseconds since the Unix epoch.
     *        An int is taken verbatim (the PHP stand-in for an in-range BigInt). A bool
     *        is coerced (true→1, false→0) to mirror TC39 ToBigInt(boolean). A decimal
     *        integer string carries an over-int64 value, decomposed exactly so full
     *        precision is preserved (see {@see decimalStringToEpochParts()}). A float is
     *        rejected: TC39 coerces the argument with ToBigInt and ToBigInt(Number) is a
     *        TypeError.
     * @throws TypeError if epochNanoseconds is a float.
     * @throws RangeError if a string is not a decimal integer, or the value is outside
     *         the representable Temporal nanosecond range.
     */
    public function __construct(int|float|string|bool $epochNanoseconds)
    {
        // TC39 coerces the argument with ToBigInt; ToBigInt(true)=1n, ToBigInt(false)=0n.
        if (is_bool($epochNanoseconds)) {
            $epochNanoseconds = (int) $epochNanoseconds;
        }
        // ToBigInt(Number) is a TypeError, so a PHP float (our Number stand-in) is
        // rejected; an over-int64 instant is supplied as a decimal string instead.
        if (is_float($epochNanoseconds)) {
            throw new TypeError('epochNanoseconds must be an integer, not a float.');
        }
        if (is_string($epochNanoseconds)) {
            // Exact decomposition of a (possibly over-int64) decimal integer string
            // into floor epoch-seconds + sub-second nanoseconds, preserving full
            // precision the lossy float path cannot.
            if (preg_match('/^[+-]?\d+$/', $epochNanoseconds) !== 1) {
                throw new RangeError('epochNanoseconds string must be a decimal integer.');
            }
            [$sec, $subNs] = self::decimalStringToEpochParts($epochNanoseconds);
            $epoch = self::normalizeEpochParts($sec, $subNs);
        } else {
            // An int argument is the instant exactly, sentinel-valued or not: PHP_INT_MIN
            // and PHP_INT_MAX are ordinary nanosecond counts here, and stamping their true
            // parts keeps them from reading as the over-int64 clamp markers of the same value.
            $epoch = EpochValue::fromNanoseconds($epochNanoseconds);
        }
        $this->epochNanoseconds = $epoch->epochNanoseconds;
        $this->applyEpoch($epoch);
    }

    /**
     * Decomposes a decimal-integer nanosecond string into floor epoch-seconds and
     * sub-second nanoseconds in [0, 1e9) using exact string/integer math.
     *
     * @return array{int, int} [epochSec, subNs]
     */
    private static function decimalStringToEpochParts(string $decimal): array
    {
        $negative = $decimal[0] === '-';
        $digits = ltrim($decimal, characters: '+-');
        $digits = ltrim($digits, characters: '0');
        if ($digits === '') {
            return [0, 0];
        }
        // Split off the last 9 digits as the sub-second nanosecond magnitude.
        if (strlen($digits) <= 9) {
            $secMagnitude = 0;
            $subMagnitude = (int) $digits;
        } else {
            $secMagnitude = (int) substr($digits, offset: 0, length: -9);
            $subMagnitude = (int) substr($digits, offset: -9);
        }
        if (!$negative) {
            return [$secMagnitude, $subMagnitude];
        }
        // Negative: floor toward -inf. -(sec.frac) = -(sec) - frac; floor folds the
        // fractional part down by one second when it is non-zero.
        if ($subMagnitude === 0) {
            return [-$secMagnitude, 0];
        }
        return [-$secMagnitude - 1, EpochLimits::NS_PER_SECOND - $subMagnitude];
    }

    /**
     * Normalizes true epoch parts and resolves the public {@see $epochNanoseconds}
     * field value (a sentinel for over-int64 instants) plus the true parts. Shared
     * by the constructor and {@see fromEpochParts()} so normalization, the spec
     * range check, and the over-int64 sentinel rule live in one place.
     *
     * The range check stays here because its RangeError message is Instant-specific;
     * the sub-second normalization and the int64-fit / sentinel pack are shared with
     * ZonedDateTime via {@see EpochValue::fromParts()}.
     *
     * @throws RangeError if the result is outside the representable Temporal range.
     */
    private static function normalizeEpochParts(int $epochSec, int $subNs): EpochValue
    {
        // Normalize + pack in one place; fromParts() carries any sub-second overflow
        // into seconds and applies the int64-fit / sentinel rule.
        $epoch = EpochValue::fromParts($epochSec, $subNs);

        // Range-check against the spec bound on the normalized pair (subNs in [0, 1e9)).
        $normSec = $epoch->trueEpochSec;
        $normSubNs = $epoch->trueSubNs;
        $maxSec = EpochLimits::MAX_EPOCH_SECONDS;
        if ($normSec < -$maxSec || $normSec > $maxSec || $normSec === $maxSec && $normSubNs > 0) {
            throw new RangeError('Instant result is outside the representable nanosecond range.');
        }

        return $epoch;
    }

    /**
     * Creates an Instant from true UTC epoch seconds and sub-second nanoseconds,
     * preserving the true value when it overflows int64 by clamping the public
     * {@see $epochNanoseconds} field to a sentinel while storing the true parts.
     *
     * Mirrors ZonedDateTime::fromEpochParts(). The seam used by the constructor
     * paths, arithmetic, rounding, and the toInstant()/toZonedDateTimeISO()
     * converters so every over-int64 instant carries its true value.
     *
     * $epochSec/$subNs accept int|float and are narrowed by
     * {@see EpochValue::narrowParts()}, which documents where float parts come from.
     *
     * @internal
     * @psalm-internal Calendrics\Spec
     * @throws RangeError if a part is a non-integer float or the result
     *         is outside the representable Temporal range.
     */
    public static function fromEpochParts(int|float $epochSec, int|float $subNs): self
    {
        // Spec range: |epochNs| ≤ 8_640_000_000_000 × 10⁹. Out-of-range results
        // throw RangeError — the project's range-violation type,
        // to which the test262 transpiler maps the JS-spec RangeError. Normalization,
        // the range check, and the over-int64 sentinel are shared with the
        // constructor via normalizeEpochParts().
        $parts = EpochValue::narrowParts($epochSec, $subNs);
        if ($parts === null) {
            throw new RangeError('Instant result is outside the representable nanosecond range.');
        }
        [$epochSec, $subNs] = $parts;

        // The constructor derives its own parts from the int it is handed, which is the
        // clamp rather than the instant once the value overflows int64, so re-stamp from
        // the value that knows both.
        $epoch = self::normalizeEpochParts($epochSec, $subNs);
        $self = new self($epoch->epochNanoseconds);
        $self->applyEpoch($epoch);
        return $self;
    }

    // -------------------------------------------------------------------------
    // Static factory methods
    // -------------------------------------------------------------------------

    /**
     * Parses an ISO 8601 / RFC 3339 date-time string that includes a UTC offset.
     *
     * Supported formats (non-exhaustive):
     *   '2020-01-01T00:00:00Z'
     *   '2020-01-01T00:00:00+05:30'
     *   '2020-01-01T00:00:00.123456789Z'
     *   '2020-01-01T15:23Z'                                    (seconds optional)
     *   '1976-11-18T15:23:30,12Z'                              (comma as decimal separator)
     *   '19761118T152330Z'                                     (compact date + compact time)
     *   '1976-11-18T15:23:30+0530'                             (short offset ±HHMM)
     *   '1976-11-18T15:23:30+00'                               (short offset ±HH)
     *   '+001976-11-18T15:23:30Z'                              (extended positive year)
     *   '-009999-11-18T15:23:30Z'                              (negative year)
     *   '+0019761118T15:23:30Z'                                (extended year + compact date)
     *   '2020-01-01T00:00:00Z[UTC][u-ca=iso8601]'              (multiple annotations ignored)
     *   '2016-12-31T23:59:60Z'                                 (leap second → last ns of :59)
     *   '1976-11-18T15:23:30.123456789-00:00:00.1'             (sub-minute offset)
     *   '-271821-04-20T00:00Z'                                 (spec minimum)
     *   '+275760-09-13T23:59:59.999999999+23:59:59.999999999'  (spec maximum)
     *
     * @throws RangeError if the string cannot be parsed, has no UTC offset,
     *                    or represents a timestamp outside the nanosecond range.
     * @throws TypeError if $item is a non-string, non-Instant object.
     */
    public static function from(string|object $item): self
    {
        if ($item instanceof self) {
            [$epochSec, $subNs] = $item->epochParts();
            return self::fromEpochParts($epochSec, $subNs);
        }
        // TC39 sec-temporal-totemporalinstant step 1.b: a ZonedDateTime carries a
        // Nanoseconds internal slot — extract it directly as the fast path.
        if ($item instanceof \Calendrics\Spec\ZonedDateTime) {
            [$epochSec, $subNs] = $item->epochParts();
            return self::fromEpochParts($epochSec, $subNs);
        }
        if (!is_string($item)) {
            throw new TypeError(sprintf(
                'Temporal.Instant.from() requires an Instant or string, got %s.',
                get_debug_type($item),
            ));
        }
        $text = $item;
        // Reject more than 9 fractional-second digits (time part or offset fraction).
        // TC39 spec: strings with 10+ fractional digits are invalid (test262 argument-string-too-many-decimals).
        if (preg_match('/[.,]\d{10,}/', $text) === 1) {
            throw new RangeError("Invalid Instant string \"{$text}\": fractional seconds may have at most 9 digits.");
        }
        /*
         * Regex groups:
         *   1 — year (±YYYYYY or YYYY)
         *   2 — date rest (-MM-DD or MMDD)
         *   3 — hour (HH)
         *   4 — minute (MM, optional — bare hour form '1976-11-18T15Z' is valid)
         *   5 — second (SS, optional)
         *   6 — time fraction ([.,]\d+, optional)
         *   7 — offset (full form including sub-minute)
         *
         * Offset alternatives (no mixed separators):
         *   Z
         *   ±HH
         *   ±HH:MM | ±HH:MM:SS | ±HH:MM:SS[.,]frac  (colon-separated)
         *   ±HHMM  | ±HHMMSS  | ±HHMMSS[.,]frac     (no separators)
         */
        $pattern = '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2}|\d{4})[T ](\d{2})(?::?(\d{2})(?::?(\d{2}))?)?([.,]\d+)?(Z|[+-]\d{2}(?::\d{2}(?::\d{2}(?:[.,]\d+)?)?|\d{2}(?:\d{2}(?:[.,]\d+)?)?)?)((?:\[[^\]]*\])*)$/i';

        /** @var list<string> $m */
        $m = [];
        if (preg_match($pattern, $text, $m) !== 1) {
            throw new RangeError("Invalid Instant string \"{$text}\": expected ISO 8601 with a UTC offset.");
        }

        [, $yearRaw, $dateRest, $hour, $min, $sec, $fractionRaw, $offsetRaw, $annotationSection] = $m;

        // Normalise compact date (MMDD) → extended form (-MM-DD) so that both
        // PHP's DateTimeImmutable and our component extraction work uniformly.
        if (!str_starts_with($dateRest, '-')) {
            $dateRest = sprintf(
                '-%s-%s',
                substr(string: $dateRest, offset: 0, length: 2),
                substr(string: $dateRest, offset: 2, length: 2),
            );
        }

        // Extract and validate date/time components.
        $yearNum = (int) $yearRaw;
        // Reject -000000 (minus-zero year is invalid per spec).
        if ($yearNum === 0 && str_starts_with($yearRaw, '-')) {
            throw new RangeError("Invalid Instant string \"{$text}\": year -000000 (negative zero) is not valid.");
        }
        $monthNum = (int) substr(string: $dateRest, offset: 1, length: 2);
        $dayNum = (int) substr(string: $dateRest, offset: 4, length: 2);
        $hourNum = (int) $hour;
        $minNum = (int) $min;
        $secNum = $sec !== '' ? (int) $sec : 0;

        if ($monthNum < 1 || $monthNum > 12) {
            throw new RangeError("Invalid Instant string \"{$text}\": month out of range.");
        }
        $maxDay = CalendarMath::calcDaysInMonth($yearNum, $monthNum);
        if ($dayNum < 1 || $dayNum > $maxDay) {
            throw new RangeError("Invalid Instant string \"{$text}\": day out of range.");
        }
        if ($hourNum > 23) {
            throw new RangeError("Invalid Instant string \"{$text}\": hour out of range.");
        }
        if ($minNum > 59) {
            throw new RangeError("Invalid Instant string \"{$text}\": minute out of range.");
        }

        // Leap second: 60 is valid and maps to the last nanosecond of :59 (spec §8.5.6).
        $sec60 = $secNum === 60;
        $normalSec = $sec60 ? 59 : $secNum;
        if (!$sec60 && $secNum > 59) {
            throw new RangeError("Invalid Instant string \"{$text}\": second out of range.");
        }

        // Parse the offset to [sign, absSec, fracNs].  The offset is applied
        // manually so that sub-minute precision is handled correctly.
        [$offsetSign, $offsetAbsSec, $offsetFracNs] = IsoOffset::parts($offsetRaw);
        if ((($offsetAbsSec * EpochLimits::NS_PER_SECOND) + $offsetFracNs) > 86_399_999_999_999) {
            throw new RangeError("Invalid Instant string \"{$text}\": UTC offset out of range.");
        }

        CalendarMath::validateAnnotations($annotationSection, $text, false);

        // Build a UTC-only DateTimeImmutable (always +00:00) so that PHP does
        // not apply any offset itself. Use validated numeric values to avoid
        // malformed strings (e.g. empty $min when only the hour was given).
        // Extended year strings like '+001976-11-18T15:23:30+00:00' are handled
        // natively by PHP's flexible DateTime parser.
        // Every component was range-validated above (year regex incl. the
        // minus-zero check, month 1–12, day 1..daysInMonth, hour ≤23, minute ≤59,
        // second 0–59), so the formatted UTC string is always well-formed — even at
        // the spec-extreme extended years ±YYYYYY — and DateTimeImmutable cannot throw.
        $dt = new DateTimeImmutable(sprintf(
            '%s%sT%02d:%02d:%02d+00:00',
            $yearRaw,
            $dateRest,
            $hourNum,
            $minNum,
            $normalSec,
        ));

        // $localSec: Unix seconds for the local date/time as if it were UTC.
        $localSec = $dt->getTimestamp();
        $localSubNs = $fractionRaw !== '' ? IsoFraction::toNanoseconds($fractionRaw) : 0;

        // UTC epoch seconds = local seconds − offset seconds.
        // We avoid multiplying large second values by 10^9 (which would overflow
        // int64) by carrying the nanosecond arithmetic separately.
        $utcEpochSec = $localSec - ($offsetSign * $offsetAbsSec);
        $baseNs = $localSubNs - ($offsetSign * $offsetFracNs);

        // Propagate carry from the nanosecond component into whole seconds.
        if ($baseNs < 0) {
            --$utcEpochSec;
            $baseNs += EpochLimits::NS_PER_SECOND;
        } elseif ($baseNs >= EpochLimits::NS_PER_SECOND) {
            ++$utcEpochSec;
            $baseNs -= EpochLimits::NS_PER_SECOND;
        }

        // Spec range: epoch nanoseconds ∈ [-8_640_000_000_000×10⁹, +8_640_000_000_000×10⁹].
        // Checked at second granularity; at the boundary second, any non-zero
        // sub-second component puts the instant out of range.
        $maxSec = EpochLimits::MAX_EPOCH_SECONDS;
        if ($utcEpochSec < -$maxSec || $utcEpochSec > $maxSec || $utcEpochSec === $maxSec && $baseNs > 0) {
            throw new RangeError("Instant string \"{$text}\" is outside the representable nanosecond range.");
        }

        // For dates far from the Unix epoch (years roughly outside 1678–2262),
        // utcEpochSec × NS_PER_SECOND would overflow PHP's int64. fromEpochParts()
        // stores the public field as a saturated sentinel while preserving the
        // true epoch seconds / sub-ns, so spec-valid but int64-unrepresentable
        // instants survive construction intact.
        return self::fromEpochParts($utcEpochSec, $baseNs);
    }

    /**
     * Creates an Instant from a Unix timestamp in milliseconds.
     *
     * @param int|float|null $epochMilliseconds Milliseconds since the Unix epoch.
     *        Must be a finite integer value within ±{@see EpochLimits::MAX_EPOCH_MILLISECONDS}.
     * @throws RangeError if the value is not a finite integer or is out of range.
     */
    public static function fromEpochMilliseconds(int|float|null $epochMilliseconds = null): self
    {
        if ($epochMilliseconds === null) {
            throw new RangeError('epochMilliseconds must be provided.');
        }
        if (is_float($epochMilliseconds)) {
            if (!is_finite($epochMilliseconds) || floor($epochMilliseconds) !== $epochMilliseconds) {
                throw new RangeError("epochMilliseconds must be a finite integer value, got {$epochMilliseconds}.");
            }
            $epochMilliseconds = (int) $epochMilliseconds;
        }
        $limit = EpochLimits::MAX_EPOCH_MILLISECONDS;
        if ($epochMilliseconds < -$limit || $epochMilliseconds > $limit) {
            throw new RangeError("epochMilliseconds {$epochMilliseconds} is outside the valid range of ±{$limit}.");
        }
        // Guard against int64 overflow when multiplying ms × 10^6 to get nanoseconds.
        // Threshold: floor(PHP_INT_MAX / NS_PER_MILLISECOND) = 9_223_372_036_854.
        // Beyond it, decompose into (epochSec, subNs) and let fromEpochParts()
        // store the true value behind a saturated sentinel.
        $threshold = EpochLimits::MAX_EPOCH_MILLISECONDS_FOR_INT64_NS;
        if ($epochMilliseconds > $threshold || $epochMilliseconds < -$threshold) {
            $epochSec = CalendarMath::floorDiv($epochMilliseconds, 1_000);
            $subMs = $epochMilliseconds - ($epochSec * 1_000);
            return self::fromEpochParts($epochSec, $subMs * EpochLimits::NS_PER_MILLISECOND);
        }
        return new self($epochMilliseconds * EpochLimits::NS_PER_MILLISECOND);
    }

    /**
     * Creates an Instant from a Unix timestamp in nanoseconds.
     */
    public static function fromEpochNanoseconds(int $epochNanoseconds): self
    {
        return new self($epochNanoseconds);
    }

    /**
     * Compares two Instants chronologically.
     *
     * Accepts either an Instant instance or a string parseable by {@see from()}.
     *
     * @return int -1, 0, or 1.
     */
    public static function compare(string|object $one, string|object $two): int
    {
        $a = $one instanceof self ? $one : self::coerceToInstant($one);
        $b = $two instanceof self ? $two : self::coerceToInstant($two);
        [$aSec, $aSubNs] = $a->epochParts();
        [$bSec, $bSubNs] = $b->epochParts();
        $cmp = $aSec <=> $bSec;
        return $cmp !== 0 ? $cmp : $aSubNs <=> $bSubNs;
    }

    /**
     * Coerces a non-Instant value to an Instant by parsing it as an ISO string.
     *
     * Per TC39, the argument undergoes ToTemporalInstant, which performs ToString
     * on a non-Instant value and then parses the result. A foreign object
     * stringifies and fails to parse, so it surfaces a RangeError; a Symbol's
     * ToString throws a TypeError (modelled here by the JsSymbol sentinel's
     * throwing {@see \Stringable::__toString()}). Non-string, non-object
     * primitives (number/bool/null/bigint) never reach this method — the typed
     * `string|object` signature rejects them with a native TypeError first.
     *
     * @throws TypeError if $arg is a Symbol (Stringable whose cast throws).
     * @throws RangeError if $arg is a foreign object or an invalid ISO string.
     */
    private static function coerceToInstant(string|object $arg): self
    {
        if (!is_string($arg)) {
            if ($arg instanceof Stringable) {
                // JsSymbol's __toString() throws TypeError here; a genuine
                // Stringable is parsed below and any parse failure is a RangeError.
                $arg = (string) $arg;
            } else {
                // A non-string, non-Stringable value (number, bool, plain object,
                // Temporal type other than Instant) is a wrong-TYPE argument —
                // TC39 throws TypeError before any string coercion is attempted.
                throw new TypeError('Calendrics\\Instant argument must be an Instant or an ISO string.');
            }
        }
        return self::from($arg);
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Returns true when both Instants represent the same point in time.
     *
     * Accepts either an Instant instance or a string parseable by {@see from()}.
     * Any other type throws TypeError (matches JS coercion failure behaviour).
     *
     * @throws TypeError if $other is not an Instant or a string.
     * @throws RangeError if $other is an invalid ISO string.
     */
    public function equals(string|object $other): bool
    {
        $otherInst = $other instanceof self ? $other : self::coerceToInstant($other);
        [$aSec, $aSubNs] = $this->epochParts();
        [$bSec, $bSubNs] = $otherInst->epochParts();
        return $aSec === $bSec && $aSubNs === $bSubNs;
    }

    /**
     * Returns an ISO 8601 string in UTC, with optional rounding and precision options.
     *
     * Options (all optional):
     *   - fractionalSecondDigits: 'auto' (default, strip trailing zeros) | 0–9 (fixed digit count)
     *     Floats are floored. Null, booleans, NaN, ±Inf, or out-of-range values throw.
     *   - smallestUnit: 'minute'|'second'|'millisecond'|'microsecond'|'nanosecond' (overrides digits)
     *   - roundingMode: 'trunc' (default)|'floor'|'ceil'|'expand'|'halfExpand'|
     *                   'halfTrunc'|'halfFloor'|'halfCeil'|'halfEven'
     *     Null roundingMode defaults to 'trunc'.
     *
     * Uses RoundNumberToIncrementAsIfPositive (spec §8.3.13): rounding is always applied
     * using the unsigned mode for a positive sign, regardless of the actual sign of the epoch.
     *
     * @param array<array-key, mixed>|object|null $options
     * @throws RangeError if options are invalid.
     * @throws TypeError if the timeZone option is a non-string.
     */
    public function toString(mixed $options = null): string
    {
        $options = Options::normalizeOptions($options, [
            'fractionalSecondDigits',
            'roundingMode',
            'smallestUnit',
            'timeZone',
        ]);

        // $digits: -2 = 'auto' (strip trailing zeros), -1 = minute format, 0-9 = fixed.
        $digits = -2;
        $roundMode = 'trunc';
        $isMinute = false;
        $increment = 1;

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
            [$digits, $isMinute] = match ($su) {
                'minute', 'minutes' => [-1, true],
                'second', 'seconds' => [0, false],
                'millisecond', 'milliseconds' => [3, false],
                'microsecond', 'microseconds' => [6, false],
                'nanosecond', 'nanoseconds' => [9, false],
                default => throw new RangeError("Invalid smallestUnit \"{$su}\"."),
            };
        }

        // roundingMode (null → default 'trunc'). Validated even on the fast
        // (increment === 1) path so an unknown / non-string mode is rejected
        // rather than silently accepted.
        if (array_key_exists('roundingMode', $options) && $options['roundingMode'] !== null) {
            $roundMode = Options::coerceEnumOption($options['roundingMode'], 'roundingMode');
            self::validateRoundingMode($roundMode);
        }

        // timeZone: must be a string; non-string (including null) → TypeError.
        // Read last, matching the order TC39 prescribes — the snapshot above fired any
        // accessor getter in that same order.
        /** @var mixed $timeZoneRaw */
        $timeZoneRaw = array_key_exists('timeZone', $options) ? $options['timeZone'] : Options::ABSENT;
        $hasTimeZone = $timeZoneRaw !== Options::ABSENT;
        if ($hasTimeZone) {
            if (!is_string($timeZoneRaw)) {
                throw new TypeError('timeZone must be a string.');
            }
            self::validateTimeZoneString($timeZoneRaw);
        }

        // Resolve timezone offset in seconds (null = UTC / 'Z' suffix).
        $tzOffsetSec = null;
        $ianaTimeZone = null;
        if ($hasTimeZone) {
            /** @var non-empty-string $timeZoneRaw */
            $tzStr = $timeZoneRaw;
            $resolved = self::resolveTimeZoneOffsetSeconds($tzStr);
            if ($resolved !== null) {
                $tzOffsetSec = $resolved;
            } else {
                // IANA timezone: extract the timezone name from the string.
                // For bracket annotations, extract the bracket content (dropping the
                // `!` critical flag, which is not part of the identifier).
                $bm2 = null;
                if (preg_match('/\[!?([^\]]+)\]/', $tzStr, $bm2) === 1) {
                    $ianaTimeZone = $bm2[1];
                } else {
                    $ianaTimeZone = $tzStr;
                }
                // An unrecognized name is a RangeError; a bracket annotation's inline
                // offset is not a fallback for one.
                $ianaTimeZone = TimeZoneHelper::normalizeTimezoneId($ianaTimeZone);
            }
        }

        // Determine the rounding increment in nanoseconds.
        if ($isMinute) {
            $increment = 60_000_000_000;
        } elseif ($digits >= 0) {
            // $digits ∈ [0, 9]; the rounding increment is 10^(9 − digits) ns.
            // Collapsed from an over-enumerated per-digit match() whose every arm
            // equals this closed form. ** widens to float|int in Psalm's model, but
            // the exponent is always ≥ 0 so the result is an int — pinned here.
            /** @var int<1, 1000000000> $increment */
            $increment = 10 ** (9 - $digits);
        }
        // For 'auto' ($digits === -2), increment stays 1 (no rounding).

        // Round using RoundNumberToIncrementAsIfPositive, decomposed into
        // (seconds, sub-ns) so the combined nanosecond value never has to fit
        // int64 — keeping over-int64 instants intact.
        [$trueSec, $trueSubNs] = $this->epochParts();
        [$secs, $subNs] = $increment === 1
            ? [$trueSec, $trueSubNs]
            : EpochRounding::round($trueSec, $trueSubNs, $increment, $roundMode);

        // For IANA timezones, compute the offset at this epoch.
        if ($ianaTimeZone !== null) {
            $tzOffsetSec = self::ianaOffsetSeconds($ianaTimeZone, $secs);
        }

        // Apply timezone offset to get local datetime.
        $localSecs = $tzOffsetSec !== null ? $secs + $tzOffsetSec : $secs;
        $dt = new DateTimeImmutable(sprintf('@%d', $localSecs))->setTimezone(new DateTimeZone('UTC'));

        // Build the UTC-offset suffix: 'Z' or ±HH:MM (always rounded to minutes for Instant).
        if ($tzOffsetSec === null) {
            $tzSuffix = 'Z';
        } else {
            $roundedMin = (int) round((float) $tzOffsetSec / 60.0);
            $absMin = abs($roundedMin);
            $tzH = intdiv(num1: $absMin, num2: 60);
            $tzM = $absMin % 60;
            $tzSign = $roundedMin < 0 ? '-' : '+';
            $tzSuffix = sprintf('%s%02d:%02d', $tzSign, $tzH, $tzM);
        }

        // Year formatting: normal 4-digit, extended ±YYYYYY when out of range.
        // PHP's 'Y' format token does not emit the signed 6-digit extended form
        // the spec requires for years <0 or >9999 (now reachable since the true
        // value is preserved beyond the int64-clamp).
        $year = (int) $dt->format('Y');
        if ($year < 0) {
            $yearStr = sprintf('-%06d', abs($year));
        } elseif ($year > 9999) {
            $yearStr = sprintf('+%06d', $year);
        } else {
            $yearStr = sprintf('%04d', $year);
        }
        $datePart = $yearStr . $dt->format('-m-d');

        if ($isMinute) {
            return $datePart . $dt->format('\TH:i') . $tzSuffix;
        }

        $base = $datePart . $dt->format('\TH:i:s');

        if ($digits === -2) {
            // 'auto': strip trailing zeros.
            if ($subNs === 0) {
                return $base . $tzSuffix;
            }
            $fraction = rtrim(sprintf('%09d', $subNs), characters: '0');
            return "{$base}.{$fraction}{$tzSuffix}";
        }

        if ($digits === 0) {
            return $base . $tzSuffix;
        }

        $fraction = substr(sprintf('%09d', $subNs), offset: 0, length: $digits);
        return "{$base}.{$fraction}{$tzSuffix}";
    }

    /**
     * Validates a timezone identifier string for the toString() timeZone option.
     *
     * Rules (from TC39 Temporal spec):
     *   - Minus-zero extended year (-000000) → reject.
     *   - Bracket annotation offset with seconds (e.g. [+23:59:60]) → reject.
     *   - Pure UTC-offset strings (start with ±HH): must be ±HH:MM or ±HHMM (no seconds).
     *   - Datetime strings (contain T): must have Z, an offset, or a bracket annotation;
     *     an inline offset must not include a seconds component.
     *
     * @throws RangeError for invalid timezone strings.
     *
     * @phpstan-assert non-empty-string $tz
     * @psalm-assert non-empty-string $tz
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
        // Datetime strings: must have Z, an offset, or a bracket annotation.
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
     * Extracts the UTC offset in minutes from a validated timezone string.
     *
     * Priority: bracket annotation > inline offset/Z.
     * Returns 0 for 'UTC' or 'Z', the offset minutes for ±HH:MM strings,
     * and the bracket annotation offset for datetime strings with [±HH:MM] or [UTC].
     */
    private static function resolveTimeZoneOffsetSeconds(string $tz): ?int
    {
        // 'UTC' (case-insensitive)
        if (strtoupper($tz) === 'UTC') {
            return 0;
        }
        // Pure UTC-offset strings: ±HH:MM or ±HHMM
        $m = null;
        if (preg_match('/^([+\-])(\d{2}):(\d{2})$/', $tz, $m) === 1) {
            $sign = $m[1] === '+' ? 1 : -1;
            return $sign * (((int) $m[2] * 3600) + ((int) $m[3] * 60));
        }
        if (preg_match('/^([+\-])(\d{2})(\d{2})$/', $tz, $m) === 1) {
            $sign = $m[1] === '+' ? 1 : -1;
            return $sign * (((int) $m[2] * 3600) + ((int) $m[3] * 60));
        }
        // Datetime strings: bracket annotation takes precedence.
        $bm = null;
        if (preg_match('/\[([^\]]+)\]/', $tz, $bm) === 1) {
            /** @var non-empty-string $bracket */
            $bracket = $bm[1];
            if (strtoupper($bracket) === 'UTC') {
                return 0;
            }
            $om = null;
            if (preg_match('/^([+\-])(\d{2}):(\d{2})$/', $bracket, $om) === 1) {
                $sign = $om[1] === '+' ? 1 : -1;
                return $sign * (((int) $om[2] * 3600) + ((int) $om[3] * 60));
            }
            // IANA timezone in bracket: return null to signal epoch-dependent
            // resolution. Whether the name is recognized is decided by the caller's
            // normalization — an unknown one is a RangeError, and the inline offset
            // is not a fallback for it.
            return null;
        }
        // Datetime strings without bracket: use inline offset or Z.
        $om = null;
        if (preg_match('/[Tt].*?(Z|([+\-])(\d{2}):(\d{2}))/i', $tz, $om) === 1) {
            if ($om[1] === 'Z' || $om[1] === 'z') {
                return 0;
            }
            /** @var array{non-falsy-string, non-falsy-string, '+'|'-', non-falsy-string, non-falsy-string} $om */
            $sign = $om[2] === '+' ? 1 : -1;
            return $sign * (((int) $om[3] * 3600) + ((int) $om[4] * 60));
        }
        // IANA timezone name: look up the offset at the current epoch.
        // This method doesn't have access to the instant's epoch, so we
        // validate the timezone now and defer the offset computation to the caller.
        self::validateTimeZoneString($tz);
        return null; // Signal to caller that it's an IANA timezone needing epoch-relative offset.
    }

    /**
     * Resolves an IANA timezone to an offset in seconds at a given epoch second.
     *
     * @param non-empty-string $tz the IANA timezone name; the empty string is excluded
     *   by the type because every call site derives it from a non-empty source (a
     *   `[^\]]+` bracket capture, or a string already accepted by validateTimeZoneString)
     */
    private static function ianaOffsetSeconds(string $tz, int $epochSec): int
    {
        try {
            $phpTz = new \DateTimeZone($tz);
            return $phpTz->getOffset(new \DateTimeImmutable(sprintf('@%d', $epochSec)));
        } catch (\Exception) {
            return 0;
        }
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
     * Returns a locale-sensitive string for this Instant using IntlDateFormatter.
     *
     * Supports a subset of Intl.DateTimeFormat options:
     *   - dateStyle: "full" | "long" | "medium" | "short"
     *   - timeStyle: "full" | "long" | "medium" | "short"
     *   - timeZone: IANA timezone string (defaults to UTC for Instant)
     *   - calendar: calendar identifier appended as u-ca locale extension
     *
     * @param string|array<array-key, mixed>|null $locales  BCP 47 locale string or array of strings.
     * @param array<array-key, mixed>|object|null $options  Intl.DateTimeFormat options array.
     * @psalm-api
     */
    public function toLocaleString(string|array|null $locales = null, array|object|null $options = null): string
    {
        $locale = IntlFormatter::resolveLocale($locales);
        /** @var array<string, mixed> $opts */
        $opts = $options === null ? [] : Options::bagSnapshot($options, IntlFormatter::OPTION_NAMES);

        /** @var mixed $tzOpt */
        $tzOpt = $opts['timeZone'] ?? null;
        $timeZone = is_string($tzOpt) ? $tzOpt : 'UTC';

        $formatter = IntlFormatter::buildIntlFormatter($locale, $timeZone, $opts);
        [$seconds, $subNs] = $this->epochParts();
        $result = IntlFormatter::formatEpoch($formatter, $seconds, $subNs, $timeZone, $locale);

        return $result !== false ? $result : $this->toString();
    }

    /**
     * Returns a ZonedDateTime for this Instant in the given time zone.
     *
     * @param string $timeZone A timezone string: 'UTC', '±HH:MM', or an ISO datetime string
     *                        with an inline offset or bracket annotation (e.g. '2020-01-01T00:00Z').
     * @psalm-api used by test262 scripts
     * @throws RangeError if the timezone string is invalid (empty, sub-minute offset, etc.).
     */
    public function toZonedDateTimeISO(string $timeZone): ZonedDateTime
    {
        $tzId = self::parseTimeZoneId($timeZone);
        [$epochSec, $subNs] = $this->epochParts();
        return ZonedDateTime::fromEpochParts($epochSec, $subNs, $tzId);
    }

    /**
     * Parses a timezone string and returns its canonical timezone ID.
     *
     * Accepts: 'UTC' (case-insensitive), '±HH:MM', or ISO datetime strings
     * with an inline offset (Z or ±HH:MM) or a bracket annotation [tzId].
     * Sub-minute offsets, bare datetimes, and empty strings are rejected.
     *
     * @throws RangeError for invalid timezone strings.
     */
    private static function parseTimeZoneId(string $tz): string
    {
        if ($tz === '') {
            throw new RangeError('Time zone string must not be empty.');
        }
        // 'UTC' (case-insensitive).
        if (strtoupper($tz) === 'UTC') {
            return 'UTC';
        }
        // Reject minus-zero extended year.
        if (preg_match('/^-0{6}(?:[^0-9]|$)/', $tz) === 1) {
            throw new RangeError("Invalid time zone string \"{$tz}\": minus-zero year.");
        }

        // Determine if this looks like a datetime (has a T-separator after a date part).
        $isDatetime = preg_match('/\d{4,}-\d{2}-\d{2}[Tt]|\d{8}[Tt]/', $tz) === 1;

        if ($isDatetime) {
            // Bracket annotation takes precedence over the inline offset.
            $bm = null;
            if (preg_match('/\[(!?[^\]]+)\]/', $tz, $bm) === 1) {
                /** @var non-empty-string $bracket */
                $bracket = $bm[1];
                // Sub-minute offset in bracket: reject.
                if (preg_match('/^[+\-]\d{2}:\d{2}:\d{2}/', $bracket) === 1) {
                    throw new RangeError(
                        "Invalid time zone string \"{$tz}\": sub-minute offset in bracket annotation.",
                    );
                }
                if (strtoupper($bracket) === 'UTC') {
                    return 'UTC';
                }
                if (preg_match('/^[+\-]\d{2}:\d{2}$/', $bracket) === 1) {
                    return $bracket;
                }
                // Try as IANA timezone name.
                try {
                    new \DateTimeZone($bracket);
                    return TimeZoneHelper::normalizeTimezoneId($bracket);
                } catch (\Exception) {
                    throw new RangeError(
                        "Invalid time zone string \"{$tz}\": unsupported bracket timezone \"{$bracket}\".",
                    );
                }
            }
            // No bracket: inline offset (Z or ±HH:MM) required.
            // Reject sub-minute inline offset.
            if (preg_match('/[+\-]\d{2}:\d{2}:\d{2}/i', $tz) === 1) {
                throw new RangeError("Invalid time zone string \"{$tz}\": inline offset contains a seconds component.");
            }
            // Extract inline offset (Z or ±HH:MM at end or after time part).
            if (preg_match('/[Zz](?:\[|$)/', $tz) === 1) {
                return 'UTC';
            }
            $om = null;
            if (preg_match('/([+\-]\d{2}:\d{2})(?:\[|$)/', $tz, $om) === 1) {
                return $om[1];
            }
            // Bare datetime with no offset and no bracket.
            throw new RangeError("Invalid time zone string \"{$tz}\": bare datetime without Z, offset, or bracket.");
        }

        // Pure UTC-offset strings: accept only ±HH:MM (no seconds component).
        if (preg_match('/^[+\-]\d{2}:\d{2}$/', $tz) === 1) {
            return $tz;
        }
        // ±HHMM (compact form) → normalize to ±HH:MM.
        $m = null;
        if (preg_match('/^([+\-])(\d{2})(\d{2})$/', $tz, $m) === 1) {
            return sprintf('%s%s:%s', $m[1], $m[2], $m[3]);
        }
        // Anything with more than ±HH:MM (seconds or fractional) → reject.
        if (preg_match('/^[+\-]\d{2}:\d{2}[:.].*/i', $tz) === 1) {
            throw new RangeError(
                "Invalid time zone string \"{$tz}\": sub-minute offset is not a valid timezone identifier.",
            );
        }

        // IANA timezone name: validate via PHP DateTimeZone.
        try {
            new \DateTimeZone($tz);
            return TimeZoneHelper::normalizeTimezoneId($tz);
        } catch (\Exception) {
            throw new RangeError("Invalid time zone string \"{$tz}\": not a recognized timezone identifier.");
        }
    }

    /**
     * Returns a new Instant advanced by the given duration.
     *
     * Calendar fields (years, months, weeks, days) are forbidden — Instant has
     * no calendar context. Passing a Duration with any of those fields non-zero
     * throws RangeError.
     *
     * @param Duration|string|array<array-key, mixed>|object $duration Duration, ISO 8601 duration string, or property-bag array.
     * @psalm-api used by test262 scripts
     * @throws RangeError if the duration contains calendar fields or the result is out of range.
     */
    public function add(string|array|object $duration): self
    {
        $d = Duration::from($duration);
        if ($d->years !== 0 || $d->months !== 0 || $d->weeks !== 0 || $d->days !== 0) {
            throw new RangeError(
                'Calendrics\\Instant::add() does not support calendar fields (years, months, weeks, days).',
            );
        }
        [$epochSec, $subNs] = $this->epochParts();
        return self::addNsOffset(
            $epochSec,
            $subNs,
            $d->hours,
            $d->minutes,
            $d->seconds,
            $d->milliseconds,
            $d->microseconds,
            $d->nanoseconds,
        );
    }

    /**
     * Returns a new Instant moved back by the given duration.
     *
     * Calendar fields (years, months, weeks, days) are forbidden.
     *
     * @param Duration|string|array<array-key, mixed>|object $duration Duration, ISO 8601 duration string, or property-bag array.
     * @psalm-api used by test262 scripts
     * @throws RangeError if the duration contains calendar fields or the result is out of range.
     */
    public function subtract(string|array|object $duration): self
    {
        $d = Duration::from($duration);
        if ($d->years !== 0 || $d->months !== 0 || $d->weeks !== 0 || $d->days !== 0) {
            throw new RangeError(
                'Calendrics\\Instant::subtract() does not support calendar fields (years, months, weeks, days).',
            );
        }
        [$epochSec, $subNs] = $this->epochParts();
        return self::addNsOffset(
            $epochSec,
            $subNs,
            -$d->hours,
            -$d->minutes,
            -$d->seconds,
            -$d->milliseconds,
            -$d->microseconds,
            -$d->nanoseconds,
        );
    }

    /**
     * Returns a new Instant rounded to the given unit and increment.
     *
     * The $roundTo argument may be a string (treated as smallestUnit) or an
     * options array with keys: smallestUnit (required), roundingMode (default
     * 'halfExpand'), roundingIncrement (default 1).
     *
     * @param string|array<array-key, mixed>|object $roundTo
     * @psalm-api used by test262 scripts
     * @throws RangeError if smallestUnit is missing/invalid or roundingIncrement is invalid.
     * @throws TypeError if smallestUnit or roundingMode is a non-string.
     */
    public function round(string|array|object $roundTo): self
    {
        if (is_string($roundTo)) {
            $roundTo = ['smallestUnit' => $roundTo];
        } elseif (is_object($roundTo)) {
            // TC39: if roundTo is undefined, throw TypeError (required arg).
            if ($roundTo instanceof \Stringable) {
                $str = (string) $roundTo; // JsSymbol: throws; JsUndefined: returns 'undefined'
                if ($str === 'undefined') {
                    throw new TypeError('Instant::round() requires a non-undefined options argument.');
                }
            }
            $roundTo = Options::requireObject($roundTo, ['roundingIncrement', 'roundingMode', 'smallestUnit']);
        }

        /** @var mixed $suRaw */
        $suRaw = $roundTo['smallestUnit'] ?? null;
        if ($suRaw === null) {
            throw new RangeError('Calendrics\\Instant::round() requires smallestUnit.');
        }
        $suRaw = Options::coerceEnumOption($suRaw, 'smallestUnit');
        // Maps unit name → [ns-per-unit, max-increment-divisor (next unit size)]
        $unitMap = [
            'nanosecond' => [1, 86_400_000_000_000],
            'nanoseconds' => [1, 86_400_000_000_000],
            'microsecond' => [1_000, 86_400_000_000],
            'microseconds' => [1_000, 86_400_000_000],
            'millisecond' => [1_000_000, 86_400_000],
            'milliseconds' => [1_000_000, 86_400_000],
            'second' => [1_000_000_000, 86_400],
            'seconds' => [1_000_000_000, 86_400],
            'minute' => [60_000_000_000, 1_440],
            'minutes' => [60_000_000_000, 1_440],
            'hour' => [3_600_000_000_000, 24],
            'hours' => [3_600_000_000_000, 24],
        ];
        if (!array_key_exists($suRaw, $unitMap)) {
            throw new RangeError("Invalid smallestUnit \"{$suRaw}\" for Calendrics\\Instant::round().");
        }
        [$nsPerUnit, $maxDivisor] = $unitMap[$suRaw];

        $roundingMode = 'halfExpand';
        if (array_key_exists('roundingMode', $roundTo) && $roundTo['roundingMode'] !== null) {
            $rmRaw = Options::coerceEnumOption($roundTo['roundingMode'], 'roundingMode');
            self::validateRoundingMode($rmRaw);
            $roundingMode = $rmRaw;
        }

        $increment = 1;
        if (array_key_exists('roundingIncrement', $roundTo) && $roundTo['roundingIncrement'] !== null) {
            // Per TC39 ToTemporalRoundingIncrement: GetOption with type «Number» calls ToNumber,
            // which coerces booleans/numeric strings. CalendarMath::toFiniteInt mirrors that.
            $increment = CalendarMath::toFiniteInt($roundTo['roundingIncrement'], 'roundingIncrement');
        }
        if ($increment < 1) {
            throw new RangeError('roundingIncrement must be a positive integer.');
        }
        if ($increment > $maxDivisor || ($maxDivisor % $increment) !== 0) {
            throw new RangeError(
                "roundingIncrement {$increment} does not evenly divide {$maxDivisor} for unit \"{$suRaw}\".",
            );
        }

        $nsIncrement = $nsPerUnit * $increment;
        [$trueSec, $trueSubNs] = $this->epochParts();
        [$roundedSec, $roundedSubNs] = EpochRounding::round($trueSec, $trueSubNs, $nsIncrement, $roundingMode);
        return self::fromEpochParts($roundedSec, $roundedSubNs);
    }

    /**
     * Returns the elapsed time from $other to $this as a Duration.
     *
     * The result is positive when $this is after $other.
     *
     * @param string|object $other The starting instant (Instant or ISO string).
     * @param array<array-key, mixed>|object|null $options
     * @psalm-api used by test262 scripts
     */
    public function since(string|object $other, mixed $options = null): Duration
    {
        $otherInst = $other instanceof self ? $other : self::coerceToInstant($other);
        [$aSec, $aSubNs] = $this->epochParts();
        [$bSec, $bSubNs] = $otherInst->epochParts();
        return self::diffInstant($aSec - $bSec, $aSubNs - $bSubNs, $options);
    }

    /**
     * Returns the elapsed time from $this to $other as a Duration.
     *
     * The result is positive when $other is after $this.
     *
     * @param string|object $other The ending instant (Instant or ISO string).
     * @param array<array-key, mixed>|object|null $options
     * @psalm-api used by test262 scripts
     */
    public function until(string|object $other, mixed $options = null): Duration
    {
        $otherInst = $other instanceof self ? $other : self::coerceToInstant($other);
        [$aSec, $aSubNs] = $otherInst->epochParts();
        [$bSec, $bSubNs] = $this->epochParts();
        return self::diffInstant($aSec - $bSec, $aSubNs - $bSubNs, $options);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Validates a roundingMode string against the allowed TC39 set so an unknown
     * (or coerced non-enum) mode is rejected even on a fast path where no
     * rounding is performed.
     *
     * @throws RangeError for an unknown roundingMode.
     */
    private static function validateRoundingMode(string $mode): void
    {
        Options::roundingMode($mode);
    }

    /**
     * Computes a new Instant by adding a time-field offset to a true (epochSec, subNs) pair.
     *
     * All arithmetic is performed in the seconds domain, with sub-second
     * contributions carried separately, so no combined nanosecond value ever has
     * to fit int64. This keeps over-int64 (but in-spec) instants exact and makes
     * the max-sentinel + small-ns overflow case (no PHP_INT_MAX → PHP_INT_MIN
     * wraparound) impossible.
     *
     * @throws RangeError if the resulting instant is outside the Temporal spec range.
     */
    private static function addNsOffset(
        int $epochSec,
        int $subNs,
        int|float $hours,
        int|float $minutes,
        int|float $seconds,
        int|float $milliseconds,
        int|float $microseconds,
        int|float $nanoseconds,
    ): self {
        // Float approximation — used only to reject deltas so large their whole-
        // second magnitude could itself overflow int64 before decomposition.
        $floatDeltaSec =
            ((float) $hours * 3_600.0)
            + ((float) $minutes * 60.0)
            + (float) $seconds
            + ((float) $milliseconds / 1_000.0)
            + ((float) $microseconds / 1_000_000.0)
            + ((float) $nanoseconds / 1_000_000_000.0);
        // Spec range in seconds: |epochSec| ≤ 8_640_000_000_000. A delta whose
        // magnitude exceeds twice that can never land in range; reject early so
        // the integer seconds sum below cannot overflow.
        $specMaxSec = (float) EpochLimits::MAX_EPOCH_SECONDS;
        if ($floatDeltaSec > (4.0 * $specMaxSec) || $floatDeltaSec < (-4.0 * $specMaxSec)) {
            throw new RangeError('Instant result is outside the representable nanosecond range.');
        }

        // Decompose each field into whole seconds + sub-second nanoseconds.
        // Crucially, ms/us/ns are each split into a whole-second part (added in
        // the seconds domain) and a sub-second remainder, so that a huge field
        // value (e.g. microseconds ≈ 9e18) never forms an int64-overflowing
        // nanosecond product — which would silently wrap and skip the range check.
        $h = (int) $hours;
        $m = (int) $minutes;
        $s = (int) $seconds;

        // Each sub-second field is split via decomposeUnit(): exact integer math
        // when the field fits int64 (so values up to 9.2e18 stay precise), or a
        // float-domain split when the field exceeds int64 (so over-int64-but-in-spec
        // float fields like nanoseconds ≈ 1.728e22 don't overflow on the int cast).
        [$msSec, $msSubNs] = self::decomposeUnit($milliseconds, 1_000_000, 1_000);
        [$usSec, $usSubNs] = self::decomposeUnit($microseconds, 1_000, 1_000_000);
        [$nsSec, $nsSubNs] = self::decomposeUnit($nanoseconds, 1, 1_000_000_000);

        // Whole-second contribution from every field.
        $deltaSec = ($h * 3_600) + ($m * 60) + $s + $msSec + $usSec + $nsSec;

        // Sub-second contribution in nanoseconds: each field's remainder is < 1e9 ns
        // apiece (sum < 3e9, well within int64).
        $subDeltaNs = $msSubNs + $usSubNs + $nsSubNs;

        // fromEpochParts() normalizes the sub-ns carry and enforces the spec range.
        return self::fromEpochParts($epochSec + $deltaSec, $subNs + $subDeltaNs);
    }

    /**
     * Splits one sub-second duration field into a whole-second part and a
     * sub-second nanosecond remainder, without ever forming an int64-overflowing
     * nanosecond product.
     *
     * @param int|float $value          The field value (e.g. nanoseconds, microseconds).
     * @param int       $nsPerUnit      Nanoseconds per one of this unit (ns=1, us=1_000, ms=1_000_000).
     * @param int       $unitsPerSecond Units of this kind per second (ns=1e9, us=1e6, ms=1e3).
     * @return array{int, int} [wholeSeconds, subNanoseconds] where subNanoseconds
     *                         carries the sign of $value and |subNanoseconds| < 1e9.
     */
    private static function decomposeUnit(int|float $value, int $nsPerUnit, int $unitsPerSecond): array
    {
        // Fast exact path: the field fits int64, so integer division and the
        // sub-second remainder (always < 1e9 ns) are computed without precision loss.
        if (is_int($value)) {
            $whole = CalendarMath::floorDiv($value, $unitsPerSecond);
            $remainderUnits = $value - ($whole * $unitsPerSecond);
            return [$whole, $remainderUnits * $nsPerUnit];
        }

        // Over-int64 float field: split in the float domain. floor() yields the
        // whole-second count (≤ ~3.46e13 for any in-spec delta, exact in float and
        // int64), and the remainder stays < 1e9 ns.
        $wholeFloat = floor($value / (float) $unitsPerSecond);
        $whole = (int) $wholeFloat;
        $remainderUnits = $value - ($wholeFloat * (float) $unitsPerSecond);
        return [$whole, (int) ($remainderUnits * (float) $nsPerUnit)];
    }

    /**
     * Core implementation for since() and until().
     *
     * Rounds and balances a signed epoch-second / sub-second difference into a
     * Duration according to the given options. The difference is supplied as a
     * (seconds, sub-ns) pair rather than a single nanosecond value so that
     * over-int64 spans survive without clamping.
     *
     * Unit ordering (smallest to largest):
     *   nanosecond < microsecond < millisecond < second < minute < hour
     *
     * @param int $diffSec   Signed whole-second difference (this − other for since, other − this for until).
     * @param int $diffSubNs Signed sub-second nanosecond difference paired with $diffSec.
     * @param array<array-key, mixed>|object|null $options
     * @throws RangeError for invalid unit/mode strings or invalid roundingIncrement.
     * @throws TypeError for wrong-typed option values.
     */
    private static function diffInstant(int $diffSec, int $diffSubNs, array|object|null $options): Duration
    {
        // Normalize to true signed seconds with sub-ns in [0, 1e9). Then derive
        // a signed magnitude so the whole diff is represented as (sign, absSec,
        // absSubNs) — never a single int64 nanosecond value, so over-int64
        // spans survive intact.
        if ($diffSubNs < 0 || $diffSubNs >= EpochLimits::NS_PER_SECOND) {
            $carry = CalendarMath::floorDiv($diffSubNs, EpochLimits::NS_PER_SECOND);
            $diffSec += $carry;
            $diffSubNs -= $carry * EpochLimits::NS_PER_SECOND;
        }
        // Sign of the whole diff (subNs is now ≥ 0, so the diff is negative iff
        // diffSec < 0, zero iff both parts are zero).
        if ($diffSec < 0) {
            $diffSign = -1;
            // Magnitude of a negative value: -(diffSec*1e9 + diffSubNs).
            if ($diffSubNs > 0) {
                $absSec = -($diffSec + 1);
                $absSubNs = EpochLimits::NS_PER_SECOND - $diffSubNs;
            } else {
                $absSec = -$diffSec;
                $absSubNs = 0;
            }
        } else {
            $diffSign = $diffSec > 0 || $diffSubNs > 0 ? 1 : 0;
            $absSec = $diffSec;
            $absSubNs = $diffSubNs;
        }
        // Unit name → index (0 = smallest).
        $unitOrder = [
            'nanosecond' => 0,
            'nanoseconds' => 0,
            'microsecond' => 1,
            'microseconds' => 1,
            'millisecond' => 2,
            'milliseconds' => 2,
            'second' => 3,
            'seconds' => 3,
            'minute' => 4,
            'minutes' => 4,
            'hour' => 5,
            'hours' => 5,
        ];

        // ns-per-unit for each canonical index.
        $nsPerUnitByIndex = [
            0 => 1,
            1 => 1_000,
            2 => 1_000_000,
            3 => 1_000_000_000,
            4 => 60_000_000_000,
            5 => 3_600_000_000_000,
        ];

        // Maximum roundingIncrement per unit (TC39 MaximumTemporalDurationRoundingIncrement).
        $maxIncrementByIndex = [
            0 => 1_000, // ns → µs: max 999
            1 => 1_000, // µs → ms: max 999
            2 => 1_000, // ms → s:  max 999
            3 => 60, // s  → min: max 59
            4 => 60, // min → h: max 59
            5 => 24, // h: max 24 (must evenly divide 24 and be < 24)
        ];

        // ---- Parse options ----
        $options = Options::normalizeOptions($options, [
            'largestUnit',
            'roundingIncrement',
            'roundingMode',
            'smallestUnit',
        ]);

        // Track whether largestUnit was explicitly provided.
        $luProvided = array_key_exists('largestUnit', $options) && $options['largestUnit'] !== null;
        /** @var mixed $luVal */
        $luVal = $luProvided ? $options['largestUnit'] : null;
        /** @var mixed $suVal */
        $suVal = array_key_exists('smallestUnit', $options) ? $options['smallestUnit'] : null;

        if ($luVal !== null) {
            $luVal = Options::coerceEnumOption($luVal, 'largestUnit');
        }
        if ($suVal !== null) {
            $suVal = Options::coerceEnumOption($suVal, 'smallestUnit');
        }

        $suRaw = is_string($suVal) ? $suVal : 'nanosecond';

        if (!array_key_exists($suRaw, $unitOrder)) {
            throw new RangeError("Invalid smallestUnit \"{$suRaw}\".");
        }

        $suIdx = $unitOrder[$suRaw];

        if ($luProvided) {
            $luRaw = (string) $luVal;
            if (!array_key_exists($luRaw, $unitOrder)) {
                throw new RangeError("Invalid largestUnit \"{$luRaw}\".");
            }
            $luIdx = $unitOrder[$luRaw];
        } else {
            // Default: LargerOfTwoTemporalUnits('second', smallestUnit) → max(3, suIdx).
            $luIdx = max(3, $suIdx);
            $luRaw = $luIdx === $suIdx ? $suRaw : 'second';
        }

        // smallestUnit must not be larger than largestUnit.
        if ($suIdx > $luIdx) {
            throw new RangeError("smallestUnit \"{$suRaw}\" must not be larger than largestUnit \"{$luRaw}\".");
        }

        $roundingMode = 'trunc';
        if (array_key_exists('roundingMode', $options) && $options['roundingMode'] !== null) {
            $roundingMode = Options::coerceEnumOption($options['roundingMode'], 'roundingMode');
            self::validateRoundingMode($roundingMode);
        }

        $increment = 1;
        if (array_key_exists('roundingIncrement', $options) && $options['roundingIncrement'] !== null) {
            $increment = CalendarMath::toFiniteInt($options['roundingIncrement'], 'roundingIncrement');
        }
        if ($increment < 1) {
            throw new RangeError('roundingIncrement must be a positive integer.');
        }
        $maxInc = $maxIncrementByIndex[$suIdx];
        // increment must evenly divide maxInc AND be strictly less than maxInc.
        if ($increment >= $maxInc || $increment > 1 && ($maxInc % $increment) !== 0) {
            throw new RangeError(
                "roundingIncrement {$increment} is invalid for unit \"{$suRaw}\" (max is {$maxInc}, must divide evenly and be < {$maxInc}).",
            );
        }

        // ---- Round ----
        // Round on the absolute magnitude, decomposed into (seconds, sub-ns) so
        // the combined nanosecond value never has to fit int64. For directional
        // modes (floor, ceil, halfFloor, halfCeil), negate the mode when the diff
        // is negative so that e.g. floor(-376435.5h) = -376436h (toward -∞) rather
        // than -376435h. Matches TC39 DifferenceInstant step 15
        // (NegateTemporalRoundingMode).
        $nsInc = $nsPerUnitByIndex[$suIdx] * $increment;
        $effectiveMode = $roundingMode;
        if ($diffSign < 0) {
            $effectiveMode = match ($roundingMode) {
                'floor' => 'ceil',
                'ceil' => 'floor',
                'halfFloor' => 'halfCeil',
                'halfCeil' => 'halfFloor',
                default => $roundingMode,
            };
        }
        [$roundedSec, $roundedSubNs] = $nsInc === 1
            ? [$absSec, $absSubNs]
            : EpochRounding::round($absSec, $absSubNs, $nsInc, $effectiveMode);

        // ---- Balance ----
        // The rounded magnitude (roundedSec, roundedSubNs) is non-negative; build
        // the Duration from the seconds and sub-second parts separately so no
        // intermediate nanosecond value can overflow int64. Sign restored last.
        $sign = $diffSign;

        // Sub-second components (ns/us/ms) come entirely from roundedSubNs after
        // rounding has settled below the second boundary.
        $ns = $roundedSubNs;
        $us = 0;
        $ms = 0;
        if ($luIdx >= 1) { // at least microseconds
            $us = intdiv(num1: $ns, num2: 1_000);
            $ns -= $us * 1_000;
        }
        if ($luIdx >= 2) { // at least milliseconds
            $ms = intdiv(num1: $us, num2: 1_000);
            $us -= $ms * 1_000;
        }

        // Seconds and coarser components come from roundedSec.
        $s = $roundedSec;
        $min = 0;
        $h = 0;
        if ($luIdx >= 4) { // at least minutes
            $min = intdiv(num1: $s, num2: 60);
            $s -= $min * 60;
        }
        if ($luIdx >= 5) { // hours
            $h = intdiv(num1: $min, num2: 60);
            $min -= $h * 60;
        }

        // When largestUnit is below seconds, fold whole seconds back down into the
        // largest available sub-second unit (ms/us/ns) so the Duration still
        // represents the full magnitude.
        if ($luIdx < 3 && $roundedSec !== 0) {
            $s = 0;
            if ($luIdx === 2) {
                $ms += $roundedSec * 1_000;
            } elseif ($luIdx === 1) {
                $us += $roundedSec * 1_000_000;
            } else {
                $ns += $roundedSec * 1_000_000_000;
            }
        }

        return new Duration(0, 0, 0, 0, $sign * $h, $sign * $min, $sign * $s, $sign * $ms, $sign * $us, $sign * $ns);
    }
}
