<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

/**
 * The UTC-offset component of an ISO 8601 string.
 *
 * The sibling of {@see IsoFraction}, for the other lexeme two parsers were decoding the
 * same way: `Instant` and `ZonedParse` each carried a copy of this scanner, identical
 * apart from `Instant`'s range check.
 *
 * That check stays at the call site, and deliberately so. Deciding whether an offset is
 * IN RANGE is not the scanner's business — the magnitude it returns is whatever the
 * grammar matched, and only the caller knows which string the lexeme came from and can
 * name it in the error. This is the same division of labor {@see EpochValue} draws for
 * the epoch range check.
 *
 * @internal
 */
final class IsoOffset
{
    /**
     * Decomposes a UTC-offset lexeme into [sign, absolute seconds, fractional nanoseconds].
     *
     * Accepted forms:
     *   Z | z                      → [+1, 0, 0]
     *   ±HH                        → [sign, H×3600, 0]
     *   ±HH:MM | ±HH:MM:SS[.,f]    → colon-separated
     *   ±HHMM   | ±HHMMSS[.,f]     → no separators
     *
     * The magnitude is NOT capped at a day. Each component is a two-digit run, so the
     * grammar admits `+99:99:99` — 100 hours 39 minutes — and a caller that needs the
     * offset to be a real one has to say so itself.
     *
     * @return array{-1|1, int<0, max>, int<0, 999999999>} [sign (+1|-1), absSec, fracNs]
     */
    public static function parts(string $offset): array
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
                        $fracNs = IsoFraction::toNanoseconds($rest);
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
                        $fracNs = IsoFraction::toNanoseconds($rest);
                    }
                }
            }
        }

        /** @var int<0, max> $absSec — every component is cast from an unsigned digit run */
        $absSec = ($hours * 3600) + ($minutes * 60) + $seconds;

        return [$sign, $absSec, $fracNs];
    }
}
