<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;
use Temporal\Spec\Internal\Calendar\CalendarFactory;
use Temporal\Spec\ZonedDateTime;

/**
 * The ISO 8601 grammar for `ZonedDateTime` strings.
 *
 * A ZDT string is the only Temporal string whose meaning is not determined by its own
 * text: `2024-11-03T01:30:00-04:00[America/New_York]` states both an offset and a zone,
 * and the hour it names exists twice that day. Resolving it therefore takes three inputs
 * — the lexical parse, the `offset` option, and the `disambiguation` option — and the
 * interesting part of this class is the cross-check between the stated offset and the
 * one the zone actually observed, which is where the four `offset` keywords differ:
 *
 *   - `use`    — trust the stated offset; the zone only names the result's zone.
 *   - `ignore` — discard it; resolve the wall clock through the zone.
 *   - `prefer` — use it when it is one of the zone's valid offsets, else fall back.
 *   - `reject` — as `prefer`, but throw instead of falling back.
 *
 * `±HH:MM` and `±HH:MM:SS` are not interchangeable here: a whole-minute offset cannot
 * express a sub-minute historical offset (LMT zones), so a minute-precision offset is
 * accepted when it rounds to the zone's actual one, while a second-precision offset must
 * match exactly.
 *
 * @internal
 */
final class ZonedParse
{
    // Group layout shared by all four datetime patterns:
    //   1 year (±YYYYYY or YYYY)  2 date rest (-MM-DD or MMDD)  3 hour  4 minute
    //   5 second  6 fraction  7 inline offset  8 bracket annotations (required)
    //
    // Mixed extended/compact spellings (202501-01, HH:MMSS) are rejected by matching each
    // consistent combination as its own alternative rather than by one permissive pattern.
    private const string PATTERN_EXT_DATE_EXT_TIME = '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2})[T ](\d{2})(?::(\d{2})(?::(\d{2}))?)?([.,]\d+)?(Z|[+-]\d{2}(?::\d{2}(?::\d{2}(?:[.,]\d+)?)?|\d{2}(?:\d{2}(?:[.,]\d+)?)?)?)?((?:\[[^\]]*\])+)$/i';
    private const string PATTERN_EXT_DATE_CPT_TIME = '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2})[T ](\d{2})(\d{2})(\d{2})?([.,]\d+)?(Z|[+-]\d{2}(?::\d{2}(?::\d{2}(?:[.,]\d+)?)?|\d{2}(?:\d{2}(?:[.,]\d+)?)?)?)?((?:\[[^\]]*\])+)$/i';
    private const string PATTERN_CPT_DATE_EXT_TIME = '/^([+-]\d{6}|\d{4})(\d{4})[T ](\d{2})(?::(\d{2})(?::(\d{2}))?)?([.,]\d+)?(Z|[+-]\d{2}(?::\d{2}(?::\d{2}(?:[.,]\d+)?)?|\d{2}(?:\d{2}(?:[.,]\d+)?)?)?)?((?:\[[^\]]*\])+)$/i';
    private const string PATTERN_CPT_DATE_CPT_TIME = '/^([+-]\d{6}|\d{4})(\d{4})[T ](\d{2})(\d{2})(\d{2})?([.,]\d+)?(Z|[+-]\d{2}(?::\d{2}(?::\d{2}(?:[.,]\d+)?)?|\d{2}(?:\d{2}(?:[.,]\d+)?)?)?)?((?:\[[^\]]*\])+)$/i';

    /** Date with annotations but no time part; resolves to start-of-day, not to midnight. */
    private const string PATTERN_DATE_ONLY = '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2}|\d{4})((?:\[[^\]]*\])+)$/i';

