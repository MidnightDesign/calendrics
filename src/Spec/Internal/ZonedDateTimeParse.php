<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;
use Temporal\Spec\Internal\Calendar\CalendarFactory;
use Temporal\Spec\ZonedDateTime;

/**
 * Reads the ISO 8601 form of a ZonedDateTime — the only Temporal string that carries all
 * three of an instant, a wall-clock reading, and a zone, e.g.
 * `2020-01-01T12:00:00+05:30[Asia/Kolkata][u-ca=hebrew]`.
 *
 * Two things make this harder than the sibling parsers and are why it lives on its own:
 *
 *   - The grammar refuses mixed extended/compact spellings (`2025-0101`, `12:3045`), which
 *     a single regex cannot express, so the date and time halves are matched by four
 *     alternates that each pin one consistent combination.
 *   - The string can state the UTC offset TWICE — inline and, implicitly, through the
 *     bracketed zone — and when the two disagree the `offset` option decides which wins.
 *     That reconciliation is most of the work below: 'use' trusts the inline offset,
 *     'ignore' drops it, 'reject' demands agreement, and 'prefer' keeps it only when it
 *     picks out one side of a DST fold the zone alone leaves ambiguous.
 *
 * This class lives in `Temporal\Spec\Internal\` and is therefore not part of the public
 * BC contract. Signatures, behavior, and existence may change between any two releases.
 * External code must not depend on it.
 */
