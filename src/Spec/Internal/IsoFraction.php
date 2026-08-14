<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

/**
 * The fractional-second component of an ISO 8601 string.
 *
 * Every ISO grammar in the spec layer — `Instant`, `PlainTime`, `PlainDateTime`,
 * `ZonedDateTime`, and the UTC-offset sub-grammar shared by the last two — captures
 * the fraction as a raw `.ddd…` / `,ddd…` lexeme and then has to turn it into a
 * nanosecond count under the same rule. That rule lives here rather than once per
 * parser.
 *
 * @internal
 */
final class IsoFraction
{
    /**
     * Converts a raw fractional-second lexeme (leading `.` or `,` included) to nanoseconds.
     *
     * The grammar admits an arbitrarily long digit run; the spec keeps the first nine and
     * discards the rest, so this truncates rather than rounds. Runs shorter than nine
     * digits are right-padded, which is what makes `.5` half a second and not five
     * nanoseconds.
     *
     * @return int<0, 999999999>
     */
    public static function toNanoseconds(string $fractionRaw): int
    {
        $digits = substr(string: $fractionRaw, offset: 1);
        /** @var int<0, 999999999> — 9 decimal digits, range 000000000–999999999 */
        return (int) str_pad(substr(string: $digits, offset: 0, length: 9), length: 9, pad_string: '0');
    }
}
