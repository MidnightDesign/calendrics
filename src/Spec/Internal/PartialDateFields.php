<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\Internal\Calendar\CalendarProtocol;

/**
 * Merges partial calendar fields before options are read, then resolves the date
 * afterward. Month-code suitability and overflow validation follow option access.
 *
 * @internal
 */
final readonly class PartialDateFields
{
    private function __construct(
        private CalendarProtocol $calendar,
        private int $year,
        private ?int $month,
        private ?string $monthCode,
        private int $day,
    ) {}

    /** @param array<array-key, mixed> $fields */
    public static function prepare(
        array $fields,
        string $calendarId,
        int $year,
        string $monthCode,
        int $day,
        string $context,
    ): self {
        $calendar = CalendarFactory::get($calendarId);
        $hasYear = array_key_exists('year', $fields);
        $readsEraFields = CalendarMath::readsEraFields($calendarId);
        $hasEra = $readsEraFields && array_key_exists('era', $fields);
        $hasEraYear = $readsEraFields && array_key_exists('eraYear', $fields);
        if (($hasEra || $hasEraYear) && !CalendarMath::supportsEras($calendarId)) {
            throw new TypeError('eraYear and era are invalid for this calendar.');
        }
        if ($hasEra !== $hasEraYear && !$hasYear) {
            throw new TypeError('era and eraYear must be provided together.');
        }
        if ($hasYear) {
            $year = CalendarMath::toFiniteInt($fields['year'], "{$context} year");
        } elseif ($hasEra) {
            $year = CalendarMath::resolveYearFromEra($calendar, $fields['era'], $fields['eraYear'], $context) ?? $year;
        }

        $hasMonth = array_key_exists('month', $fields);
        $hasMonthCode = array_key_exists('monthCode', $fields);
        if ($hasMonthCode) {
            $monthCode = MonthCode::validate($fields['monthCode']);
        } elseif ($hasMonth) {
            $monthCode = null;
        }
        $month = $hasMonth ? CalendarMath::toFiniteInt($fields['month'], "{$context} month") : null;
        if (array_key_exists('day', $fields)) {
            $day = CalendarMath::toFiniteInt($fields['day'], "{$context} day");
        }
        if ($month !== null && $month < 1) {
            throw new RangeError("Invalid month {$month}: must be at least 1.");
        }
        if ($day < 1) {
            throw new RangeError("Invalid day {$day}: must be at least 1.");
        }

        return new self($calendar, $year, $month, $monthCode, $day);
    }

    /** @return array{int, int, int} ISO year, month, and day. */
    public function resolve(string $overflow): array
    {
        if ($this->monthCode !== null) {
            if (
                $this->month !== null
                && $this->month !== $this->calendar->monthCodeToMonth($this->monthCode, $this->year)
            ) {
                throw new RangeError('Conflicting month and monthCode fields.');
            }
            return $this->calendar->calendarToIsoFromMonthCode($this->year, $this->monthCode, $this->day, $overflow);
        }
        assert(
            $this->month !== null,
            description: 'An explicit month is the only field that clears the inherited monthCode.',
        );

        return $this->calendar->calendarToIso($this->year, $this->month, $this->day, $overflow);
    }
}
