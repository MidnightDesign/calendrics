<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

/**
 * Scanners for the two ISO 8601 tokens every Temporal string parser decodes identically:
 * the fractional-second token and the UTC-offset token.
 *
 * Both are pure lexical conversions — they translate an already-matched token into
 * numbers and take no position on whether the result is in range. The spec-range checks
 * stay at the call sites, whose RangeError wording is class-specific (see
 * {@see EpochValue} for the same division of labor).
 *
 * @internal
 * @psalm-internal Temporal\Spec
 */
final class IsoToken
{
    /**
     * Strips the leading separator from a fractional-second token and truncates or pads
     * the digits to exactly nine, returning the nanosecond count.
     *
     * The Temporal grammar allows an arbitrarily long fraction; digits past the ninth are
     * discarded (truncation, not rounding).
     *
     * @param string $fractionRaw The matched token including its leading '.' or ','.
     * @return int<0, 999999999>
     */
    public static function fractionNanoseconds(string $fractionRaw): int
    {
        $digits = substr(string: $fractionRaw, offset: 1);
        /** @var int<0, 999999999> — 9 decimal digits, range 000000000–999999999 */
        return (int) str_pad(substr(string: $digits, offset: 0, length: 9), length: 9, pad_string: '0');
    }

    /**
     * Decomposes a UTC-offset token into [sign, absolute seconds, fractional nanoseconds].
     *
     * Accepted forms:
     *   Z | z                      → [+1, 0, 0]
     *   ±HH                        → [sign, H×3600, 0]
     *   ±HH:MM | ±HH:MM:SS[.,f]    → colon-separated
     *   ±HHMM   | ±HHMMSS[.,f]     → no separators
     *
     * The magnitude is returned as scanned, and the grammar admits two digits per field,
     * so '+99:00' scans to 356_400 seconds. Rejecting an offset of 24 hours or more is
     * the caller's job — it is the one that knows which string the token came from and
     * can name it in the error.
     *
     * @return array{-1|1, int<0, max>, int<0, 999999999>} [sign (+1|-1), absSec, fracNs]
     */
    public static function offsetParts(string $offset): array
    {
        if ($offset === 'Z' || $offset === 'z') {
            return [1, 0, 0];
        }

        $sign = $offset[0] === '+' ? 1 : -1;
        $rest = substr(string: $offset, offset: 1); // digits (and separators) after the sign

        $hours = (int) substr(string: $rest, offset: 0, length: 2);
        $rest = substr(string: $rest, offset: 2);
        $minutes = 0;
        $seconds = 0;
        $fracNs = 0;

        if ($rest !== '') {
            if ($rest[0] === ':') {
                // Colon-separated: :MM[:SS[.frac]]
                $minutes = (int) substr(string: $rest, offset: 1, length: 2);
                $rest = substr(string: $rest, offset: 3);
                if (str_starts_with($rest, ':')) {
                    $seconds = (int) substr(string: $rest, offset: 1, length: 2);
                    $rest = substr(string: $rest, offset: 3);
                    if (str_starts_with($rest, '.') || str_starts_with($rest, ',')) {
                        $fracNs = self::fractionNanoseconds($rest);
                    }
                }
            } else {
                // No separators: MM[SS[.frac]]
                $minutes = (int) substr(string: $rest, offset: 0, length: 2);
                $rest = substr(string: $rest, offset: 2);
                if (strlen($rest) >= 2) {
                    $seconds = (int) substr(string: $rest, offset: 0, length: 2);
                    $rest = substr(string: $rest, offset: 2);
                    if (str_starts_with($rest, '.') || str_starts_with($rest, ',')) {
                        $fracNs = self::fractionNanoseconds($rest);
                    }
                }
            }
        }

        /** @var int<0, max> $absSec — every field is cast from an unsigned digit run */
        $absSec = ($hours * 3600) + ($minutes * 60) + $seconds;

        return [$sign, $absSec, $fracNs];
    }

    /**
     * Converts a validated ±HH:MM or ±HH:MM:SS property-bag offset field into signed seconds.
     *
     * Unlike {@see offsetParts()}, this reads the fixed shape that
     * `ZonedDateTime`'s `offset` FIELD is validated against before it gets here — no
     * compact form, no Z, no fraction — so it can return a single signed number.
     */
    public static function offsetFieldSeconds(string $offset): int
    {
        $sign = $offset[0] === '+' ? 1 : -1;
        $parts = explode(separator: ':', string: substr(string: $offset, offset: 1));
        return (
            $sign
            * (((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (array_key_exists(2, $parts) ? (int) $parts[2] : 0))
        );
    }
}
