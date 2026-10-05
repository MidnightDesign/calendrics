<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

use Calendrics\Spec\Internal\StringNumericLiteral;

/**
 * Numeric operations used by upstream calendar fixtures on ICU part strings.
 *
 * @psalm-api used by dynamically-required test262 scripts
 */
final class JsNumber
{
    /** Number.isInteger does not coerce strings, booleans, or objects. */
    public static function isInteger(mixed $value): bool
    {
        return is_int($value) || is_float($value) && is_finite($value) && floor($value) === $value;
    }

    /** JS StringNumericLiteral conversion; nonnumeric month names produce NaN. */
    public static function fromString(string $value): float
    {
        return StringNumericLiteral::fromString($value);
    }
}