final class ZonedDateTimeParse
{
    /**
     * Parses a ZonedDateTime ISO string into a value.
     *
     * @param mixed $options Options from from() (may contain 'offset' key).
     * @throws RangeError if the string is invalid.
     */
    public static function parse(string $text, mixed $options = null): ZonedDateTime
    {
        // Reject more than 9 fractional-second digits.
        if (preg_match('/[.,]\d{10,}/', $text) === 1) {
            throw new RangeError(
                "Invalid ZonedDateTime string \"{$text}\": fractional seconds may have at most 9 digits.",
            );
        }

        /*
         * Pattern groups:
         *   1 — year (±YYYYYY or YYYY)
         *   2 — date rest (-MM-DD or MMDD); must not mix extended and compact formats
         *   3 — hour
         *   4 — minute (only present if consistent format: extended has :, compact has no :)
         *   5 — second (optional)
         *   6 — time fraction (optional)
         *   7 — inline offset (optional: Z, ±HH:MM, ±HHMM, etc.)
         *   8 — bracket annotation section (required: one or more [...])
         *
         * To reject mixed date formats (e.g. 202501-01 or 2025-0101) and mixed time
         * formats (e.g. HH:MMSS or HHMMSS:), we use strict alternation:
         *   - Extended date: -MM-DD  (year then -MM-DD)
         *   - Compact date: MMDD     (year then 4 digits)
         *   - Extended time: HH:MM[:SS]
         *   - Compact time: HHMM[SS]  or just HH
         */
        // Extended date + extended time
        $patternExtDateExtTime = '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2})[T ](\d{2})(?::(\d{2})(?::(\d{2}))?)?([.,]\d+)?(Z|[+-]\d{2}(?::\d{2}(?::\d{2}(?:[.,]\d+)?)?|\d{2}(?:\d{2}(?:[.,]\d+)?)?)?)?((?:\[[^\]]*\])+)$/i';
        // Extended date + compact time
        $patternExtDateCptTime = '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2})[T ](\d{2})(\d{2})(\d{2})?([.,]\d+)?(Z|[+-]\d{2}(?::\d{2}(?::\d{2}(?:[.,]\d+)?)?|\d{2}(?:\d{2}(?:[.,]\d+)?)?)?)?((?:\[[^\]]*\])+)$/i';
        // Compact date + extended time
        $patternCptDateExtTime = '/^([+-]\d{6}|\d{4})(\d{4})[T ](\d{2})(?::(\d{2})(?::(\d{2}))?)?([.,]\d+)?(Z|[+-]\d{2}(?::\d{2}(?::\d{2}(?:[.,]\d+)?)?|\d{2}(?:\d{2}(?:[.,]\d+)?)?)?)?((?:\[[^\]]*\])+)$/i';
        // Compact date + compact time
        $patternCptDateCptTime = '/^([+-]\d{6}|\d{4})(\d{4})[T ](\d{2})(\d{2})(\d{2})?([.,]\d+)?(Z|[+-]\d{2}(?::\d{2}(?::\d{2}(?:[.,]\d+)?)?|\d{2}(?:\d{2}(?:[.,]\d+)?)?)?)?((?:\[[^\]]*\])+)$/i';

        // Date-only pattern: YYYY-MM-DD[tzAnnotation] (no time part; defaults to midnight).
        $dateOnlyPattern = '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2}|\d{4})((?:\[[^\]]*\])+)$/i';

        /** @var list<string> $m */
        $m = [];
        $matched = false;
        $isDateOnly = false;
        foreach ([
            $patternExtDateExtTime,
            $patternExtDateCptTime,
            $patternCptDateExtTime,
            $patternCptDateCptTime,
        ] as $pat) {
            /** @var list<string> $tmp */
            $tmp = [];
            if (preg_match($pat, $text, $tmp) === 1) {
                $m = $tmp;
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            /** @var list<string> $dm */
            $dm = [];
            if (preg_match($dateOnlyPattern, $text, $dm) !== 1) {
                throw new RangeError(
                    "Invalid ZonedDateTime string \"{$text}\": expected ISO 8601 with bracket timezone annotation.",
                );
            }
            // Normalize to the same $m layout with empty time fields (defaults to midnight).
            $m = [$dm[0], $dm[1], $dm[2], '', '', '', '', '', $dm[3]];
            $isDateOnly = true;
        }

        [, $yearRaw, $dateRest, $hourStr, $minStr, $secStr, $fractionRaw, $offsetRaw, $annotationSection] = $m;

        // Normalize compact date rest.
        if (!str_starts_with($dateRest, '-')) {
            $dateRest = sprintf(
                '-%s-%s',
                substr(string: $dateRest, offset: 0, length: 2),
                substr(string: $dateRest, offset: 2, length: 2),
            );
        }

        $yearNum = (int) $yearRaw;
        // Reject minus-zero year.
        if ($yearNum === 0 && str_starts_with($yearRaw, '-')) {
            throw new RangeError(
                "Invalid ZonedDateTime string \"{$text}\": year -000000 (negative zero) is not valid.",
            );
        }

        $monthNum = (int) substr(string: $dateRest, offset: 1, length: 2);
        $hourNum = (int) $hourStr;
        $minNum = (int) $minStr;
        $secNum = $secStr !== '' ? (int) $secStr : 0;

        if ($monthNum < 1 || $monthNum > 12) {
            throw new RangeError("Invalid ZonedDateTime string \"{$text}\": month out of range.");
        }

        // Leap second: map :60 → last nanosecond of :59.
        $sec60 = $secNum === 60;
        $normalSec = $sec60 ? 59 : $secNum;

        // Extract the timezone and calendar from bracket annotations.
        [$tzId, $calendarId] = self::extractTimeZoneAndCalendar($annotationSection, $text);

        // Parse inline offset if present.
        $hasInlineOffset = $offsetRaw !== '';
        $inlineOffsetSec = 0;
        // Whether the inline offset string included a seconds component (e.g. +05:30:00 vs +05:30).
        $inlineOffsetHasSeconds = false;
        if ($hasInlineOffset) {
            [$inlineSign, $inlineAbsSec] = IsoToken::offsetParts($offsetRaw);
            $inlineOffsetSec = $inlineSign * $inlineAbsSec;
            // Detect seconds: extended ±HH:MM:SS or compact ±HHMMSS (7+ chars after sign).
            $inlineOffsetHasSeconds =
                preg_match('/^[+\-]\d{2}:\d{2}:\d{2}/', $offsetRaw) === 1
                || preg_match('/^[+\-]\d{6}/', $offsetRaw) === 1;
        }

        // Build wall-clock DateTimeImmutable (treat as UTC to get Unix seconds).
        $wallDt = new \DateTimeImmutable(sprintf(
            '%s%sT%02d:%02d:%02d+00:00',
            $yearRaw,
            $dateRest,
            $hourNum,
            $minNum,
            $normalSec,
        ));

        $wallSec = $wallDt->getTimestamp();

        // GetOptionsObject runs only now: ToTemporalZonedDateTime parses the string
        // first, so a malformed one is a RangeError before an options accessor is ever
        // touched. The keywords are validated here and resolved leniently below, where
        // an out-of-range value has already been rejected.
        $opts = ZonedDateTime::validateFromOptions($options);
        $offsetOption = array_key_exists('offset', $opts) && is_string($opts['offset']) ? $opts['offset'] : 'reject';
        $disambiguation = array_key_exists('disambiguation', $opts) && is_string($opts['disambiguation'])
            ? $opts['disambiguation']
            : 'compatible';

        // When offset='use' or 'ignore', the epoch is derived directly from the stated offset
        // (or the timezone offset), so the wall-clock time need not be within the spec range.
        // For 'prefer' and 'reject', we need the wall-clock-derived UTC to be valid, so check.
        if ($offsetOption !== 'use' && $offsetOption !== 'ignore') {
            // ISODateTimeWithinLimits check: the wall-clock (local) date must itself be within the
            // representable ZonedDateTime date range [-271821-04-20, +275760-09-13].
            // - Min: any wallSec < -8640000000000 is on a date before April 20, -271821.
            // - Max: wallSec >= 8640000086400 is on a date after September 13, +275760.
            //   (8640000086400 = max boundary epoch + 86400 s = midnight of +275760-09-14.)
            if ($wallSec < -EpochLimits::MAX_EPOCH_SECONDS || $wallSec >= (EpochLimits::MAX_EPOCH_SECONDS + 86_400)) {
                throw new RangeError(
                    "ZonedDateTime string \"{$text}\": local date-time is outside the representable range.",
                );
            }
        }
        $subNs = $fractionRaw !== '' ? IsoToken::fractionNanoseconds($fractionRaw) : 0;

        // Determine epoch seconds.
        if ($hasInlineOffset && (strtoupper($offsetRaw) === 'Z' || $offsetRaw === 'Z' || $offsetRaw === 'z')) {
            // Z → UTC, epochSec = wallSec.
            $epochSec = $wallSec;
        } elseif ($hasInlineOffset) {
            // Inline offset present: behavior depends on offset option.
            $normalizedTzId = TimeZoneIdentity::normalize($tzId);
            $resolvedTzId = TimeZoneIdentity::canonicalId($normalizedTzId);

            if ($offsetOption === 'use') {
                // Use the stated inline offset directly.
                $epochSec = $wallSec - $inlineOffsetSec;
            } elseif ($offsetOption === 'ignore') {
                // Ignore the inline offset; use the wall clock with the bracket timezone.
                $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
            } elseif ($offsetOption === 'prefer') {
                if ($inlineOffsetHasSeconds) {
                    // HH:MM:SS: use inline offset if it matches exactly; otherwise timezone.
                    $epochSec = $wallSec - $inlineOffsetSec;
                    $actualOffsetSec = TimeZoneHelper::offsetSecondsAt($resolvedTzId, $epochSec);
                    if ($actualOffsetSec !== $inlineOffsetSec) {
                        $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
                    }
                } else {
                    // HH:MM: use timezone resolution first. If the resolved offset matches
                    // exactly, the inline offset successfully disambiguated. Otherwise, accept
                    // if it rounds to the resolved offset (sub-minute tolerance).
                    $tzEpoch = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
                    $tzOffset = TimeZoneHelper::offsetSecondsAt($resolvedTzId, $tzEpoch);
                    if ($tzOffset === $inlineOffsetSec) {
                        // The timezone's default resolution matches the inline offset.
                        $epochSec = $tzEpoch;
                    } elseif (($tzOffset % 60) !== 0) {
                        // The resolved offset is sub-minute; HH:MM can't disambiguate.
                        // Accept if the inline offset rounds to the resolved offset.
                        $epochSec = $tzEpoch;
                    } else {
                        // Try using inline offset for disambiguation (DST fold with
                        // whole-minute offsets on both sides).
                        $epochSec = $wallSec - $inlineOffsetSec;
                        $actualOffsetSec = TimeZoneHelper::offsetSecondsAt($resolvedTzId, $epochSec);
                        if ($actualOffsetSec === $inlineOffsetSec) {
                            // Exact match: inline offset disambiguates.
                        } else {
                            // No match: use timezone resolution.
                            $epochSec = $tzEpoch;
                        }
                    }
                }
            } else {
                // offset: 'reject' (default): throw if inline offset doesn't match timezone.
                if ($inlineOffsetHasSeconds) {
                    // HH:MM:SS: must match exactly.
                    $epochSec = $wallSec - $inlineOffsetSec;
                    $actualOffsetSec = TimeZoneHelper::offsetSecondsAt($resolvedTzId, $epochSec);
                    if ($actualOffsetSec !== $inlineOffsetSec) {
                        // Also check against timezone resolution for the error case.
                        $tzEpoch = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
                        $tzOffset = TimeZoneHelper::offsetSecondsAt($resolvedTzId, $tzEpoch);
                        if ($tzOffset !== $inlineOffsetSec) {
                            throw new RangeError(
                                "Invalid ZonedDateTime string \"{$text}\": inline offset does not match timezone offset.",
                            );
                        }
                        $epochSec = $tzEpoch;
                    }
                } else {
                    // HH:MM: use timezone resolution, validate with rounding.
                    $tzEpoch = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
                    $tzOffset = TimeZoneHelper::offsetSecondsAt($resolvedTzId, $tzEpoch);
                    if ($tzOffset === $inlineOffsetSec) {
                        $epochSec = $tzEpoch;
                    } elseif (($tzOffset % 60) !== 0) {
                        // Sub-minute resolved offset: HH:MM can't disambiguate.
                        if (((int) round((float) $tzOffset / 60.0) * 60) !== $inlineOffsetSec) {
                            throw new RangeError(
                                "Invalid ZonedDateTime string \"{$text}\": inline offset does not match timezone offset.",
                            );
                        }
                        $epochSec = $tzEpoch;
                    } else {
                        // Try using inline offset for disambiguation (whole-minute DST fold).
                        $epochSec = $wallSec - $inlineOffsetSec;
                        $actualOffsetSec = TimeZoneHelper::offsetSecondsAt($resolvedTzId, $epochSec);
                        if ($actualOffsetSec !== $inlineOffsetSec) {
                            throw new RangeError(
                                "Invalid ZonedDateTime string \"{$text}\": inline offset does not match timezone offset.",
                            );
                        }
                    }
                }
            }
            $tzId = $normalizedTzId;
        } else {
            // No inline offset: convert wall clock to UTC via the timezone.
            $normalizedTzId = TimeZoneIdentity::normalize($tzId);
            $resolvedTzId = TimeZoneIdentity::canonicalId($normalizedTzId);
            if ($isDateOnly) {
                // Date-only string: use startOfDay semantics (TC39 spec).
                $epochSec = TimeZoneHelper::wallSecToEpochSecStartOfDay($wallSec, $resolvedTzId);
            } else {
                $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
            }
            $tzId = $normalizedTzId;
        }

        // Validate spec range.
        $maxSec = EpochLimits::MAX_EPOCH_SECONDS;
        if ($epochSec < -$maxSec || $epochSec > $maxSec || $epochSec === $maxSec && $subNs > 0) {
            throw new RangeError("ZonedDateTime string \"{$text}\" is outside the representable nanosecond range.");
        }

        return ZonedDateTime::fromEpochParts($epochSec, $subNs, $tzId, $calendarId ?? 'iso8601');
    }

    /**
     * Extracts the timezone identifier and optional calendar ID from the bracket annotation section.
     *
     * The FIRST bracket without '=' is the timezone annotation. Key-value brackets
     * (with '=') are metadata (e.g. [u-ca=hebrew]).
     *
     * @return array{0: string, 1: ?string} [timezoneId, calendarId]
     * @throws RangeError if no timezone annotation is found, or calendar is unknown.
     */
    private static function extractTimeZoneAndCalendar(string $section, string $original): array
    {
        $matches = null;
        preg_match_all('/\[(!?)([^\]]*)\]/', $section, $matches, PREG_SET_ORDER);

        $tzId = null;
        $tzCount = 0;
        $calCount = 0;
        $calHasCritical = false;
        $calendarId = null;

        foreach ($matches as $match) {
            [, $bang, $content] = $match;
            $critical = $bang === '!';

            if (str_contains($content, '=')) {
                [$key] = explode(separator: '=', string: $content, limit: 2);
                if ($key !== strtolower($key)) {
                    throw new RangeError(
                        "Invalid annotation key \"{$key}\" in \"{$original}\": annotation keys must be lowercase.",
                    );
                }
                if ($key === 'u-ca') {
                    $isFirst = $calCount === 0;
                    if ($isFirst) {
                        $calValue = substr(string: $content, offset: strlen($key) + 1);
                        if (!CalendarFactory::isKnownCalendar($calValue)) {
                            throw new RangeError("Unknown calendar \"{$calValue}\" in \"{$original}\".");
                        }
                        $calendarId = CalendarFactory::canonicalize($calValue);
                    }
                    ++$calCount;
                    if ($critical) {
                        $calHasCritical = true;
                    }
                    if (!$isFirst && $calHasCritical) {
                        throw new RangeError("Multiple calendar annotations with critical flag in \"{$original}\".");
                    }
                } else {
                    if ($critical) {
                        throw new RangeError("Critical unknown annotation \"[!{$content}]\" in \"{$original}\".");
                    }
                }
            } else {
                ++$tzCount;
                if ($tzCount > 1) {
                    throw new RangeError("Multiple time-zone annotations in \"{$original}\".");
                }
                $tzId = $content;

                // Validate offset-style TZ annotation: ±HH:MM only (no seconds).
                if (preg_match('/^[+-]/', $content) === 1) {
                    if (
                        preg_match('/^[+-]\d{2}:\d{2}:\d{2}/', $content) === 1
                        || preg_match('/^[+-]\d{2}:\d{2}[.,]/', $content) === 1
                    ) {
                        throw new RangeError("Sub-minute UTC offset in time-zone annotation in \"{$original}\".");
                    }
                }
            }
        }

        if ($tzId === null) {
            throw new RangeError("Invalid ZonedDateTime string \"{$original}\": no timezone annotation found.");
        }

        return [$tzId, $calendarId];
    }

    // -------------------------------------------------------------------------
    // Private helpers for add/subtract/since/until/round/with
    // -------------------------------------------------------------------------
}
