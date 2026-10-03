<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal\Calendar;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Internal\CalendarMath;

/**
 * Parses the ISO string forms accepted by ParseTemporalCalendarString.
 *
 * Only grammar and ISO field validity matter: dates need not fit Temporal's
 * representable range, and a time-zone annotation need not name an installed zone.
 *
 * @internal
 */
final class CalendarString
{
    private const string YEAR = '(?:[+-][0-9]{6}|[0-9]{4})';
    private const string MONTH = '(?:0[1-9]|1[0-2])';
    private const string DAY = '(?:0[1-9]|[12][0-9]|3[01])';
    private const string HOUR = '(?:[01][0-9]|2[0-3])';
    private const string MINUTE = '[0-5][0-9]';
    private const string SECOND = '(?:[0-5][0-9]|60)';
    private const string FRACTION = '(?:[.,][0-9]{1,9})?';

    public static function parse(string $input): string
    {
        // Split the body from annotations, then validate their complete grammar.
        $parts = [];
        if (preg_match('/\A([^\[\]]+)((?:\[[^\[\]]+\])*)\z/', $input, $parts) !== 1) {
            throw new RangeError(sprintf('Invalid calendar string "%s".', $input));
        }
        $body = $parts[1];
        $annotations = $parts[2];
        $offsetMinute = sprintf('[+-]%s(?::?%s)?', self::HOUR, self::MINUTE);
        $zoneComponent = '[A-Za-z._][A-Za-z._0-9+-]*';
        $zone = sprintf('(?:%s|%s(?:/%s)*)', $offsetMinute, $zoneComponent, $zoneComponent);
        $annotationPattern = sprintf(
            '~\A(?:\[!?%s\])?(?:\[!?[a-z_][a-z0-9_-]*=[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\])*\z~',
            $zone,
        );
        if (preg_match($annotationPattern, $annotations) !== 1) {
            throw new RangeError(sprintf('Invalid annotations in calendar string "%s".', $input));
        }
        $calendar = CalendarMath::validateAnnotations($annotations, $input) ?? 'iso8601';
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
            if ($date[3] !== '' && preg_match(sprintf('/\A[Tt ]%s(?:[Zz]|%s)?\z/', $time, $offset), $date[3]) !== 1) {
                throw new RangeError(sprintf('Invalid time in calendar string "%s".', $input));
            }
            return $calendar;
        }
        // Try the short date forms before time: unprefixed times that also match
        // a year-month or month-day are reserved for the date grammar.
        if (preg_match(sprintf('/\A(%s)-?(%s)\z/', self::YEAR, self::MONTH), $body, $date) === 1) {
            self::validateDate($date[1], (int) $date[2], 1, $input);
            self::requireIsoShortDate($calendar, $input);
            return $calendar;
        }
        if (
            preg_match(sprintf('/\A(?:--)?(%s)-?(%s)\z/', self::MONTH, self::DAY), $body, $date) === 1
            && (int) $date[2] <= CalendarMath::calcDaysInMonth(1972, (int) $date[1])
        ) {
            self::validateDate('1972', (int) $date[1], (int) $date[2], $input);
            self::requireIsoShortDate($calendar, $input);
            return $calendar;
        }
        if (preg_match(sprintf('/\A[Tt]?%s(?:%s)?\z/', $time, $offset), $body) === 1) {
            return $calendar;
        }
        throw new RangeError(sprintf('Invalid calendar string "%s".', $input));
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
            throw new RangeError(sprintf('Invalid date in calendar string "%s".', $input));
        }
    }

    private static function requireIsoShortDate(string $calendar, string $input): void
    {
        if ($calendar !== 'iso8601') {
            throw new RangeError(sprintf('Short date in calendar string "%s" requires the ISO calendar.', $input));
        }
    }
}
