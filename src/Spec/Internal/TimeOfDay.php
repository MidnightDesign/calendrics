<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;

/**
 * Nanoseconds-of-day arithmetic shared by the plain date/time types.
 *
 * A wall-clock time is one integer — nanoseconds since midnight — and every type that
 * carries one (PlainTime, PlainDateTime, the difference and duration engines) needs the
 * same three moves: fields to that integer, the integer back to fields, and rounding it
 * to a multiple of an increment under the nine TC39 rounding modes. Before this class
 * each of those was copy-pasted per type, and the rounding rule in particular had
 * drifted into three verbatim copies.
 *
 * Rounding here is the *non-negative* form: callers that round a signed difference
 * first take the absolute value and flip the directional modes (floor↔ceil,
 * halfFloor↔halfCeil), because "toward negative infinity" on a magnitude means the
 * opposite of what it means on the signed value.
 *
 * @internal
 */
final class TimeOfDay
{
    public const int NS_PER_DAY = 86_400_000_000_000;
    public const int NS_PER_HOUR = 3_600_000_000_000;
    public const int NS_PER_MINUTE = 60_000_000_000;

    private function __construct() {}

    /**
     * Converts wall-clock time fields to total nanoseconds since midnight.
     */
    public static function toNs(int $h, int $min, int $sec, int $ms, int $us, int $ns): int
    {
        return (
            ($h * self::NS_PER_HOUR)
            + ($min * self::NS_PER_MINUTE)
            + ($sec * EpochLimits::NS_PER_SECOND)
            + ($ms * EpochLimits::NS_PER_MILLISECOND)
            + ($us * EpochLimits::NS_PER_MICROSECOND)
            + $ns
        );
    }

    /**
     * Decomposes non-negative nanoseconds into [hour, minute, second, ms, µs, ns].
     *
     * @param int $ns Non-negative nanoseconds, normally less than {@see NS_PER_DAY}.
     * @return array{int, int, int, int, int, int}
     */
    public static function decompose(int $ns): array
    {
        $h = intdiv(num1: $ns, num2: self::NS_PER_HOUR);
        $rem = $ns % self::NS_PER_HOUR;
        $min = intdiv(num1: $rem, num2: self::NS_PER_MINUTE);
        $rem %= self::NS_PER_MINUTE;
        $sec = intdiv(num1: $rem, num2: EpochLimits::NS_PER_SECOND);
        $rem %= EpochLimits::NS_PER_SECOND;
        $msR = intdiv(num1: $rem, num2: EpochLimits::NS_PER_MILLISECOND);
        $rem %= EpochLimits::NS_PER_MILLISECOND;
        $usR = intdiv(num1: $rem, num2: EpochLimits::NS_PER_MICROSECOND);
        $nsR = $rem % EpochLimits::NS_PER_MICROSECOND;

        return [$h, $min, $sec, $msR, $usR, $nsR];
    }

    /**
     * Rounds a non-negative nanosecond value to a multiple of $increment.
     *
     * The halfEven tie breaks on the parity of the floor multiple's index, per TC39
     * RoundNumberToIncrement.
     *
     * @param int    $ns        Non-negative nanoseconds.
     * @param int    $increment Rounding increment in nanoseconds (>= 1).
     * @param string $mode      TC39 rounding mode name.
     * @return int Rounded nanoseconds (a multiple of $increment).
     * @throws RangeError for unknown rounding modes.
     */
    public static function roundPositive(int $ns, int $increment, string $mode): int
    {
        $q = intdiv(num1: $ns, num2: $increment);
        $rem = $ns - ($q * $increment);
        $r1 = $q * $increment; // floor multiple
        $r2 = $r1 + $increment; // ceil multiple
        if ($mode === 'halfEven') {
            $cmp = $rem * 2;
            if ($cmp < $increment) {
                return $r1;
            }
            if ($cmp > $increment) {
                return $r2;
            }
            return ($q % 2) === 0 ? $r1 : $r2;
        }
        return match ($mode) {
            'trunc', 'floor' => $r1,
            'ceil', 'expand' => $rem === 0 ? $r1 : $r2,
            'halfExpand', 'halfCeil' => ($rem * 2) >= $increment ? $r2 : $r1,
            'halfTrunc', 'halfFloor' => ($rem * 2) > $increment ? $r2 : $r1,
            default => throw new RangeError("Invalid roundingMode \"{$mode}\"."),
        };
    }
}
