<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Spec\Internal\Calendar\CalendarFactory;

/**
 * Normalizes an object property bag to an array the way TC39's PrepareCalendarFields
 * reads one: `calendar` first, then each recognized field as its own `Get(O, P)`, in
 * alphabetical order.
 *
 * The alternative — `get_object_vars()` — sees only an object's DECLARED public
 * properties. An object that exposes its fields through `__get` (an ordinary PHP DTO,
 * a lazily-hydrated entity, a wrapper around a config array) snapshots as an empty bag,
 * so every field silently goes missing and the caller reports the fields as absent.
 * Reading the recognized names one at a time through {@see Options::bagGet()} fires
 * those accessors.
 *
 * Which names get read is part of the contract, not an implementation detail. A bag may
 * legitimately throw from an accessor for a name the operation has no business reading,
 * so the field list is derived rather than guessed: the requested calendar fields, plus
 * `era`/`eraYear` when — and only when — the bag's calendar has eras, plus the caller's
 * non-calendar fields (`timeZone`, `offset`), all sorted together.
 *
 * Array bags are returned unchanged: their keys are already a snapshot, there is no
 * accessor to fire, and passing them through preserves entries the caller inspects but
 * did not list.
 *
 * @internal
 */
final class FieldBag
{
    /**
     * Snapshots $bag, reading the fields TC39 prescribes for a calendar-aware type.
     *
     * @param array<array-key, mixed>|object $bag
     * @param list<string> $calendarFields    Calendar field names the operation requests.
     * @param list<string> $nonCalendarFields Non-calendar names (`timeZone`, `offset`), sorted in with the rest.
     * @param string $context Type name used in the calendar-resolution error message.
     * @return array<array-key, mixed>
     */
    public static function forCalendarType(
        array|object $bag,
        array $calendarFields,
        array $nonCalendarFields,
        string $context,
    ): array {
        if (is_array($bag)) {
            return $bag;
        }

        // GetTemporalCalendarIdentifierWithISODefault precedes PrepareCalendarFields, so
        // `calendar` is read — and an unusable one rejected — before any field is touched.
        /** @var mixed $calendarRaw */
        $calendarRaw = Options::bagGet($bag, 'calendar');
        $calendarId = $calendarRaw === Options::ABSENT
            ? null
            : CalendarFactory::resolveBagCalendar($calendarRaw, $context);

        $snapshot = Options::bagSnapshot($bag, self::fieldNames($calendarFields, $calendarId, $nonCalendarFields));
        if ($calendarRaw !== Options::ABSENT) {
            $snapshot = array_merge($snapshot, ['calendar' => $calendarRaw]);
        }

        return $snapshot;
    }

    /**
     * Snapshots a PARTIAL bag — the argument to a `with()` call, which overrides some
     * fields of an existing value and inherits the rest.
     *
     * What separates this from {@see self::forCalendarType()} is the calendar: it is the
     * receiver's, never the bag's. `with()` cannot change it, and IsPartialTemporalObject
     * rejects a bag that so much as carries a `calendar` or `timeZone` key.
     *
     * That rejection reads both names with `Get(O, P)`, not HasProperty, and does so
     * BEFORE any field is touched — IsPartialTemporalObject runs ahead of
     * PrepareCalendarFields. An accessor for either name therefore does fire, and fires
     * first, which is observable when it has side effects or throws.
     *
     * @param array<array-key, mixed>|object $bag
     * @param list<string> $calendarFields Calendar field names the operation recognizes.
     * @param string|null $calendarId The RECEIVER's calendar, which decides whether era/eraYear are fields.
     * @param list<string> $nonCalendarFields Non-calendar names (`offset`), sorted in with the rest.
     * @return array<array-key, mixed>
     */
    public static function forPartial(
        array|object $bag,
        array $calendarFields,
        ?string $calendarId,
        array $nonCalendarFields = [],
    ): array {
        if (is_array($bag)) {
            return $bag;
        }

        // Re-exposed as null so the caller's IsPartialTemporalObject check sees the name;
        // only its presence decides the rejection, never its value.
        $rejected = [];
        foreach (['calendar', 'timeZone'] as $name) {
            if (Options::bagGet($bag, $name) === Options::ABSENT) {
                continue;
            }
            $rejected[$name] = null;
        }

        $snapshot = Options::bagSnapshot($bag, self::fieldNames($calendarFields, $calendarId, $nonCalendarFields));

        return array_merge($snapshot, $rejected);
    }

    /**
     * Snapshots $bag for a type that carries no calendar — `Temporal.Duration`, whose
     * fields are a fixed list read in alphabetical order with no `calendar` step.
     *
     * @param array<array-key, mixed>|object $bag
     * @param list<string> $fields Recognized field names; sorted here, so callers may list them in any order.
     * @return array<array-key, mixed>
     */
    public static function forFields(array|object $bag, array $fields): array
    {
        if (is_array($bag)) {
            return $bag;
        }

        sort($fields);

        return Options::bagSnapshot($bag, $fields);
    }

    /**
     * Builds PrepareCalendarFields' field list: the requested calendar fields, the
     * CalendarExtraFields the calendar contributes, and the caller's non-calendar
     * fields, all sorted together into the single alphabetical order the spec reads.
     *
     * @param list<string> $calendarFields
     * @param list<string> $nonCalendarFields
     * @return list<string>
     */
    private static function fieldNames(array $calendarFields, ?string $calendarId, array $nonCalendarFields): array
    {
        $names = $calendarFields;
        if (CalendarMath::supportsEras($calendarId)) {
            // Eraless calendars never expose era/eraYear, and probing them would fire an
            // accessor on a name the spec does not read.
            $names[] = 'era';
            $names[] = 'eraYear';
        }
        foreach ($nonCalendarFields as $name) {
            $names[] = $name;
        }
        sort($names);

        return $names;
    }
}