    /**
     * Parses a ZonedDateTime ISO string, which must carry a bracket time-zone annotation.
     *
     * @param mixed $options Options from `from()`; `offset` and `disambiguation` are read,
     *                       but only once the text has parsed — see the note at that read.
     * @throws RangeError if the string is malformed, names an unknown calendar, or resolves
     *                    outside the representable range.
     */
    public static function parse(string $text, mixed $options = null): ZonedDateTime
    {
        if (preg_match('/[.,]\d{10,}/', $text) === 1) {
            throw new RangeError(
                "Invalid ZonedDateTime string \"{$text}\": fractional seconds may have at most 9 digits.",
            );
        }

        [$m, $isDateOnly] = self::match($text);

        [, $yearRaw, $dateRest, $hourStr, $minStr, $secStr, $fractionRaw, $offsetRaw, $annotationSection] = $m;

        if (!str_starts_with($dateRest, '-')) {
            $dateRest = sprintf(
                '-%s-%s',
                substr(string: $dateRest, offset: 0, length: 2),
                substr(string: $dateRest, offset: 2, length: 2),
            );
        }

        $yearNum = (int) $yearRaw;
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

        // Leap second: :60 names the last nanosecond of :59.
        $normalSec = $secNum === 60 ? 59 : $secNum;

        [$tzId, $calendarId] = self::extractAnnotations($annotationSection, $text);

        $hasInlineOffset = $offsetRaw !== '';
        $inlineOffsetSec = 0;
        // ±HH:MM:SS and ±HHMMSS state seconds; ±HH:MM cannot, which changes how strictly
        // the offset is matched against the zone below.
        $inlineOffsetHasSeconds = false;
        if ($hasInlineOffset) {
            [$inlineSign, $inlineAbsSec] = self::parseOffset($offsetRaw);
            $inlineOffsetSec = $inlineSign * $inlineAbsSec;
            $inlineOffsetHasSeconds =
                preg_match('/^[+\-]\d{2}:\d{2}:\d{2}/', $offsetRaw) === 1
                || preg_match('/^[+\-]\d{6}/', $offsetRaw) === 1;
        }

        // Build wall-clock seconds by reading the local fields as if they were UTC.
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
        // touched. fromOptions() validates the keywords, so the reads below can resolve
        // leniently — an out-of-range value has already been rejected.
        $opts = ZonedFields::fromOptions($options);
        $offsetOption = array_key_exists('offset', $opts) && is_string($opts['offset']) ? $opts['offset'] : 'reject';
        $disambiguation = array_key_exists('disambiguation', $opts) && is_string($opts['disambiguation'])
            ? $opts['disambiguation']
            : 'compatible';

        // With offset='use'/'ignore' the epoch comes from the stated offset or the zone, so
        // the wall clock itself need not be in range. 'prefer'/'reject' derive UTC from the
        // wall clock, so ISODateTimeWithinLimits applies:
        //   below -8640000000000 s is earlier than -271821-04-20;
        //   at or past 8640000086400 s (= max + one day) is later than +275760-09-13.
        if ($offsetOption !== 'use' && $offsetOption !== 'ignore') {
            if ($wallSec < -EpochLimits::MAX_EPOCH_SECONDS || $wallSec >= (EpochLimits::MAX_EPOCH_SECONDS + 86_400)) {
                throw new RangeError(
                    "ZonedDateTime string \"{$text}\": local date-time is outside the representable range.",
                );
            }
        }

        $subNs = $fractionRaw !== '' ? IsoFraction::toNanoseconds($fractionRaw) : 0;

        if ($hasInlineOffset && ($offsetRaw === 'Z' || $offsetRaw === 'z')) {
            $epochSec = $wallSec;
        } elseif ($hasInlineOffset) {
            $tzId = TimeZoneHelper::normalizeTimezoneId($tzId);
            $epochSec = self::resolveWithInlineOffset(
                $text,
                $wallSec,
                $inlineOffsetSec,
                $inlineOffsetHasSeconds,
                ZoneOffsets::canonicalize($tzId),
                $offsetOption,
                $disambiguation,
            );
        } else {
            $tzId = TimeZoneHelper::normalizeTimezoneId($tzId);
            $resolvedTzId = ZoneOffsets::canonicalize($tzId);
            $epochSec = $isDateOnly
                ? TimeZoneHelper::wallSecToEpochSecStartOfDay($wallSec, $resolvedTzId)
                : TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
        }

        $maxSec = EpochLimits::MAX_EPOCH_SECONDS;
        if ($epochSec < -$maxSec || $epochSec > $maxSec || $epochSec === $maxSec && $subNs > 0) {
            throw new RangeError("ZonedDateTime string \"{$text}\" is outside the representable nanosecond range.");
        }

        return ZonedDateTime::createFromEpochParts($epochSec, $subNs, $tzId, $calendarId ?? 'iso8601');
    }

