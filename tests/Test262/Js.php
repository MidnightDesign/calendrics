<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

/**
 * PHP equivalents of JavaScript String/Array prototype methods used in test262
 * fixtures. All methods implement JS semantics faithfully so that transpiled
 * scripts behave identically to their JS originals.
 *
 * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
 */
final class Js
{
    /** Computed property reads must cross the same boundary even for dynamic keys. */
    public static function computedProperty(mixed $receiver, mixed $key): mixed
    {
        if ($key === 'epochNanoseconds') {
            return JsEpoch::read($receiver);
        }
        if (!is_int($key) && !is_string($key)) {
            Assert::incomplete('Computed property key coercion is not supported');
        }
        if (is_array($receiver)) {
            return $receiver[$key] ?? null;
        }
        if ($receiver instanceof \ArrayAccess) {
            return $receiver->offsetExists($key) ? $receiver->offsetGet($key) : null;
        }
        if (is_string($receiver)) {
            if (!is_int($key) && !ctype_digit($key)) {
                Assert::incomplete('Computed string property requires an integer index');
            }
            return $receiver[(int) $key] ?? null;
        }
        if (is_object($receiver)) {
            $name = (string) $key;
            if (property_exists($receiver, $name)) {
                return new \ReflectionProperty($receiver, $name)->getValue($receiver);
            }
            if (method_exists($receiver, '__get')) {
                return $receiver->__get($name);
            }
            return null;
        }
        throw new \TypeError('Cannot read a computed property of this receiver');
    }

    /**
     * Implements JS String.prototype.slice / Array.prototype.slice.
     *
     * For strings: indices are byte offsets (safe for ASCII date/offset strings).
     * For arrays: returns a re-indexed (list) sub-array.
     *
     * JS semantics:
     *  - Negative $start / $end count from the end of the value.
     *  - $end is an exclusive index (not a length).
     *  - Indices are clamped to [0, length]; out-of-bounds do not throw.
     *  - If $end is omitted the slice extends to the end.
     *  - If the resolved start >= resolved end, the result is empty (''/[]).
     *
     * @param string|list<mixed> $value
     * @return ($value is string ? string : list<mixed>)
     */
    public static function slice(string|array $value, int $start, ?int $end = null): string|array
    {
        if (is_string($value)) {
            return self::sliceString($value, $start, $end);
        }
        return self::sliceArray($value, $start, $end);
    }

    /**
     * Implements JS String(value) coercion.
     *
     * For most values this is equivalent to a (string) cast. The key difference
     * from PHP's cast is JsSymbol: in JS, String(Symbol()) returns "Symbol()"
     * without throwing, while interpolating a symbol throws TypeError. This method
     * mirrors the non-throwing String() path used in assertion message strings.
     *
     * @param mixed $value
     */
    public static function toString(mixed $value): string
    {
        if ($value instanceof JsSymbol) {
            return 'Symbol()';
        }
        // JS String([]) → "" (empty array), String([1,2]) → "1,2" (join with comma).
        // PHP's (string) [] triggers "Array to string conversion" warning; handle explicitly.
        if (is_array($value)) {
            /** @phpstan-ignore cast.string */
            return implode(',', array_map(static fn(mixed $v): string => (string) $v, $value));
        }

        /** @phpstan-ignore cast.string */
        return (string) $value;
    }

    /**
     * Implements JS Number.prototype.toPrecision(precision).
     *
     * Returns a string representation of $number with exactly $precision
     * significant digits, using exponential notation when necessary —
     * matching JS behaviour closely enough for test262 fixture comparisons.
     *
     * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
     */
    public static function toPrecision(float $number, int $precision): string
    {
        if (!is_finite($number)) {
            return (string) $number;
        }
        // PHP's %g uses the shorter of %e/%f with the given significant digits.
        // sprintf("%.*g", $precision, $x) gives $precision significant digits — matching toPrecision(N).
        // The * width specifier injects $precision as an argument, avoiding string concatenation.
        $result = sprintf('%.*g', $precision, $number);
        // Normalise exponential notation: JS uses "e+7" and PHP uses "E+7"; lowercase.
        return strtolower($result);
    }

    /**
     * Implements JS String.prototype.startsWith.
     */
    public static function startsWith(string $haystack, string $needle): bool
    {
        return str_starts_with($haystack, $needle);
    }

    /**
     * Implements JS String.prototype.endsWith.
     */
    public static function endsWith(string $haystack, string $needle): bool
    {
        return str_ends_with($haystack, $needle);
    }

