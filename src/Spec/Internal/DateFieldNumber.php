<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;

/** Bounded representations of month/day inputs before calendar regulation. @internal */
final class DateFieldNumber
{
    public static function month(mixed $value, string $context): int
    {
        return self::bounded($value, $context, 13);
    }

    public static function day(mixed $value, string $context): int
    {
        return self::bounded($value, $context, 31);
    }

    /**
     * Every supported calendar has at most 13 months and 31 days per month.
     * Preserve excess as maximum + 1, rather than wrapping a wide float to int.
     * The sentinel cannot equal a valid monthCode ordinal. Calendar conversion
     * still performs the actual constrain/reject operation for its year/month.
     * Zero preserves the caller's existing nonpositive-field validation phase.
     */
    private static function bounded(mixed $value, string $context, int $maximum): int
    {
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }
        if (is_string($value)) {
            $value = StringNumericLiteral::fromString($value);
        } elseif (is_bool($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) && !is_float($value)) {
            throw new RangeError("{$context} must be numeric.");
        }
        if (!is_finite($value)) {
            throw new RangeError("{$context} must be finite.");
        }
        if ($value < 1) {
            return 0;
        }
        if ($value >= ($maximum + 1)) {
            return $maximum + 1;
        }
        return (int) $value;
    }
}
