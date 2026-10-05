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
 * than a calendar date; this conversion rejects the UTC designator after lexing.
 *
 * The date itself admits extended (`YYYY-MM-DD`) and basic (`YYYYMMDD`) spellings,
 * each also with a six-digit signed extended year — except `-000000`, which TC39
 * rejects as a negative zero year.
 *
 * @internal
 */
final class DateParse
{
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

        $parsed = IsoLexical::date($s);
        if ($parsed === null || $parsed->hasUtcDesignator()) {
            throw new RangeError('Invalid ISO 8601 date string.');
        }
        $yearRaw = $parsed->year;
        $dateRest = $parsed->dateRest;

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

        self::validateOptionalTime($parsed->hour, $parsed->minute, $parsed->second, $s, 'PlainDate');

        // Validate bracket annotations and extract calendar ID.
        $annotationSection = $parsed->annotations;
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