    /**
     * Matches $text against the datetime spellings, then the date-only one.
     *
     * A date-only match is normalized to the same nine-element layout with empty time
     * groups, so the caller has a single shape to destructure.
     *
     * @return array{0: array<array-key, string>, 1: bool} [groups, isDateOnly]
     * @throws RangeError if nothing matches.
     */
    private static function match(string $text): array
    {
        foreach ([
            self::PATTERN_EXT_DATE_EXT_TIME,
            self::PATTERN_EXT_DATE_CPT_TIME,
            self::PATTERN_CPT_DATE_EXT_TIME,
            self::PATTERN_CPT_DATE_CPT_TIME,
        ] as $pattern) {
            /** @var list<string> $m */
            $m = [];
            if (preg_match($pattern, $text, $m) === 1) {
                return [$m, false];
            }
        }

        /** @var list<string> $dm */
        $dm = [];
        if (preg_match(self::PATTERN_DATE_ONLY, $text, $dm) !== 1) {
            throw new RangeError(
                "Invalid ZonedDateTime string \"{$text}\": expected ISO 8601 with bracket timezone annotation.",
            );
        }
        return [[$dm[0], $dm[1], $dm[2], '', '', '', '', '', $dm[3]], true];
    }

    /**
     * Resolves the epoch second for a string that states both an inline offset and a zone.
     *
     * @throws RangeError under `offset: 'reject'` when the stated offset is not one the zone observed.
     */
    private static function resolveWithInlineOffset(
        string $text,
        int $wallSec,
        int $inlineOffsetSec,
        bool $inlineOffsetHasSeconds,
        string $resolvedTzId,
        string $offsetOption,
        string $disambiguation,
    ): int {
        if ($offsetOption === 'use') {
            return $wallSec - $inlineOffsetSec;
        }
        if ($offsetOption === 'ignore') {
            return TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
        }

        if ($inlineOffsetHasSeconds) {
            // Second precision: the stated offset must be exactly what the zone observed.
            $epochSec = $wallSec - $inlineOffsetSec;
            if (ZoneOffsets::offsetAt($epochSec, $resolvedTzId) === $inlineOffsetSec) {
                return $epochSec;
            }
            $tzEpoch = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
            if ($offsetOption === 'prefer' || ZoneOffsets::offsetAt($tzEpoch, $resolvedTzId) === $inlineOffsetSec) {
                return $tzEpoch;
            }
            throw new RangeError(
                "Invalid ZonedDateTime string \"{$text}\": inline offset does not match timezone offset.",
            );
        }

        // Minute precision. Resolve through the zone first: when its offset matches, the
        // inline offset agreed and there is nothing to disambiguate.
        $tzEpoch = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
        $tzOffset = ZoneOffsets::offsetAt($tzEpoch, $resolvedTzId);
        if ($tzOffset === $inlineOffsetSec) {
            return $tzEpoch;
        }
        if (($tzOffset % 60) !== 0) {
            // The zone's offset has seconds (an LMT-era zone), which ±HH:MM cannot name.
            // Accept the offset when it is the zone's offset rounded to the minute.
            if ($offsetOption === 'prefer' || ((int) round((float) $tzOffset / 60.0) * 60) === $inlineOffsetSec) {
                return $tzEpoch;
            }
            throw new RangeError(
                "Invalid ZonedDateTime string \"{$text}\": inline offset does not match timezone offset.",
            );
        }

        // Whole-minute offsets on both sides: a DST fold, where the inline offset picks
        // which of the two repeated instants was meant.
        $epochSec = $wallSec - $inlineOffsetSec;
        if (ZoneOffsets::offsetAt($epochSec, $resolvedTzId) === $inlineOffsetSec) {
            return $epochSec;
        }
        if ($offsetOption === 'prefer') {
            return $tzEpoch;
        }
        throw new RangeError("Invalid ZonedDateTime string \"{$text}\": inline offset does not match timezone offset.");
    }

