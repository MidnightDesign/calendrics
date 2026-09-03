<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\Internal\Calendar\CalendarProtocol;

/** @internal */
final class CalendarMath
{
    /**
     * Extracts an optional int field from a property bag, returning $default if absent.
     *
     * @param array<array-key, mixed> $bag
     * @param non-empty-string $field
     * @param non-empty-string $className Used in error messages (e.g. "PlainDateTime").
     * @throws RangeError if the field is present but null.
     * @throws RangeError if the value is non-finite.
     */
    public static function extractIntField(array $bag, string $field, int $default, string $className): int
    {
        if (!array_key_exists($field, $bag)) {
            return $default;
        }
        /** @var mixed $raw */
        $raw = $bag[$field];
        if ($raw === null) {
            throw new RangeError("{$className} property bag {$field} field must not be undefined.");
        }
        return self::toFiniteInt($raw, "{$className} {$field}");
    }

    /**
     * Returns true if the calendar exposes era/eraYear as recognized input fields.
     * Per TC39 Table 2 (Eras): iso8601, chinese, and dangi are eraless; every
     * other calendar id in the project's supported set has eras.
     */
    public static function supportsEras(?string $calendarId): bool
    {
        return $calendarId !== null
        && $calendarId !== 'iso8601'
        && !in_array($calendarId, ['chinese', 'dangi'], strict: true);
    }

    /**
     * Returns true if a property bag's era/eraYear values are read at all for this
     * calendar. ISO exposes no eras, so its CalendarExtraFields list omits both and their
     * values are never even coerced.
     *
     * Deliberately wider than {@see supportsEras()}, which also excludes the eraless
     * chinese/dangi: those two do read and coerce era fields today, then discard the
     * resolved year.
     */
    public static function readsEraFields(?string $calendarId): bool
    {
        return $calendarId !== null && $calendarId !== 'iso8601';
    }

    /**
     * Validates the era/eraYear pair on a property bag and returns true iff
     * both are present (and non-null). Per TC39 NonISOResolveFields step 11,
     * the pair check fires only for calendars where CalendarSupportsEra is
     * true; eraless calendars silently ignore both fields. PHP `null` is
     * treated as absent (= JS undefined), since the transpiler maps both
     * onto null.
     *
     * @param array<array-key, mixed> $bag
     * @throws TypeError if only one of era/eraYear is present on an era-supporting calendar.
     */
    public static function hasEraAndEraYear(array $bag, ?string $calendarId, string $className): bool
    {
        $hasEra = array_key_exists('era', $bag) && $bag['era'] !== null;
        $hasEraYear = array_key_exists('eraYear', $bag) && $bag['eraYear'] !== null;
        if (self::supportsEras($calendarId) && $hasEra !== $hasEraYear) {
            throw new TypeError("{$className} property bag must have both era and eraYear, or neither.");
        }
        return $hasEra && $hasEraYear;
    }

    /**
     * Coerces an `era` bag value to a string per the spec's `to-string`
     * conversion in PrepareCalendarFields, then delegates to
     * `$calendar->resolveEra()`. Treats null era or eraYear as absent
     * (PrepareCalendarFields skips conversion for undefined values).
     * Returns null for eraless calendars (chinese, dangi); the caller
     * must handle that case if it implies a later TypeError per
     * NonISOResolveFields.
     *
     * @throws TypeError if the era value cannot be coerced to a string.
     */
    public static function resolveYearFromEra(
        CalendarProtocol $calendar,
        mixed $eraRaw,
        mixed $eraYearRaw,
        string $errorContext,
    ): ?int {
        if ($eraRaw === null || $eraYearRaw === null) {
            return null;
        }
        if (is_string($eraRaw)) {
            $eraStr = $eraRaw;
        } elseif (is_scalar($eraRaw) || $eraRaw instanceof \Stringable) {
            $eraStr = (string) $eraRaw;
        } else {
            throw new TypeError(sprintf('%s era must be a string; got %s.', $errorContext, get_debug_type($eraRaw)));
        }
        $eraYearInt = self::toFiniteInt($eraYearRaw, "{$errorContext} eraYear");
        return $calendar->resolveEra($eraStr, $eraYearInt);
    }

