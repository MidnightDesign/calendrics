<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\ZonedDateTime;

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

        $parsed = IsoLexical::date($text);
        if ($parsed === null || $parsed->annotations === '') {
            throw new RangeError('Invalid ISO 8601 string.');
        }
        $yearRaw = $parsed->year;
        $dateRest = $parsed->dateRest;
        $hourStr = $parsed->hour;
        $minStr = $parsed->minute;
        $secStr = $parsed->second;
        $fractionRaw = $parsed->fraction;
        $offsetRaw = $parsed->offset;
        $annotationSection = $parsed->annotations;
        $isDateOnly = $parsed->hour === '';

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
        $dayNum = (int) substr(string: $dateRest, offset: 4, length: 2);
        $hourNum = (int) $hourStr;
        $minNum = (int) $minStr;
        $secNum = $secStr !== '' ? (int) $secStr : 0;

        if ($monthNum < 1 || $monthNum > 12) {
            throw new RangeError("Invalid ZonedDateTime string \"{$text}\": month out of range.");
        }

        $daysInMonth = CalendarMath::calcDaysInMonth($yearNum, $monthNum);
        if ($dayNum < 1 || $dayNum > $daysInMonth) {
            throw new RangeError("Invalid ZonedDateTime string \"{$text}\": day out of range.");
        }
        DateParse::validateOptionalTime($hourStr, $minStr, $secStr, $text, 'ZonedDateTime');
        // Leap second: 60 maps to second 59 while retaining the fractional part.
        $normalSec = $secNum === 60 ? 59 : $secNum;

        [$tzId, $calendarId] = self::extractAnnotations($annotationSection, $text);

        $hasInlineOffset = $offsetRaw !== '';
        $inlineOffsetSec = 0;
        $inlineOffsetSubNs = 0;
        // ±HH:MM:SS and ±HHMMSS state seconds; ±HH:MM cannot, which changes how strictly
        // the offset is matched against the zone below.
        $inlineOffsetHasSeconds = false;
        if ($hasInlineOffset) {
            [$inlineSign, $inlineAbsSec, $inlineFracNs] = IsoOffset::parts($offsetRaw);
            $inlineOffsetSec = $inlineSign * $inlineAbsSec;
            $inlineOffsetSubNs = $inlineSign * $inlineFracNs;
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
                $inlineOffsetSubNs,
                $inlineOffsetHasSeconds,
                ZoneOffsets::canonicalize($tzId),
                $offsetOption,
                $disambiguation,
            );
            if ($offsetOption === 'use') {
                $subNs -= $inlineOffsetSubNs;
                if ($subNs < 0) {
                    --$epochSec;
                    $subNs += 1_000_000_000;
                } elseif ($subNs >= 1_000_000_000) {
                    ++$epochSec;
                    $subNs -= 1_000_000_000;
                }
            }
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

        return ZonedDateTime::fromEpochParts($epochSec, $subNs, $tzId, $calendarId ?? 'iso8601');
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
        int $inlineOffsetSubNs,
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
            if ($inlineOffsetSubNs === 0 && ZoneOffsets::offsetAt($epochSec, $resolvedTzId) === $inlineOffsetSec) {
                return $epochSec;
            }
            $tzEpoch = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
            if (
                $offsetOption === 'prefer'
                || $inlineOffsetSubNs === 0 && ZoneOffsets::offsetAt($tzEpoch, $resolvedTzId) === $inlineOffsetSec
            ) {
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
}