    /**
     * Reads the bracket annotation section for the time zone and the calendar.
     *
     * The first bracket without an `=` is the time zone; bracket contents containing `=`
     * are key-value metadata, of which only `u-ca` is recognized. A `!` prefix marks an
     * annotation critical, which makes an unrecognized key fatal rather than ignorable.
     *
     * @return array{0: string, 1: ?string} [timeZoneId, calendarId]
     * @throws RangeError if there is no time-zone annotation, more than one, an unknown
     *                    calendar, a non-lowercase key, or a critical unknown annotation.
     */
    private static function extractAnnotations(string $section, string $original): array
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

            if (!str_contains($content, '=')) {
                ++$tzCount;
                if ($tzCount > 1) {
                    throw new RangeError("Multiple time-zone annotations in \"{$original}\".");
                }
                $tzId = $content;

                // An offset-shaped annotation is limited to ±HH:MM — a zone cannot be
                // named by a sub-minute offset.
                if (
                    preg_match('/^[+-]/', $content) === 1
                    && (
                        preg_match('/^[+-]\d{2}:\d{2}:\d{2}/', $content) === 1
                        || preg_match('/^[+-]\d{2}:\d{2}[.,]/', $content) === 1
                    )
                ) {
                    throw new RangeError("Sub-minute UTC offset in time-zone annotation in \"{$original}\".");
                }
                continue;
            }

            [$key] = explode(separator: '=', string: $content, limit: 2);
            if ($key !== strtolower($key)) {
                throw new RangeError(
                    "Invalid annotation key \"{$key}\" in \"{$original}\": annotation keys must be lowercase.",
                );
            }
            if ($key !== 'u-ca') {
                if ($critical) {
                    throw new RangeError("Critical unknown annotation \"[!{$content}]\" in \"{$original}\".");
                }
                continue;
            }

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
        }

        if ($tzId === null) {
            throw new RangeError("Invalid ZonedDateTime string \"{$original}\": no timezone annotation found.");
        }

        return [$tzId, $calendarId];
    }

    /**
     * Parses a UTC-offset lexeme (`Z`, `±HH`, `±HH:MM`, `±HHMM`, `±HH:MM:SS`, `±HHMMSS`,
     * any of the last two with a fraction) into its parts.
     *
     * @return array{-1|1, int<0, 86399>, int<0, 999999999>} [sign, absSeconds, fractionalNanoseconds]
     */
    private static function parseOffset(string $offset): array
    {
        if ($offset === 'Z' || $offset === 'z') {
            return [1, 0, 0];
        }

        $sign = $offset[0] === '+' ? 1 : -1;
        $rest = substr(string: $offset, offset: 1);

        $hours = (int) substr(string: $rest, offset: 0, length: 2);
        $rest = substr(string: $rest, offset: 2);
        $minutes = 0;
        $seconds = 0;
        $fracNs = 0;

        if ($rest !== '') {
            if ($rest[0] === ':') {
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

        /** @var int<0, 86399> $absSec — the grammar caps each component, so the sum stays under a day */
        $absSec = ($hours * 3600) + ($minutes * 60) + $seconds;

        return [$sign, $absSec, $fracNs];
    }
}