    /**
     * Validates that a mixed value is finite and converts it to int.
     *
     * @throws RangeError if the value is non-finite, non-numeric, or otherwise
     *         not coercible to a number.
     * @throws TypeError if the value is a Symbol (its `__toString` throws).
     */
    public static function toFiniteInt(mixed $value, string $errorContext): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new RangeError("{$errorContext} must be finite.");
            }
            return (int) $value;
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        if (is_string($value)) {
            if (!is_numeric($value)) {
                throw new RangeError("{$errorContext} must be numeric.");
            }
            $floatVal = (float) $value;
            if (!is_finite($floatVal)) {
                throw new RangeError("{$errorContext} must be finite.");
            }
            return (int) $floatVal;
        }
        // Stringable: cast to string then re-run the numeric checks. The JsSymbol
        // sentinel's __toString throws Calendrics\Exception\TypeError here, while a
        // plain stdClass (not Stringable) falls through to RangeError below.
        if ($value instanceof \Stringable) {
            $str = (string) $value;
            if (!is_numeric($str)) {
                throw new RangeError("{$errorContext} must be numeric.");
            }
            $floatVal = (float) $str;
            if (!is_finite($floatVal)) {
                throw new RangeError("{$errorContext} must be finite.");
            }
            return (int) $floatVal;
        }
        throw new RangeError("{$errorContext} must be numeric.");
    }

    /**
     * TC39 ToIntegerWithTruncation for constructor numeric arguments.
     *
     * Like toFiniteInt but treats null as 0 (TC39 ToNumber(null) = 0 and
     * ToNumber(undefined) = NaN which truncates to 0, and PHP maps both omitted
     * and null to null at optional-parameter call sites).
     *
     * Called by Plain* constructors whose positional numeric arguments apply the
     * ToIntegerWithTruncation abstract operation.
     *
     * @throws RangeError if the value is NaN or ±Infinity.
     * @throws TypeError  (via JsSymbol.__toString) if the value is a Symbol.
     */
    public static function toConstructorInt(mixed $value, string $errorContext): int
    {
        if ($value === null) {
            return 0;
        }
        return self::toFiniteInt($value, $errorContext);
    }

    /**
     * Validates bracket annotations in a Temporal string (e.g. from `from()` or `fromISO()`).
     *
     * Rejects: uppercase annotation keys, critical unknown annotations, multiple time-zone
     * annotations, sub-minute UTC offsets inside time-zone annotations, and unknown calendar IDs.
     *
     * When $checkCalendar is true (the default), validates the first u-ca annotation value
     * against the known calendar list and returns the canonicalized calendar ID (or null if
     * no u-ca annotation is present). Pass false for types that do not use a calendar
     * (PlainTime, Instant) where the Temporal spec requires calendar annotations to be
     * ignored regardless of value; in that case null is always returned.
     *
     * @return ?string Canonicalized calendar ID from the first u-ca annotation, or null.
     * @throws RangeError on any violation.
     */
    public static function validateAnnotations(string $section, string $original, bool $checkCalendar = true): ?string
    {
        if ($section === '') {
            return null;
        }

        $tzCount = 0;
        $calCount = 0;
        $calHasCritical = false;
        $calendarId = null;

        $matches = null;
        preg_match_all('/\[(!?)([^\]]*)\]/', $section, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            [, $bang, $content] = $match;
            $critical = $bang === '!';

            if (str_contains($content, '=')) {
                [$key] = explode(separator: '=', string: $content, limit: 2);

                if ($key !== strtolower($key)) {
                    throw new RangeError(
                        "Invalid annotation key \"{$key}\" in \"{$original}\": annotation keys must be lowercase.",
                    );
                }

                if ($key === 'u-ca') {
                    if ($checkCalendar && $calCount === 0) {
                        $calValue = substr(string: $content, offset: strlen($key) + 1);
                        if (!CalendarFactory::isKnownCalendar($calValue)) {
                            throw new RangeError("Unknown calendar \"{$calValue}\" in \"{$original}\".");
                        }
                        $calendarId = CalendarFactory::canonicalize($calValue);
                    }
                    ++$calCount;
                    if ($critical) {
                        $calHasCritical = true;
                    }
                    if ($calCount > 1 && $calHasCritical) {
                        throw new RangeError("Multiple calendar annotations with critical flag in \"{$original}\".");
                    }
                } else {
                    if ($critical) {
                        throw new RangeError("Critical unknown annotation \"[!{$content}]\" in \"{$original}\".");
                    }
                }
            } else {
                ++$tzCount;
                if ($tzCount > 1) {
                    throw new RangeError("Multiple time-zone annotations in \"{$original}\".");
                }
                // Offset-style TZ annotation: reject sub-minute (seconds component).
                if (preg_match('/^[+-]/', $content) === 1) {
                    if (
                        preg_match('/^[+-]\d{2}:\d{2}:\d{2}/', $content) === 1
                        || preg_match('/^[+-]\d{2}:\d{2}[.,]/', $content) === 1
                    ) {
                        throw new RangeError("Sub-minute UTC offset in time-zone annotation in \"{$original}\".");
                    }
                    if (preg_match('/^[+-]\d{2}(?!\d*:)\d{4,}/', $content) === 1) {
                        throw new RangeError("Sub-minute UTC offset in time-zone annotation in \"{$original}\".");
                    }
                }
            }
        }

        return $calendarId;
    }

    /**
     * Determines whether to round up based on fractional progress through the current unit.
     *
     * `$absFloorUnits` is the number of whole units already accumulated (the absolute floor
     * count, i.e. the integer multiple of the increment at the bucket's lower boundary).
     * It is only consulted by `halfEven`, which rounds 0.5 ties to the nearest even value.
     *
     * For negative diffs, floor and ceil are swapped so they retain their directional meaning.
     */
    public static function applyRoundingProgress(float $progress, string $mode, int $sign, int $absFloorUnits = 0): bool
    {
        // For negative diffs, flip floor/ceil so they retain their directional meaning.
        $effectiveMode = $mode;
        if ($sign < 0) {
            $effectiveMode = match ($mode) {
                'floor' => 'ceil',
                'ceil' => 'floor',
                'halfFloor' => 'halfCeil',
                'halfCeil' => 'halfFloor',
                default => $mode,
            };
        }
        return match ($effectiveMode) {
            'trunc', 'floor' => false,
            'ceil', 'expand' => $progress > 0.0,
            'halfExpand', 'halfCeil' => $progress >= 0.5,
            'halfTrunc', 'halfFloor' => $progress > 0.5,
            'halfEven' => $progress > 0.5 || $progress === 0.5 && ($absFloorUnits % 2) !== 0,
            default => false,
        };
    }

    /**
     * Determines whether to round up based on whole units + sub-unit fractional progress.
     *
     * Unlike {@see applyRoundingProgress} (which takes a single float progress ratio),
     * this variant works with a `$wholeUnits` count and a separate `$progress` fraction,
     * allowing correct `halfEven` tie-breaking via the quotient parity.
     *
     * Used by `PlainDateTime` and `ZonedDateTime` rounding paths. ZDT callers pre-negate
     * the mode via `negateRoundingMode` before calling, so they pass `$sign = 1` (default).
     *
     * @param int    $wholeUnits The total number of whole units (for `halfEven`: determines evenness).
     * @param float  $progress   Fractional progress within the current unit, in [0.0, 1.0).
     * @param int    $increment  The rounding increment.
     * @param string $mode       The rounding mode string.
     * @param int    $sign       1 for positive durations, -1 for negative (flips floor/ceil).
     * @return bool True if the value should be rounded up.
     */
    public static function applyCalendarRoundingProgress(
        int $wholeUnits,
        float $progress,
        int $increment,
        string $mode,
        int $sign = 1,
    ): bool {
        $q = intdiv(num1: $wholeUnits, num2: $increment);
        $unitRem = $wholeUnits - ($q * $increment);
        $hasFraction = $unitRem > 0 || $progress > 0.0;
        $halfPoint = (float) $increment / 2.0;
        $totalFrac = (float) $unitRem + $progress;

        $effectiveMode = $mode;
        if ($sign < 0) {
            $effectiveMode = match ($mode) {
                'floor' => 'ceil',
                'ceil' => 'floor',
                'halfFloor' => 'halfCeil',
                'halfCeil' => 'halfFloor',
                default => $mode,
            };
        }
        return match ($effectiveMode) {
            'trunc', 'floor' => false,
            'ceil', 'expand' => $hasFraction,
            'halfExpand', 'halfCeil' => $totalFrac >= $halfPoint,
            'halfTrunc', 'halfFloor' => $totalFrac > $halfPoint,
            'halfEven' => $totalFrac > $halfPoint || $totalFrac === $halfPoint && ($q % 2) !== 0,
            default => false,
        };
    }

    /**
     * Validates all time fields and throws if any are out of their valid range.
     *
     * @phpstan-assert int<0, 23> $h
     * @phpstan-assert int<0, 59> $min
     * @phpstan-assert int<0, 59> $sec
     * @phpstan-assert int<0, 999> $ms
     * @phpstan-assert int<0, 999> $us
     * @phpstan-assert int<0, 999> $ns
     * @throws RangeError if any field is out of its valid range.
     */
    public static function validateTimeFields(int $h, int $min, int $sec, int $ms, int $us, int $ns): void
    {
        if ($h < 0 || $h > 23) {
            throw new RangeError("Invalid time: hour {$h} is out of range 0–23.");
        }
        if ($min < 0 || $min > 59) {
            throw new RangeError("Invalid time: minute {$min} is out of range 0–59.");
        }
        if ($sec < 0 || $sec > 59) {
            throw new RangeError("Invalid time: second {$sec} is out of range 0–59.");
        }
        if ($ms < 0 || $ms > 999) {
            throw new RangeError("Invalid time: millisecond {$ms} is out of range 0–999.");
        }
        if ($us < 0 || $us > 999) {
            throw new RangeError("Invalid time: microsecond {$us} is out of range 0–999.");
        }
        if ($ns < 0 || $ns > 999) {
            throw new RangeError("Invalid time: nanosecond {$ns} is out of range 0–999.");
        }
    }

    /**
     * Converts time-of-day fields to total nanoseconds since midnight.
     */
    public static function timeToNs(int $h, int $min, int $sec, int $ms, int $us, int $ns): int
    {
        return (
            ($h * 3_600_000_000_000)
            + ($min * 60_000_000_000)
            + ($sec * 1_000_000_000)
            + ($ms * 1_000_000)
            + ($us * 1_000)
            + $ns
        );
    }

    /**
     * Splits nanoseconds since midnight (0 ≤ $timeNs < 86,400,000,000,000) back
     * into time-of-day fields.
     *
     * @return array{int, int, int, int, int, int} [hour, minute, second, ms, us, ns]
     */
    public static function nsToTime(int $timeNs): array
    {
        $h = intdiv(num1: $timeNs, num2: 3_600_000_000_000);
        $rem = $timeNs % 3_600_000_000_000;
        $min = intdiv(num1: $rem, num2: 60_000_000_000);
        $rem %= 60_000_000_000;
        $sec = intdiv(num1: $rem, num2: 1_000_000_000);
        $rem %= 1_000_000_000;
        $ms = intdiv(num1: $rem, num2: 1_000_000);
        $rem %= 1_000_000;
        $us = intdiv(num1: $rem, num2: 1_000);
        $ns = $rem % 1_000;

        return [$h, $min, $sec, $ms, $us, $ns];
    }

    /**
     * Validates and returns the integer value of a `roundingIncrement` option.
     *
     * Accepts int, float, string, or bool. Returns the truncated integer value
     * in the range 1–1,000,000,000 (the maximum for any sub-second unit).
     *
     * Two-tier design: this version applies the universal 1e9 upper bound and is
     * used by the Plain* and ZonedDateTime classes, where the spec-level maximum
     * for any rounding-increment unit is 1e9 nanoseconds. {@see Options::roundingIncrement()}
     * is the lighter core (coerce + finite + ≥ 1 only, no upper bound) used by
     * Duration, which performs its own operation-specific range check at the call
     * site after the increment is validated.
     *
     * @throws RangeError if the value is non-numeric, NaN, infinite, or outside 1–1000000000.
     * @throws TypeError if the value is a Symbol (its `__toString` throws).
     */
    public static function validateRoundingIncrement(mixed $value): int
    {
        if (!is_int($value) && !is_float($value) && !is_string($value) && !is_bool($value)) {
            // Stringable: cast to string so the JsSymbol sentinel's __toString
            // raises Calendrics\Exception\TypeError; everything else => RangeError.
            if ($value instanceof \Stringable) {
                $value = (string) $value;
            } else {
                throw new RangeError('roundingIncrement must be numeric.');
            }
        }
        $riFloat = (float) $value;
        if (is_nan($riFloat) || !is_finite($riFloat)) {
            throw new RangeError('roundingIncrement must be a finite number.');
        }
        $riInt = (int) $riFloat; // truncate toward zero per spec
        if ($riInt < 1 || $riInt > 1_000_000_000) {
            throw new RangeError("roundingIncrement {$riInt} is out of range; must be 1–1000000000.");
        }
        return $riInt;
    }

    /**
     * Validates an ISO month code and returns the month number 1–12.
     *
     * @return int<1, 12>
     * @throws RangeError if the month code is not M01–M12.
     */
    public static function monthCodeToMonth(string $monthCode): int
    {
        if (preg_match('/^M(0[1-9]|1[0-2])$/', $monthCode) !== 1) {
            throw new RangeError("Invalid monthCode for ISO calendar: \"{$monthCode}\".");
        }
        /** @var int<1, 12> */
        return (int) substr($monthCode, offset: 1);
    }

    /**
     * Floor division: rounds towards negative infinity (unlike intdiv which truncates towards zero).
     *
     * Required for correct Julian Day Number conversions with negative years.
     */
    public static function floorDiv(int $a, int $b): int
    {
        $q = intdiv($a, $b);
        $r = $a - ($q * $b);
        return $r < 0 ? $q - 1 : $q;
    }

    public static function isLeapYear(int $year): bool
    {
        return ($year % 4) === 0 && ($year % 100) !== 0 || ($year % 400) === 0;
    }

    /**
     * @param int $month
     * @return int<28, 31>
     */
    public static function calcDaysInMonth(int $year, int $month): int
    {
        return match ($month) {
            1, 3, 5, 7, 8, 10, 12 => 31,
            4, 6, 9, 11 => 30,
            2 => self::isLeapYear($year) ? 29 : 28,
            default => throw new \ValueError("Month must be between 1 and 12, got {$month}."),
        };
    }

    /**
     * Day of year for a proleptic ISO date.
     */
    public static function isoDayOfYear(int $year, int $month, int $day): int
    {
        /** @var array<int, int> $cumDays */
        static $cumDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $doy = $cumDays[$month - 1] + $day;
        if ($month > 2 && self::isLeapYear($year)) {
            $doy++;
        }
        return $doy;
    }

    /**
     * ISO 8601 day of week, derived from the Julian Day Number.
     *
     * Returns 1 = Monday … 7 = Sunday.
     *
     * JDN 0 is a Monday, so the weekday is the JDN modulo 7. The modulo is taken
     * Euclidean rather than with PHP's `%`, whose result carries the sign of the
     * dividend: JDNs go negative around ISO year -4713, well inside Temporal's
     * ±273,972-year range, and a truncated remainder would map those days onto
     * negative weekday numbers.
     *
     * Sharing {@see self::toJulianDay()} keeps the proleptic-Gregorian leap-year
     * math in one place; a Sakamoto-style formula would have to repeat it, and
     * repeats it wrongly for negative years unless every division floors too.
     *
     * @return int<1, 7>
     */
    public static function isoWeekday(int $year, int $month, int $day): int
    {
        $jdn = self::toJulianDay($year, $month, $day);
        return ((($jdn % 7) + 7) % 7) + 1;
    }

    /**
     * Ordinal day of the year (1 = January 1).
     *
     * @return int<1, 366>
     */
    public static function calcDayOfYear(int $year, int $month, int $day): int
    {
        /** @var array<int, int> $cumDays */
        static $cumDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $result = $cumDays[$month - 1] + $day;
        if ($month > 2 && self::isLeapYear($year)) {
            $result++;
        }
        /** @var int<1, 366> $result — max 335 + 31 = 366 (Dec 31 in leap year) */
        return $result;
    }

    /**
     * Returns the ISO 8601 week number and week-year for the given date.
     *
     * @return array{week: int<1, 53>, year: int}
     * @psalm-suppress UnusedMethod — called from weekOfYear and yearOfWeek property hooks
     */
    public static function isoWeekInfo(int $year, int $month, int $day): array
    {
        $dow = self::isoWeekday($year, $month, $day);
        $ordinal = self::calcDayOfYear($year, $month, $day);

        // Move to the Thursday of this ISO week; its ordinal determines the week number.
        $thursdayOrdinal = $ordinal + (4 - $dow);

        if ($thursdayOrdinal < 1) {
            // Thursday fell in the previous year → last week of that year.
            $prevYear = $year - 1;
            $dec31Dow = self::isoWeekday($prevYear, 12, 31);
            $dec31Ord = self::isLeapYear($prevYear) ? 366 : 365;
            /** @var int<1, 53> ISO week of previous year's Dec 31 */
            $prevWeek = intdiv(num1: $dec31Ord + (4 - $dec31Dow) - 1, num2: 7) + 1;
            return ['week' => $prevWeek, 'year' => $prevYear];
        }

        $yearDays = self::isLeapYear($year) ? 366 : 365;
        if ($thursdayOrdinal > $yearDays) {
            // Thursday fell in the next year → week 1 of next year.
            return ['week' => 1, 'year' => $year + 1];
        }

        /** @var int<1, 53> thursdayOrdinal 1–366 maps to week 1–53 */
        $week = intdiv(num1: $thursdayOrdinal - 1, num2: 7) + 1;
        return ['week' => $week, 'year' => $year];
    }

    /** @var array<int, int> Memoized toJulianDay results, keyed by encoded (year, month, day). */
    private static array $toJulianDayCache = [];

    /** @var array<int, array{0: int, 1: int<1, 12>, 2: int<1, 31>}> Memoized fromJulianDay results, keyed by JDN. */
    private static array $fromJulianDayCache = [];

    /**
     * Converts a proleptic Gregorian calendar date to a Julian Day Number.
     * Algorithm: Richards (2013).
     */
    public static function toJulianDay(int $year, int $month, int $day): int
    {
        // Packed int key (month ≤ 12 < 32, day ≤ 31 < 32) is injective over all (year, month, day).
        $key = ($year * 512) + ($month * 32) + $day;
        if (array_key_exists($key, self::$toJulianDayCache)) {
            return self::$toJulianDayCache[$key];
        }

        $a = intdiv(num1: 14 - $month, num2: 12);
        $y = $year + 4800 - $a;
        $m = $month + (12 * $a) - 3;

        // For $y ≥ 0, intdiv ≡ floorDiv. Slow path only for very-negative years.
        if ($y >= 0) {
            $jdn =
                $day + intdiv(num1: (153 * $m) + 2, num2: 5) + (365 * $y) + intdiv(num1: $y, num2: 4)
                    - intdiv(num1: $y, num2: 100)
                    + intdiv(num1: $y, num2: 400)
                - 32_045;
        } else {
            $jdn =
                $day + intdiv(num1: (153 * $m) + 2, num2: 5) + (365 * $y) + self::floorDiv($y, 4)
                    - self::floorDiv($y, 100)
                    + self::floorDiv($y, 400)
                - 32_045;
        }

        return self::$toJulianDayCache[$key] = $jdn;
    }

    /**
     * Converts a Julian Day Number to a proleptic Gregorian calendar date.
     *
     * @return array{0: int, 1: int<1, 12>, 2: int<1, 31>} [year, month, day]
     */
    public static function fromJulianDay(int $jdn): array
    {
        if (array_key_exists($jdn, self::$fromJulianDayCache)) {
            return self::$fromJulianDayCache[$jdn];
        }
        $a = $jdn + 32_044;

        // Inline floorDiv: for $a ≥ 0 (jdn ≥ -32044, covers all realistic dates),
        // intdiv ≡ floorDiv. Slow path only for extremely-negative jdn.
        if ($a >= 0) {
            $b = intdiv(num1: (4 * $a) + 3, num2: 146_097);
            $c = $a - intdiv(num1: 146_097 * $b, num2: 4);
            $d = intdiv(num1: (4 * $c) + 3, num2: 1_461);
            $e = $c - intdiv(num1: 1_461 * $d, num2: 4);
            $m = intdiv(num1: (5 * $e) + 2, num2: 153);
        } else {
            $b = self::floorDiv((4 * $a) + 3, 146_097);
            $c = $a - self::floorDiv(146_097 * $b, 4);
            $d = self::floorDiv((4 * $c) + 3, 1_461);
            $e = $c - self::floorDiv(1_461 * $d, 4);
            $m = self::floorDiv((5 * $e) + 2, 153);
        }

        /** @var int<1, 31> Richards algorithm guarantees day is 1–31 */
        $day = $e - intdiv(num1: (153 * $m) + 2, num2: 5) + 1;
        $mDiv10 = intdiv(num1: $m, num2: 10);
        /** @var int<1, 12> Richards algorithm guarantees month is 1–12 */
        $month = $m + 3 - (12 * $mDiv10);
        $year = (100 * $b) + $d - 4800 + $mDiv10;

        return self::$fromJulianDayCache[$jdn] = [$year, $month, $day];
    }
}