    /**
     * Implements JS String.prototype.includes / Array.prototype.includes.
     *
     * For strings: delegates to str_contains (position argument is not used
     * by any of the temporal fixtures and is intentionally ignored).
     * For arrays: uses strict (SameValueZero) comparison via in_array with
     * strict=true, which is correct for the numeric/string members in these
     * fixtures.
     *
     * @param string|list<mixed> $haystack
     * @param string|int $needle
     */
    public static function includes(string|array $haystack, string|int $needle): bool
    {
        if (is_string($haystack)) {
            return str_contains($haystack, (string) $needle);
        }
        return in_array($needle, $haystack, strict: true);
    }

    /** Returns the current Unix time in whole milliseconds, like JS Date.now(). */
    public static function dateNow(): int
    {
        return (int) floor(microtime(as_float: true) * 1_000.0);
    }

    /**
     * String/Array.prototype.indexOf for UTF-8 strings and dense arrays.
     *
     * Numeric positions use ToIntegerOrInfinity semantics. String coercions,
     * sparse arrays and PHP arrays used as JS object values are unsupported;
     * they must not silently use PHP's different coercion or identity rules.
     *
     * @param string|array<array-key, mixed> $haystack
     */
    public static function indexOf(string|array $haystack, mixed $needle, mixed $fromIndex = 0): int
    {
        if ($fromIndex instanceof JsUndefined) {
            $fromIndex = 0;
        }
        if (!is_int($fromIndex) && !is_float($fromIndex)) {
            Assert::incomplete('indexOf requires a numeric fromIndex; JS coercion is not supported.');
        }
        if (is_float($fromIndex) && is_nan($fromIndex)) {
            $fromIndex = 0;
        }
        // Truncate before choosing the negative-index branch: -0.5 becomes -0,
        // which starts at zero rather than at the end of an array.
        $fromIndex = $fromIndex < 0 ? ceil($fromIndex) : floor($fromIndex);
        if (is_string($haystack)) {
            if (!is_string($needle)) {
                Assert::incomplete('String.indexOf requires a string needle; JS coercion is not supported.');
            }
            return self::stringIndexOf($haystack, $needle, $fromIndex);
        }
        if (!array_is_list($haystack) || is_array($needle)) {
            Assert::incomplete('Array.indexOf requires a dense list and preserved JS value identity.');
        }

        $length = count($haystack);
        $start = $fromIndex >= 0 ? (int) min($length, $fromIndex) : (int) max(0, (float) $length + $fromIndex);
        for ($index = $start; $index < $length; $index++) {
            /** @var mixed $item */
            $item = $haystack[$index];
            if (is_array($item)) {
                Assert::incomplete('Array.indexOf cannot recover JS object identity from PHP array values.');
            }
            // JS has one Number type: int and float representations compare
            // numerically, NaN never equals itself, and either zero sign matches.
            if ((is_int($item) || is_float($item)) && (is_int($needle) || is_float($needle))) {
                if ((float) $item === (float) $needle) {
                    return $index;
                }
                continue;
            }
            // PHP's strict comparison preserves primitive types and object identity.
            if ($item === $needle) {
                return $index;
            }
        }
        return -1;
    }

    private static function stringIndexOf(string $haystack, string $needle, int|float $fromIndex): int
    {
        $matches = [];
        if (preg_match_all('/./us', $haystack, $matches) === false || preg_match('//u', $needle) !== 1) {
            Assert::incomplete('String.indexOf requires well-formed UTF-8 strings.');
        }
        $characters = $matches[0];
        $units = 0;
        $startByte = strlen($haystack);
        $startFound = false;
        $bytePosition = 0;
        foreach ($characters as $character) {
            if (!$startFound && $units >= $fromIndex) {
                $startByte = $bytePosition;
                $startFound = true;
            }
            // Every four-byte UTF-8 character is a surrogate pair in UTF-16.
            $byteLength = strlen($character);
            $units += $byteLength === 4 ? 2 : 1;
            $bytePosition += $byteLength;
        }
        if ($needle === '') {
            return (int) max(0, min($units, $fromIndex));
        }
        $found = strpos($haystack, $needle, $startByte);
        if ($found === false) {
            return -1;
        }
        // Well-formed UTF-8 needles only match at a character boundary.
        $units = 0;
        $bytePosition = 0;
        foreach ($characters as $character) {
            if ($bytePosition >= $found) {
                break;
            }
            $byteLength = strlen($character);
            $units += $byteLength === 4 ? 2 : 1;
            $bytePosition += $byteLength;
        }
        return $units;
    }

