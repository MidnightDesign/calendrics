<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;

/**
 * Parses the complete ISO string forms accepted by calendar and time-zone conversion.
 *
 * Only grammar and ISO field validity matter: dates need not fit Temporal's
 * representable range. Calendar and time-zone names are returned without lookup.
 *
 * @internal
 */
final class IsoString
{
    private const string YEAR = '(?:[+-][0-9]{6}|[0-9]{4})';
    private const string MONTH = '(?:0[1-9]|1[0-2])';
    private const string DAY = '(?:0[1-9]|[12][0-9]|3[01])';
    private const string HOUR = '(?:[01][0-9]|2[0-3])';
    private const string MINUTE = '[0-5][0-9]';
    private const string SECOND = '(?:[0-5][0-9]|60)';
    private const string FRACTION = '(?:[.,][0-9]{1,9})?';

    /** @return array{calendar: ?string, timeZone: ?string, offset: ?string} */
    public static function parse(string $input): array
    {
        // Split the body from annotations, then validate their complete grammar.
        $parts = [];
        if (preg_match('/\A([^\[\]]+)((?:\[[^\[\]]+\])*)\z/', $input, $parts) !== 1) {
            throw new RangeError(sprintf('Invalid ISO string "%s".', $input));
        }
        $body = $parts[1];
        $annotations = $parts[2];
        $offsetMinute = sprintf('[+-]%s(?::?%s)?', self::HOUR, self::MINUTE);
        $zoneComponent = '[A-Za-z._][A-Za-z._0-9+-]*';
        $zone = sprintf('(?:%s|%s(?:/%s)*)', $offsetMinute, $zoneComponent, $zoneComponent);
        $annotationPattern = sprintf(
            '~\A(?:\[!?(%s)\])?(?:\[!?[a-z_][a-z0-9_-]*=[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\])*\z~',
            $zone,
        );
        $annotationParts = [];
        if (preg_match($annotationPattern, $annotations, $annotationParts) !== 1) {
            throw new RangeError(sprintf('Invalid annotations in ISO string "%s".', $input));
        }
        CalendarMath::validateAnnotations($annotations, $input, checkCalendar: false);
        $calendarParts = [];
        $calendar = preg_match('/\[!?u-ca=([^\]]+)\]/', $annotations, $calendarParts) === 1 ? $calendarParts[1] : null;
        $timeZone = ($annotationParts[1] ?? '') !== '' ? $annotationParts[1] : null;
        $result = ['calendar' => $calendar, 'timeZone' => $timeZone, 'offset' => null];
        $time = sprintf(
            '%s(?::%s(?::%s%s)?|%s(?:%s%s)?)?',
            self::HOUR,
            self::MINUTE,
            self::SECOND,
            self::FRACTION,
            self::MINUTE,
            self::SECOND,
            self::FRACTION,
        );
        $offset = sprintf(
            '[+-]%s(?::%s(?::%s%s)?|%s(?:%s%s)?)?',
            self::HOUR,
            self::MINUTE,
            self::MINUTE,
            self::FRACTION,
            self::MINUTE,
            self::MINUTE,
            self::FRACTION,
        );
        $date = [];
        if (preg_match(sprintf('/\A(%s)(-[0-9]{2}-[0-9]{2}|[0-9]{4})(.*)\z/', self::YEAR), $body, $date) === 1) {
            $rest = str_replace('-', replace: '', subject: $date[2]);
            self::validateDate(
                $date[1],
                (int) substr($rest, offset: 0, length: 2),
                (int) substr($rest, offset: 2, length: 2),
                $input,
            );
            if ($date[3] !== '') {
                $timeParts = [];
                if (preg_match(sprintf('/\A[Tt ]%s([Zz]|%s)?\z/', $time, $offset), $date[3], $timeParts) !== 1) {
                    throw new RangeError(sprintf('Invalid time in ISO string "%s".', $input));
                }
                $result['offset'] = $timeParts[1] ?? null;
            }
            return $result;
        }
        // Try the short date forms before time: unprefixed times that also match
        // a year-month or month-day are reserved for the date grammar.
        if (preg_match(sprintf('/\A(%s)-?(%s)\z/', self::YEAR, self::MONTH), $body, $date) === 1) {
            self::validateDate($date[1], (int) $date[2], 1, $input);
            self::requireIsoShortDate($calendar, $input);
            return $result;
        }
        if (
            preg_match(sprintf('/\A(?:--)?(%s)-?(%s)\z/', self::MONTH, self::DAY), $body, $date) === 1
            && (int) $date[2] <= CalendarMath::calcDaysInMonth(1972, (int) $date[1])
        ) {
            self::validateDate('1972', (int) $date[1], (int) $date[2], $input);
            self::requireIsoShortDate($calendar, $input);
            return $result;
        }
        $timeParts = [];
        if (preg_match(sprintf('/\A[Tt]?%s(%s)?\z/', $time, $offset), $body, $timeParts) === 1) {
            $result['offset'] = $timeParts[1] ?? null;
            return $result;
        }
        throw new RangeError(sprintf('Invalid ISO string "%s".', $input));
    }

    private static function validateDate(string $year, int $month, int $day, string $input): void
    {
        if (
            $year === '-000000'
            || $month < 1
            || $month > 12
            || $day < 1
            || $day > CalendarMath::calcDaysInMonth((int) $year, $month)
        ) {
            throw new RangeError(sprintf('Invalid date in ISO string "%s".', $input));
        }
    }

    private static function requireIsoShortDate(?string $calendar, string $input): void
    {
        if ($calendar !== null && strtolower($calendar) !== 'iso8601') {
            throw new RangeError(sprintf('Short date in ISO string "%s" requires the ISO calendar.', $input));
        }
    }
}
