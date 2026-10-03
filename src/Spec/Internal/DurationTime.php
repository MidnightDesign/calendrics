<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Spec\Duration;

/** @internal */
final class DurationTime
{
    /**
     * Exact time fields, excluding calendar days. Both parts have the duration's sign;
     * the nanosecond remainder has magnitude below one second.
     *
     * @return array{int, int}
     */
    public static function parts(Duration $duration): array
    {
        $seconds = ((int) $duration->hours * 3_600) + ((int) $duration->minutes * 60) + (int) $duration->seconds;
        if ($duration->milliseconds === 0 && $duration->microseconds === 0 && $duration->nanoseconds === 0) {
            return [$seconds, 0];
        }
        $subNs = 0;
        foreach ([
            [$duration->milliseconds, 3, 1_000_000],
            [$duration->microseconds, 6, 1_000],
            [$duration->nanoseconds, 9, 1],
        ] as [$field, $places, $scale]) {
            [$whole, $remainder] = self::splitField($field, $places);
            $seconds += $whole;
            $subNs += $remainder * $scale;
        }
        return [$seconds + intdiv($subNs, num2: 1_000_000_000), $subNs % 1_000_000_000];
    }

    /** @return array{int, int} */
    private static function splitField(int|float $value, int $places): array
    {
        if (is_int($value)) {
            $divisor = (int) 10 ** $places;
            return [intdiv($value, $divisor), $value % $divisor];
        }
        // A float field can exceed int64. Decimal splitting preserves its exact integer
        // value without rounding the quotient or overflowing an intermediate cast.
        $digits = str_pad(sprintf('%.0F', abs($value)), $places + 1, pad_string: '0', pad_type: STR_PAD_LEFT);
        $sign = $value < 0 ? -1 : 1;
        return [$sign * (int) substr($digits, offset: 0, length: -$places), $sign * (int) substr($digits, -$places)];
    }
}
