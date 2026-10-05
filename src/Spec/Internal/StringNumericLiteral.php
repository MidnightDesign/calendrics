<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

/** Converts an ECMAScript StringNumericLiteral to its Number value. @internal */
final class StringNumericLiteral
{
    /** ECMAScript WhiteSpace and LineTerminator code points. */
    private const string SPACE = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** StringNumericLiteral conversion; nonnumeric text produces NaN. */
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
            throw new \TypeError('Numeric text must be valid UTF-8.');
        }
        return $trimmed;
    }
}
