<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

/**
 * Numeric operations used by upstream calendar fixtures on ICU part strings.
 *
 * @psalm-api used by dynamically-required test262 scripts
 */
final class JsNumber
{
    /** ECMAScript WhiteSpace and LineTerminator code points. */
    private const string SPACE = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** Number.isInteger does not coerce strings, booleans, or objects. */
    public static function isInteger(mixed $value): bool
    {
        return is_int($value) || is_float($value) && is_finite($value) && floor($value) === $value;
    }

    /** JS StringNumericLiteral conversion; nonnumeric month names produce NaN. */
    public static function fromString(string $value): float
    {
        $value = self::trimSpace($value);
        if ($value === '') {
            return 0.0;
        }
        if ($value === 'Infinity' || $value === '+Infinity') {
            return INF;
        }
        if ($value === '-Infinity') {
            return -INF;
        }
        if (preg_match('/^0[xX][0-9a-fA-F]+$/D', $value) === 1) {
            return (float) hexdec(substr($value, offset: 2));
        }
        if (preg_match('/^0[bB][01]+$/D', $value) === 1) {
            return (float) bindec(substr($value, offset: 2));
        }
        if (preg_match('/^0[oO][0-7]+$/D', $value) === 1) {
            return (float) octdec(substr($value, offset: 2));
        }
        if (preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $value) !== 1) {
            return NAN;
        }
        return (float) $value;
    }

    private static function trimSpace(string $value): string
    {
        $trimmed = preg_replace(
            sprintf('/^[%s]+|[%s]+$/u', self::SPACE, self::SPACE),
            replacement: '',
            subject: $value,
        );
        if ($trimmed === null) {
            throw new \TypeError('ICU part text must be valid UTF-8.');
        }
        return $trimmed;
    }
}
