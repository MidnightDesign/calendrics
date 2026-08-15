<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;
use Temporal\Spec\PlainDateTime;

/**
 * The ISO 8601 grammar for `PlainDateTime` strings.
 *
 * A PlainDateTime string is a date, optionally followed by a wall-clock time and
 * bracket annotations. The grammar's quirks all stem from what a *plain* datetime must
 * not carry: a UTC designator `Z` is rejected outright (it would name an instant, not
 * a wall clock), while a numeric UTC offset is parsed and then deliberately ignored —
 * TC39 treats it as advisory garnish on a timezone-less value.
 *
 * The time section admits three mutually exclusive spellings — extended `HH:MM[:SS]`,
 * basic `HHMM[SS]`, and bare `HH` — matched as separate alternatives so that mixed
 * separator styles (`HH:MMSS`) fail to parse rather than being read permissively.
 * A date-only string is also accepted; its time defaults to midnight.
 *
 * @internal
 */
final class DateTimeParse
{
    /**
     * Parses an ISO 8601 string into a PlainDateTime.
     *
     * Accepts:
     *   - Full datetime: date T time [offset?] [annotations?]
     *       Time formats: HH:MM[:SS[.frac]] (extended) or HHMM[SS[.frac]] (basic)
     *       Separator style must be consistent within time (no mixing).
     *       UTC offset (±HH:MM etc.) is accepted and ignored.
     *       UTC designator Z is rejected (PlainDateTime has no timezone).
     *   - Date-only: YYYY-MM-DD or ±YYYYYY-MM-DD [annotations?] — time defaults to 00:00:00.
     *   - Bracket annotations: validated per TC39 rules.
     *
     * @throws RangeError for invalid or out-of-range values.
     */
    public static function parse(string $s): PlainDateTime
    {
        if ($s === '') {
            throw new RangeError('PlainDateTime::from() received an empty string.');
        }
        // Reject non-ASCII minus sign (U+2212).
        if (str_contains($s, "\u{2212}")) {
            throw new RangeError("PlainDateTime::from() cannot parse \"{$s}\": non-ASCII minus sign is not allowed.");
        }
        // Reject more than 9 fractional-second digits.
        if (preg_match('/[.,]\d{10,}/', $s) === 1) {
            throw new RangeError(
                "PlainDateTime::from() cannot parse \"{$s}\": fractional seconds may have at most 9 digits.",
            );
        }

        // UTC offset sub-pattern (Z excluded — captured separately).
        $offsetHH = '(?:[01]\d|2[0-3])';
        $offsetMM = '[0-5]\d';
        $offsetSS = '[0-5]\d';
        $offsetNonZ = sprintf(
            '[+-]%s(?::%s(?::%s(?:[.,]\d+)?)?|%s(?:%s(?:[.,]\d+)?)?)?',
            $offsetHH,
            $offsetMM,
            $offsetSS,
            $offsetMM,
            $offsetSS,
        );

        // Full datetime pattern (T/t/space separator required).
        // Time section: three mutually exclusive branches to enforce separator consistency:
        //   extended = HH:MM[:SS[.frac]]   (groups 3–6)
        //   basic    = HHMM[SS[.frac]]     (groups 7–10)
        //   hour-only = HH                 (group 13)
        // Group 11 captures a Z designator (which is then rejected).
        // Group 12 captures bracket annotations.
        // groups: 1=year, 2=dateRest, 3-6=ext time, 7-10=basic time, 13=hour-only, 11=Z, 12=annotations
        $dtPattern = sprintf(
            '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2}|\d{4})[Tt ](?:(\d{2}):(\d{2})(?::(\d{2})([.,]\d+)?)?|(\d{2})(\d{2})(?:(\d{2})([.,]\d+)?)?|(\d{2}))(Z)?(?:%s)?((?:\[[^\]]*\])*)$/i',
            $offsetNonZ,
        );

        // Date-only pattern: YYYY-MM-DD or ±YYYYYY-MM-DD or YYYYMMDD, plus optional annotations.
        // Groups: 1=year, 2=dateRest, 3=annotations.
        $dateOnlyPattern = '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2}|\d{4})((?:\[[^\]]*\])*)$/i';

        /** @var list<string> $m */
        $m = [];
        $hourNum = 0;
        $minNum = 0;
        $secNum = 0;
        $fracRaw = '';

        if (preg_match($dtPattern, $s, $m) === 1) {
            // UTC designator Z is not allowed for PlainDateTime.
            if ($m[12] !== '') {
                throw new RangeError("PlainDateTime::from() cannot parse \"{$s}\": UTC designator (Z) is not allowed.");
            }
            $yearRaw = $m[1];
            $dateRest = $m[2];
            $annotations = $m[13];
            // Determine which time branch matched (extended uses group 3, basic uses group 7, hour-only uses group 11).
            if ($m[3] !== '') {
                // Extended format: HH:MM[:SS[.frac]]
                $hourNum = (int) $m[3];
                $minNum = (int) $m[4];
                $secNum = $m[5] !== '' ? (int) $m[5] : 0;
                $fracRaw = $m[6];
            } elseif ($m[7] !== '') {
                // Basic format: HHMM[SS[.frac]]
                $hourNum = (int) $m[7];
                $minNum = (int) $m[8];
                $secNum = $m[9] !== '' ? (int) $m[9] : 0;
                $fracRaw = $m[10];
            } else {
                // Hour-only format: HH
                $hourNum = (int) $m[11];
                $minNum = 0;
                $secNum = 0;
                $fracRaw = '';
            }
            // Leap second 60 → 59.
            if ($secNum === 60) {
                $secNum = 59;
            }
            // Validate time ranges.
            if ($hourNum > 23) {
                throw new RangeError("PlainDateTime::from() cannot parse \"{$s}\": hour {$hourNum} out of range.");
            }
            if ($minNum > 59) {
                throw new RangeError("PlainDateTime::from() cannot parse \"{$s}\": minute {$minNum} out of range.");
            }
            if ($secNum > 59) {
                throw new RangeError("PlainDateTime::from() cannot parse \"{$s}\": second {$secNum} out of range.");
            }
        } elseif (preg_match($dateOnlyPattern, $s, $m) === 1) {
            // Date-only string: time defaults to midnight (all zeros).
            $yearRaw = $m[1];
            $dateRest = $m[2];
            $annotations = $m[3];
        } else {
            throw new RangeError("PlainDateTime::from() cannot parse \"{$s}\": invalid ISO 8601 datetime string.");
        }

        // Reject minus-zero extended year (-000000).
        if (preg_match('/^-0{6}$/', $yearRaw) === 1) {
            throw new RangeError(
                "PlainDateTime::from() cannot parse \"{$s}\": cannot use negative zero as extended year.",
            );
        }

        // Parse date components.
        if (!str_starts_with($dateRest, '-')) {
            $month = (int) substr(string: $dateRest, offset: 0, length: 2);
            $day = (int) substr(string: $dateRest, offset: 2, length: 2);
        } else {
            $month = (int) substr(string: $dateRest, offset: 1, length: 2);
            $day = (int) substr(string: $dateRest, offset: 4, length: 2);
        }
        $year = (int) $yearRaw;

        // Validate bracket annotations and extract calendar ID.
        $calendarId = CalendarMath::validateAnnotations($annotations, $s);

        // Decompose sub-second nanoseconds.
        $subNs = $fracRaw !== '' ? IsoFraction::toNanoseconds($fracRaw) : 0;
        $ms = intdiv(num1: $subNs, num2: EpochLimits::NS_PER_MILLISECOND);
        $us = intdiv(num1: $subNs % EpochLimits::NS_PER_MILLISECOND, num2: EpochLimits::NS_PER_MICROSECOND);
        $ns = $subNs % EpochLimits::NS_PER_MICROSECOND;

        return new PlainDateTime(
            $year,
            $month,
            $day,
            $hourNum,
            $minNum,
            $secNum,
            $ms,
            $us,
            $ns,
            $calendarId ?? 'iso8601',
        );
    }
}
