<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\PlainDate;

/**
 * The ISO 8601 grammar for `PlainDate` strings.
 *
 * A PlainDate string is a date, optionally followed by a wall-clock time, a numeric
 * UTC offset, and bracket annotations — all of which are parsed, validated, and then
 * discarded: only the date portion survives into the value. The one thing the trailing
 * matter may never contain is a UTC designator `Z`, which would name an instant rather
 * than a calendar date; the grammar simply has no branch for it, so a `Z` fails the
 * match outright.
 *
 * The date itself admits extended (`YYYY-MM-DD`) and basic (`YYYYMMDD`) spellings,
 * each also with a six-digit signed extended year — except `-000000`, which TC39
 * rejects as a negative zero year.
 *
 * @internal
 */
final class DateParse
{
    /** Numeric UTC offset grammar; offset seconds exclude wall-clock leap second 60. */
    public const string NUMERIC_OFFSET_PATTERN = '[+-](?:[01]\d|2[0-3])(?::[0-5]\d(?::[0-5]\d(?:[.,]\d+)?)?|[0-5]\d(?:[0-5]\d(?:[.,]\d+)?)?)?';

    /**
     * Parses an ISO 8601 date string into a PlainDate.
     *
     * Accepted formats:
     *   YYYY-MM-DD, ±YYYYYY-MM-DD, YYYYMMDD, ±YYYYYYMMDD
     * Optional trailing time, offset, and bracket annotations are accepted;
     * only the date portion is used. Z (UTC designator) is not valid for PlainDate.
     *
     * @throws RangeError for invalid or out-of-range dates.
     */
    public static function parse(string $s): PlainDate
    {
        if ($s === '') {
            throw new RangeError('PlainDate::from() received an empty string.');
        }
        // Reject non-ASCII minus sign (U+2212 = \xe2\x88\x92).
        if (str_contains($s, "\u{2212}")) {
            throw new RangeError("PlainDate::from() cannot parse \"{$s}\": non-ASCII minus sign is not allowed.");
        }
        // Reject more than 9 fractional-second digits anywhere (time part or offset fraction).
        if (preg_match('/[.,]\d{10,}/', $s) === 1) {
            throw new RangeError(
                "PlainDate::from() cannot parse \"{$s}\": fractional seconds may have at most 9 digits.",
            );
        }

        // Full anchored regex for a PlainDate string.
        // Date part: YYYY-MM-DD | ±YYYYYY-MM-DD | YYYYMMDD | ±YYYYYYMMDD
        // Optional time: T + HH[:MM[:SS[frac]]]  (fraction only after SS)
        // Optional non-Z offset (only when time is present): ±HH[:MM[:SS[frac]]]
        // Optional bracket annotations
        // Z (UTC designator) is NEVER valid for PlainDate.
        // date: year + rest, optional T+HH:MM:SS.frac, optional offset, bracket annotations
        $pattern = sprintf(
            '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2}|\d{4})(?:[Tt ](\d{2})(?::?(\d{2})(?::?(\d{2})([.,]\d+)?)?)?(?:%s)?)?((?:\[[^\]]*\])*)$/',
            self::NUMERIC_OFFSET_PATTERN,
        );

        /** @var list<string> $m */
        $m = [];
        if (preg_match($pattern, $s, $m) !== 1) {
            throw new RangeError("PlainDate::from() cannot parse \"{$s}\": invalid ISO 8601 date string.");
        }

        [, $yearRaw, $dateRest] = $m;

        // Reject minus-zero extended year (-000000).
        if (preg_match('/^-0{6}$/', $yearRaw) === 1) {
            throw new RangeError('Cannot use negative zero as extended year.');
        }

        // Compact date rest (MMDD) → extract components.
        if (!str_starts_with($dateRest, '-')) {
            $month = (int) substr(string: $dateRest, offset: 0, length: 2);
            $day = (int) substr(string: $dateRest, offset: 2, length: 2);
        } else {
            $month = (int) substr(string: $dateRest, offset: 1, length: 2);
            $day = (int) substr(string: $dateRest, offset: 4, length: 2);
        }
        $year = (int) $yearRaw;

        self::validateOptionalTime($m[3], $m[4], $m[5], $s, 'PlainDate');

        // Validate bracket annotations and extract calendar ID.
        $annotationSection = $m[7];
        $calendarId = CalendarMath::validateAnnotations($annotationSection, $s);

        return new PlainDate($year, $month, $day, $calendarId ?? 'iso8601');
    }

    public static function validateOptionalTime(
        string $hourText,
        string $minuteText,
        string $secondText,
        string $input,
        string $context,
    ): void {
        if ($hourText !== '') {
            $hour = (int) $hourText;
            if ($hour > 23) {
                throw new RangeError("{$context}::from() cannot parse \"{$input}\": hour {$hour} out of range.");
            }
            if ($minuteText !== '') {
                $minute = (int) $minuteText;
                if ($minute > 59) {
                    throw new RangeError(
                        "{$context}::from() cannot parse \"{$input}\": minute {$minute} out of range.",
                    );
                }
                if ($secondText !== '') {
                    $second = (int) $secondText;
                    if ($second > 60) {
                        throw new RangeError(
                            "{$context}::from() cannot parse \"{$input}\": second {$second} out of range.",
                        );
                    }
                }
            }
        }
    }
}