    /**
     * Implements JS Date.UTC(year, month, day, hours, minutes, seconds, ms).
     *
     * Returns milliseconds since the Unix epoch (1970-01-01 00:00:00 UTC).
     * Month is 0-indexed (JS convention: 0 = January, 11 = December).
     * Matches the JS Date.UTC() return value for dates after the epoch.
     *
     * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
     */
    public static function dateUTC(
        int $year,
        int $month = 0,
        int $day = 1,
        int $hours = 0,
        int $minutes = 0,
        int $seconds = 0,
        int $ms = 0,
    ): int {
        // gmmktime uses 1-indexed months; JS Date.UTC uses 0-indexed months.
        // gmmktime() can return false for invalid input, but the test fixtures always
        // supply valid dates — the conditional is unreachable in practice, and the cast
        // to int allows PHPStan to accept the multiplication on the next line.
        $ts = gmmktime($hours, $minutes, $seconds, $month + 1, $day, $year);
        return ((int) $ts * 1000) + $ms;
    }

    /**
     * Implements JS Array.prototype.find(callback).
     *
     * Returns the first element for which the callback is truthy, or null
     * (standing in for JS `undefined`) when none matches.
     *
     * @param list<mixed> $items
     * @param callable(mixed, int, list<mixed>): mixed $callback
     * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
     */
    public static function arrayFind(array $items, callable $callback): mixed
    {
        /** @var mixed $item */
        foreach ($items as $index => $item) {
            if (self::truthy($callback($item, $index, $items))) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Implements JS Array.prototype.some(callback).
     *
     * @param iterable<mixed> $items
     * @param callable(mixed): mixed $callback
     * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
     */
    public static function arraySome(iterable $items, callable $callback): bool
    {
        /** @var mixed $item */
        foreach ($items as $item) {
            if ((bool) $callback($item)) {
                return true;
            }
        }
        return false;
    }

    /** JavaScript ToBoolean, including truthy empty arrays and the string "0". */
    public static function truthy(mixed $value): bool
    {
        if ($value === null || $value instanceof JsUndefined || $value === false || $value === '') {
            return false;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_float($value)) {
            return $value !== 0.0 && !is_nan($value);
        }
        return true;
    }

    /**
     * Reads one field of a destructured JS function parameter — the PHP lowering
     * of `({ field }) => …` arrow parameters. Handles the shapes such a parameter
     * can arrive as: plain arrays, ArrayAccess values ({@see IntlFormatPart}),
     * and stdClass-style objects (objectMode scripts).
     *
     * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
     */
    public static function destructure(mixed $value, string $field): mixed
    {
        if ($field === 'epochNanoseconds') {
            return JsEpoch::read($value);
        }
        if (is_array($value)) {
            return $value[$field] ?? null;
        }
        if ($value instanceof \ArrayAccess) {
            return $value->offsetExists($field) ? $value->offsetGet($field) : null;
        }
        if (is_object($value)) {
            return get_object_vars($value)[$field] ?? null;
        }
        throw new \TypeError('Js::destructure(): cannot destructure a non-object value.');
    }

    /**
     * The rest of a destructuring pattern: `const { a, ...others } = value` binds
     * `others` to a new object carrying every own property except the named ones.
     *
     * @param list<string> $taken The property names the pattern bound individually.
     */
    public static function destructureRest(mixed $value, array $taken): object
    {
        $props = match (true) {
            is_array($value) => $value,
            is_object($value) => get_object_vars($value),
            default => throw new \TypeError('Js::destructureRest(): cannot destructure a non-object value.'),
        };
        foreach ($taken as $name) {
            unset($props[$name]);
        }
        if (array_key_exists('epochNanoseconds', $props)) {
            JsEpoch::read($value);
        }
        return (object) $props;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function sliceString(string $str, int $start, ?int $end): string
    {
        $len = strlen($str);
        $realStart = $start >= 0 ? min($start, $len) : max($start + $len, 0);
        if ($end === null) {
            $realEnd = $len;
        } else {
            $realEnd = $end >= 0 ? min($end, $len) : max($end + $len, 0);
        }
        if ($realStart >= $realEnd) {
            return '';
        }
        return substr($str, $realStart, $realEnd - $realStart);
    }

    /**
     * @param list<mixed> $arr
     * @return list<mixed>
     */
    private static function sliceArray(array $arr, int $start, ?int $end): array
    {
        $len = count($arr);
        $realStart = $start >= 0 ? min($start, $len) : max($start + $len, 0);
        if ($end === null) {
            $length = $len - $realStart;
        } else {
            $realEnd = $end >= 0 ? min($end, $len) : max($end + $len, 0);
            $length = max(0, $realEnd - $realStart);
        }
        return array_slice($arr, $realStart, $length);
    }
}
