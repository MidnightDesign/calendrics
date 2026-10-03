<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\TypeError;
use Calendrics\Spec\Duration;
use Calendrics\Spec\PlainDate;
use Calendrics\Spec\ZonedDateTime;

/** @internal */
final class RelativeTo
{
    /**
     * Resolves GetTemporalRelativeToOption once, before callers take any early return.
     * Only an absent option means no anchor; an explicit null is invalid.
     *
     * @param array<array-key, mixed> $options
     */
    public static function readOption(array $options): PlainDate|ZonedDateTime|null
    {
        if (!array_key_exists('relativeTo', $options)) {
            return null;
        }
        /** @var mixed $relativeTo */
        $relativeTo = $options['relativeTo'];
        if ($relativeTo instanceof PlainDate || $relativeTo instanceof ZonedDateTime) {
            return $relativeTo;
        }
        if (is_string($relativeTo)) {
            return preg_match('/\[[^\]=]+\]/', $relativeTo) === 1
                ? ZonedDateTime::from($relativeTo)
                : PlainDate::from($relativeTo);
        }
        if (!is_array($relativeTo) && !is_object($relativeTo)) {
            throw new TypeError('relativeTo must be a string, property bag, or Temporal date/datetime.');
        }
        $bag = FieldBag::forCalendarType(
            $relativeTo,
            ZonedFields::CALENDAR_FIELDS,
            ['offset', 'timeZone'],
            'relativeTo',
        );
        if (array_key_exists('timeZone', $bag)) {
            return ZonedDateTime::from($bag);
        }
        foreach (['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'] as $field) {
            if (!array_key_exists($field, $bag)) {
                continue;
            }
            CalendarMath::toFiniteInt($bag[$field], "relativeTo {$field}");
        }
        return PlainDate::from($bag);
    }

    /** @return array{year: int, month: int, day: int} */
    public static function toPlainDateBag(PlainDate|ZonedDateTime $anchor): array
    {
        if ($anchor instanceof ZonedDateTime) {
            $local = $anchor->localComponents();
            return ['year' => $local['year'], 'month' => $local['month'], 'day' => $local['day']];
        }
        return ['year' => $anchor->isoYear, 'month' => $anchor->isoMonth, 'day' => $anchor->isoDay];
    }

    public static function resolveAnchor(PlainDate|ZonedDateTime $anchor): RelativeAnchor
    {
        if ($anchor instanceof ZonedDateTime) {
            return RelativeAnchor::onInstant(...$anchor->epochParts());
        }
        return RelativeAnchor::onDate(AnchorMath::isoDateToEpochDays(
            $anchor->isoYear,
            $anchor->isoMonth,
            $anchor->isoDay,
        ));
    }

    /**
     * UTC and fixed offsets need no DST arithmetic.
     *
     * @return null|array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int}
     */
    public static function resolveZdt(PlainDate|ZonedDateTime $anchor): ?array
    {
        if (!$anchor instanceof ZonedDateTime) {
            return null;
        }
        $tzId = $anchor->timeZoneId;
        if ($tzId === 'UTC' || preg_match('/^[+\-]\d{2}:\d{2}$/', $tzId) === 1) {
            return null;
        }
        [$epochSec, $subNs] = $anchor->epochParts();
        $local = $anchor->localComponents();
        return [
            'epochSec' => $epochSec,
            'subNs' => $subNs,
            'tzId' => $tzId,
            'year' => $local['year'],
            'month' => $local['month'],
            'day' => $local['day'],
            'hour' => $local['hour'],
            'minute' => $local['minute'],
            'second' => $local['second'],
        ];
    }

    public static function zdtTargetOutOfRange(int $epochSec, int $subNs, Duration $d): bool
    {
        [$seconds, $nanoseconds] = DurationTime::parts($d);
        $seconds += (int) $d->days * 86_400;
        $subNs += $nanoseconds;
        $carry = CalendarMath::floorDiv($subNs, EpochLimits::NS_PER_SECOND);
        $targetSec = $epochSec + $seconds + $carry;
        $targetSubNs = $subNs - ($carry * EpochLimits::NS_PER_SECOND);

        return (
            $targetSec > EpochLimits::MAX_EPOCH_SECONDS
            || $targetSec < -EpochLimits::MAX_EPOCH_SECONDS
            || $targetSec === EpochLimits::MAX_EPOCH_SECONDS
            && $targetSubNs > 0
        );
    }
}
