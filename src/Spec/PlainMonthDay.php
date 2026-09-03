<?php

declare(strict_types=1);

namespace Calendrics\Spec;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\Internal\CalendarMath;
use Calendrics\Spec\Internal\FieldBag;
use Calendrics\Spec\Internal\HasPlainLocaleString;
use Calendrics\Spec\Internal\HasStringRepresentations;
use Calendrics\Spec\Internal\LocaleComponents;
use Calendrics\Spec\Internal\MonthCode;
use Calendrics\Spec\Internal\Options;
use Stringable;

/**
 * A calendar month-day without a year, time, or time zone.
 *
 * Only the ISO 8601 calendar is supported. The referenceISOYear defaults to
 * 1972 (a leap year), which allows Feb 29 to be a valid PlainMonthDay.
 *
 * @see https://tc39.es/proposal-temporal/#sec-temporal-plainmonthday-objects
 */
final class PlainMonthDay implements Stringable
{
    use HasPlainLocaleString;
    use HasStringRepresentations;

    /**
     * The calendar fields a PlainMonthDay is built from, as passed to
     * PrepareCalendarFields. `era`/`eraYear` are CalendarExtraFields, added by
     * {@see FieldBag} only for calendars that have eras.
     *
     * @var list<string>
     */
    private const array CALENDAR_FIELDS = ['year', 'month', 'monthCode', 'day'];

    // -------------------------------------------------------------------------
    // Virtual (get-only) properties
    // -------------------------------------------------------------------------

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public string $monthCode {
        get => CalendarFactory::get($this->calendarId)->monthCode(
            $this->referenceISOYear,
            $this->isoMonth,
            $this->isoDay,
        );
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $month {
        get => CalendarFactory::get($this->calendarId)->month($this->referenceISOYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property
     * @psalm-suppress PossiblyUnusedProperty
     * @psalm-api
     */
    public int $day {
        get => CalendarFactory::get($this->calendarId)->day($this->referenceISOYear, $this->isoMonth, $this->isoDay);
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /** @psalm-api */
    public readonly string $calendarId;

    /**
     * The ISO month number 1–12.
     *
     * @psalm-api
     * @var int<1, 12>
     */
    public readonly int $isoMonth;

    /**
     * The ISO day number 1–31.
     *
     * @psalm-api
     * @var int<1, 31>
     */
    public readonly int $isoDay;

    /**
     * The reference ISO year; defaults to 1972 (a leap year).
     *
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public readonly int $referenceISOYear;

    /**
     * Constructs a PlainMonthDay.
     *
     * @param mixed $isoMonth        Month 1–12 after TC39 ToIntegerWithTruncation.
     * @param mixed $isoDay          Day 1–31 (calendar-aware) after coercion.
     * @param string|int|float|bool|object|null $calendar Calendar ID string; only "iso8601" supported.
     * @param mixed $referenceISOYear Reference ISO year; defaults to 1972 when null/omitted.
     *
     * @throws TypeError if calendar is provided but is not a string.
     * @throws RangeError if month/day/referenceISOYear are out of range, infinite,
     *                                  or the calendar is unsupported.
     */
    public function __construct(
        mixed $isoMonth,
        mixed $isoDay,
        string|int|float|bool|object|null $calendar = 'iso8601',
        mixed $referenceISOYear = null,
    ) {
        $this->calendarId = CalendarFactory::resolveConstructorCalendar($calendar, 'PlainMonthDay');
        // TC39 ToIntegerWithTruncation: null/omitted → 0, bool → 0/1, string/float → truncated int.
        // referenceISOYear defaults to 1972 when omitted/null.
        $monthInt = CalendarMath::toConstructorInt($isoMonth, 'PlainMonthDay isoMonth');
        if ($monthInt < 1 || $monthInt > 12) {
            throw new RangeError("Invalid PlainMonthDay: month {$monthInt} is out of range 1–12.");
        }
        $this->isoMonth = $monthInt;
        $dayInt = CalendarMath::toConstructorInt($isoDay, 'PlainMonthDay isoDay');
        if ($dayInt < 1) {
            throw new RangeError("Invalid PlainMonthDay: day {$dayInt} must be at least 1.");
        }
        $this->referenceISOYear = $referenceISOYear === null
            ? 1972
            : CalendarMath::toConstructorInt($referenceISOYear, 'PlainMonthDay referenceISOYear');

        // Validate day against the reference year's month.
        $daysInMonth = CalendarMath::calcDaysInMonth($this->referenceISOYear, $this->isoMonth);
        if ($dayInt > $daysInMonth) {
            throw new RangeError(
                "Invalid PlainMonthDay: day {$dayInt} exceeds {$daysInMonth} days in "
                . "{$this->referenceISOYear}-{$this->isoMonth}.",
            );
        }
        /** @psalm-suppress InvalidPropertyAssignmentValue — $dayInt <= $daysInMonth <= 31 */
        // @mago-ignore analysis:invalid-property-assignment-value
        $this->isoDay = $dayInt;

        // TC39 range: the resulting date (referenceISOYear-month-day) must be within the
        // representable PlainDate range (Apr 19 −271821 … Sep 13 +275760).
        if (!self::isIsoDateWithinLimits($this->referenceISOYear, $this->isoMonth, $this->isoDay)) {
            throw new RangeError(sprintf(
                'Invalid PlainMonthDay: %d-%d-%d is outside the representable range.',
                $this->referenceISOYear,
                $this->isoMonth,
                $this->isoDay,
            ));
        }
    }

    /**
     * ISODateWithinLimits: whether an ISO date falls within the representable PlainDate
     * range (epoch days within [-100_000_001, 100_000_000], i.e. Apr 19 −271821 …
     * Sep 13 +275760).
     */
    private static function isIsoDateWithinLimits(int $isoYear, int $isoMonth, int $isoDay): bool
    {
        $epochDays = CalendarMath::toJulianDay($isoYear, $isoMonth, $isoDay) - 2_440_588;

        return $epochDays >= -100_000_001 && $epochDays <= 100_000_000;
    }

    // -------------------------------------------------------------------------
    // Static factory methods
    // -------------------------------------------------------------------------

    /**
     * Creates a PlainMonthDay from another PlainMonthDay, an ISO 8601 string, or a
     * property-bag array with 'month'/'monthCode' and 'day' fields.
     *
     * @param self|string|array<array-key, mixed>|object $item PlainMonthDay, ISO 8601 month-day string, or property-bag array.
     * @param mixed $options Options bag (['overflow' => 'constrain'|'reject']), null/primitive (TypeError), or omitted.
     * @throws RangeError if the string is invalid or overflow option is invalid.
     * @throws \TypeError if the type cannot be interpreted as a PlainMonthDay.
     * @psalm-api
     */
    public static function from(string|array|object $item, mixed $options = []): self
    {
        if (is_string($item)) {
            // ToTemporalMonthDay (string branch): ParseISODateTime (step 11) runs BEFORE
            // GetOptionsObject (step 14) and GetTemporalOverflowOption (step 15), so a
            // malformed string raises RangeError even when the options argument is a bad
            // value (a primitive options arg is a TypeError raised AFTER the parse).
            // Overflow is irrelevant to a string but is still validated.
            $result = self::fromString($item);
            Options::overflowFromValue($options);
            return $result;
        }

        // An existing PlainMonthDay is copied wholesale — no fields are read — but the
        // options argument is still put through GetOptionsObject.
        if ($item instanceof self) {
            Options::overflowFromValue($options);
            return new self($item->isoMonth, $item->isoDay, $item->calendarId, $item->referenceISOYear);
        }
        // Temporal objects with calendar fields: extract as a property bag
        // per TC39 ToTemporalMonthDay step that calls CalendarFields. Reading an
        // internal slot is not observable, so the options follow as usual.
        if ($item instanceof PlainDate || $item instanceof PlainDateTime) {
            $bag = [
                'year' => $item->year,
                'month' => $item->month,
                'monthCode' => $item->monthCode,
                'day' => $item->day,
                'calendar' => $item->calendarId,
            ];
            return self::fromPropertyBag($bag, Options::overflowFromValue($options));
        }

        // Property-bag branch: PrepareCalendarFields reads the bag BEFORE
        // GetOptionsObject, and both precede the algorithmic field validation in
        // CalendarMonthDayFromFields.
        $item = FieldBag::forCalendarType($item, self::CALENDAR_FIELDS, [], 'PlainMonthDay');
        $overflow = Options::overflowFromValue($options);
        return self::fromPropertyBag($item, $overflow);
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Returns a new PlainMonthDay with the specified fields overridden.
     *
     * Recognized fields: 'month', 'monthCode', 'day'. The 'year' field is
     * accepted but only used for overflow validation (not for a range check).
     * The 'calendar' and 'timeZone' keys must not be present.
     *
     * @param array<array-key, mixed>|object $fields Property bag with fields to override.
     * @param mixed $options Options bag (['overflow' => ...]), null/primitive (TypeError), or omitted.
     * @throws \TypeError             if $fields contains 'calendar' or 'timeZone'.
     * @throws \TypeError             if no recognized fields are present.
     * @throws RangeError if the resulting month-day is invalid (overflow: reject).
     * @psalm-api
     */
    public function with(array|object $fields, mixed $options = []): self
    {
        // Reject Temporal objects (IsPartialTemporalObject step 2).
        if (
            $fields instanceof PlainDate
            || $fields instanceof PlainDateTime
            || $fields instanceof self
            || $fields instanceof PlainTime
            || $fields instanceof PlainYearMonth
            || $fields instanceof ZonedDateTime
            || $fields instanceof Instant
            || $fields instanceof Duration
        ) {
            throw new TypeError('PlainMonthDay::with() argument must not be a Temporal object.');
        }

        // Normalize inputs to array up front so the body has a single path.
        $bag = FieldBag::forPartial($fields, self::CALENDAR_FIELDS, $this->calendarId);

        // IsPartialTemporalObject step 3: calendar key present → TypeError.
        if (array_key_exists('calendar', $bag)) {
            throw new TypeError('PlainMonthDay::with() fields must not contain a calendar property.');
        }
        // IsPartialTemporalObject step 4: timeZone key present → TypeError.
        if (array_key_exists('timeZone', $bag)) {
            throw new TypeError('PlainMonthDay::with() fields must not contain a timeZone property.');
        }

        // PrepareCalendarFields step 10: at least one recognized calendar field must be present
        // and not undefined (null in PHP).
        $hasYear = array_key_exists('year', $bag) && $bag['year'] !== null;
        $hasMonth = array_key_exists('month', $bag) && $bag['month'] !== null;
        $hasMonthCode = array_key_exists('monthCode', $bag) && $bag['monthCode'] !== null;
        $hasDay = array_key_exists('day', $bag) && $bag['day'] !== null;

        if (!$hasYear && !$hasMonth && !$hasMonthCode && !$hasDay) {
            throw new TypeError('PlainMonthDay::with() requires at least one of: year, month, monthCode, day.');
        }

        // GetOptionsObject + GetTemporalOverflowOption are deferred to AFTER the field
        // reading/coercion below: TC39 PrepareCalendarFields precedes GetOptionsObject,
        // so a bad field value's RangeError must precede a primitive options TypeError.
        $resolveOverflow = static fn(): string => Options::overflowFromValue($options);

        $calendar = $this->calendarId !== 'iso8601' ? CalendarFactory::get($this->calendarId) : null;

        // Non-ISO calendar path.
        if ($calendar !== null) {
            // For non-ISO calendars, month without monthCode requires year.
            if ($hasMonth && !$hasMonthCode && !$hasYear) {
                throw new TypeError(
                    'PlainMonthDay::with() non-ISO calendar requires year when only month is provided.',
                );
            }

            // Resolve monthCode: use provided, or default to current.
            $monthCode = null;
            $useMonthCode = false;
            if ($hasMonthCode) {
                // MonthCode::validate: non-string TYPE => TypeError, ill-formed STRING => RangeError.
                $monthCode = MonthCode::validate($bag['monthCode']);
                $useMonthCode = true;
            }

            $month = null;
            if ($hasMonth) {
                $month = CalendarMath::toFiniteInt($bag['month'], 'PlainMonthDay::with() month');
                $useMonthCode = false;
            }
            if (!$hasMonth && !$hasMonthCode) {
                // Default: preserve current monthCode.
                $monthCode = $this->monthCode;
                $useMonthCode = true;
            }

            $day = $this->day;
            if ($hasDay) {
                $day = CalendarMath::toFiniteInt($bag['day'], 'PlainMonthDay::with() day');
            }

            if ($day < 1) {
                throw new RangeError("Invalid day {$day}: must be at least 1.");
            }

            // Resolve year for validation context.
            $calYear = null;
            if ($hasYear) {
                $calYear = CalendarMath::toFiniteInt($bag['year'], 'PlainMonthDay::with() year');
            }

            if ($calYear !== null) {
                // All fields have been read/coerced; resolve the options object now.
                $overflow = $resolveOverflow();

                // Validate month/monthCode conflict with year context.
                if ($useMonthCode && $hasMonth) {
                    assert($monthCode !== null, description: '$useMonthCode implies monthCode was provided');
                    /** @var int $month */
                    $resolvedMonth = $calendar->monthCodeToMonth($monthCode, $calYear);
                    if ($month !== $resolvedMonth) {
                        throw new RangeError('Conflicting month and monthCode fields.');
                    }
                }

                if ($useMonthCode && $monthCode !== null) {
                    [$isoY, $isoM, $isoD] = $calendar->calendarToIsoFromMonthCode(
                        $calYear,
                        $monthCode,
                        $day,
                        $overflow,
                    );
                } else {
                    /** @var int $month */
                    if ($month < 1) {
                        throw new RangeError("Invalid month {$month}: must be at least 1.");
                    }
                    [$isoY, $isoM, $isoD] = $calendar->calendarToIso($calYear, $month, $day, $overflow);
                }

                // Read back resolved monthCode+day after overflow processing.
                $resolvedMonthCode = $calendar->monthCode($isoY, $isoM, $isoD);
                $resolvedDay = $calendar->day($isoY, $isoM, $isoD);
                return self::resolveNonIsoReferenceYear($calendar, $this->calendarId, $resolvedMonthCode, $resolvedDay);
            }

            // No year: use monthCode path with reference year resolution. Resolve the
            // options object (GetOptionsObject) now that all fields have been read.
            $resolveOverflow();
            // $useMonthCode is always true here: month-without-year-or-monthCode was
            // rejected above, and every other no-year shape sets useMonthCode = true.
            /** @var string $monthCode — guaranteed non-null when $useMonthCode is true */
            return self::resolveNonIsoReferenceYear($calendar, $this->calendarId, $monthCode, $day);
        }

        // ISO path: start from current fields.
        $month = $this->isoMonth;
        $monthCode = null;
        if ($hasMonthCode) {
            // MonthCode::validate: non-string TYPE => TypeError, ill-formed STRING => RangeError.
            $monthCode = MonthCode::validate($bag['monthCode']);
        }
        if ($hasMonth) {
            $month = CalendarMath::toFiniteInt($bag['month'], 'PlainMonthDay::with() month');
        }

        $day = $this->isoDay;
        if ($hasDay) {
            $day = CalendarMath::toFiniteInt($bag['day'], 'PlainMonthDay::with() day');
        }

        $refYear = $this->referenceISOYear;
        if ($hasYear) {
            $refYear = CalendarMath::toFiniteInt($bag['year'], 'PlainMonthDay::with() year');
        }

        // `month` and `day` are read with ToPositiveIntegerWithTruncation, so a
        // non-positive value is rejected during field preparation — before the options.
        if ($hasMonth && $month < 1) {
            throw new RangeError("Invalid month {$month}: must be at least 1.");
        }
        if ($day < 1) {
            throw new RangeError("Invalid day {$day}: must be at least 1.");
        }

        // All fields have been read/coerced; resolve the options object now — still
        // ahead of CalendarMonthDayFromFields, which is what decides whether the month
        // code names a month this calendar has.
        $overflow = $resolveOverflow();

        if ($hasMonthCode) {
            /** @var string $monthCode */
            $mcMonth = CalendarMath::monthCodeToMonth($monthCode);
            if ($hasMonth && $month !== $mcMonth) {
                throw new RangeError('Conflicting month and monthCode fields.');
            }
            $month = $mcMonth;
        }

        if ($overflow === 'constrain') {
            /**
             * @psalm-suppress UnnecessaryVarAnnotation — Mago can't narrow min()
             */
            $month = min(12, $month);
            $maxDay = CalendarMath::calcDaysInMonth($refYear, $month);
            $day = min($maxDay, $day);
        } else {
            // reject: validate against refYear's month
            if ($month > 12) {
                throw new RangeError("Invalid month {$month}: must be in range 1–12.");
            }
            $maxDay = CalendarMath::calcDaysInMonth($refYear, $month);
            if ($day > $maxDay) {
                throw new RangeError(
                    "Invalid day {$day}: exceeds {$maxDay} days in month {$month} of year {$refYear}.",
                );
            }
        }

        // Always use 1972 as the new referenceISOYear: 1972 is a leap year, so its
        // days-in-month is the maximum any year provides, and the constrain/reject
        // validation above guarantees $day never exceeds it.
        return new self($month, $day, $this->calendarId, 1972);
    }

    /**
     * Returns true if this PlainMonthDay is the same as $other.
     *
     * Equality compares month, day, calendarId, AND referenceISOYear.
     *
     * @param self|string|array<array-key, mixed>|object $other A PlainMonthDay, ISO 8601 month-day string, or property-bag array.
     * @psalm-api
     */
    public function equals(string|array|object $other): bool
    {
        $o = $other instanceof self ? $other : self::from($other);
        return (
            $this->isoMonth === $o->isoMonth
            && $this->isoDay === $o->isoDay
            && $this->referenceISOYear === $o->referenceISOYear
            && $this->calendarId === $o->calendarId
        );
    }

    /**
     * Returns a string representation.
     *
     * For ISO 8601 calendar:
     *   auto/never → "MM-DD"
     *   always     → "YYYY-MM-DD[u-ca=iso8601]"
     *   critical   → "YYYY-MM-DD[!u-ca=iso8601]"
     *
     * For non-ISO calendars:
     *   auto       → "YYYY-MM-DD[u-ca=<id>]"
     *   never      → "YYYY-MM-DD" (year shown, but no annotation)
     *   always     → "YYYY-MM-DD[u-ca=<id>]"
     *   critical   → "YYYY-MM-DD[!u-ca=<id>]"
     *
     * @param array<array-key, mixed>|object|null $options Options bag: ['calendarName' => 'auto'|'always'|'never'|'critical']
     * @throws RangeError for invalid calendarName values.
     * @psalm-api
     */
    #[\Override]
    public function toString(mixed $options = null): string
    {
        $opts = Options::normalizeOptions($options, ['calendarName']);

        $calendarName = 'auto';
        if (array_key_exists('calendarName', $opts)) {
            $cn = Options::coerceEnumOption($opts['calendarName'], 'calendarName');
            $calendarName = $cn;
        }

        $isNonIso = $this->calendarId !== 'iso8601';
        $yearStr = self::formatYear($this->referenceISOYear);
        $dateStr = sprintf('%s-%02d-%02d', $yearStr, $this->isoMonth, $this->isoDay);

        return match ($calendarName) {
            'auto' => $isNonIso
                ? sprintf('%s[u-ca=%s]', $dateStr, $this->calendarId)
                : sprintf('%02d-%02d', $this->isoMonth, $this->isoDay),
            'never' => $isNonIso ? $dateStr : sprintf('%02d-%02d', $this->isoMonth, $this->isoDay),
            'always' => sprintf('%s[u-ca=%s]', $dateStr, $this->calendarId),
            'critical' => sprintf('%s[!u-ca=%s]', $dateStr, $this->calendarId),
            default => throw new RangeError("Invalid calendarName value: \"{$calendarName}\"."),
        };
    }

    /**
     * Formats a year for ISO 8601 string output.
     *
     * Years 0-9999 use 4-digit format, years outside that range use 6-digit
     * with a sign prefix (+ or -).
     */
    private static function formatYear(int $year): string
    {
        if ($year < 0) {
            return sprintf('-%06d', abs($year));
        }
        if ($year > 9999) {
            return sprintf('+%06d', $year);
        }
        return sprintf('%04d', $year);
    }

    /**
     * Converts this PlainMonthDay to a PlainDate by supplying the year.
     *
     * The day is constrained to the valid range for that year's month (default overflow behaviour).
     *
     * @param array<array-key, mixed>|object $fields Must contain 'year' key.
     * @throws \TypeError             if $fields is not an object/array or 'year' is missing.
     * @throws RangeError if the resulting date is invalid.
     * @psalm-api
     */
    public function toPlainDate(array|object $fields): PlainDate
    {
        // PrepareCalendarFields uses THIS month-day's calendar, so era/eraYear are
        // fields only when that calendar has eras. Probing them otherwise would fire an
        // accessor on a name the spec never reads.
        $bag = FieldBag::forFields(
            $fields,
            CalendarMath::supportsEras($this->calendarId) ? ['year', 'era', 'eraYear'] : ['year'],
        );

        $calendar = $this->calendarId !== 'iso8601' ? CalendarFactory::get($this->calendarId) : null;

        $hasYear = array_key_exists('year', $bag);
        $hasEra = array_key_exists('era', $bag);
        $hasEraYear = array_key_exists('eraYear', $bag);

        if (!$hasYear && !($hasEra && $hasEraYear && $calendar !== null)) {
            throw new TypeError('PlainMonthDay::toPlainDate() argument must have a year property.');
        }

        $year = null;
        if ($hasYear) {
            $year = CalendarMath::toFiniteInt($bag['year'], 'toPlainDate() year');
        }

        // Resolve era + eraYear for non-ISO calendars.
        if ($calendar !== null && $hasEra && $hasEraYear) {
            $resolved = CalendarMath::resolveYearFromEra($calendar, $bag['era'], $bag['eraYear'], 'toPlainDate()');
            if ($resolved !== null) {
                $year = $resolved;
            }
        }

        if ($year === null) {
            throw new TypeError('PlainMonthDay::toPlainDate() could not resolve a year.');
        }

        // Non-ISO calendar: combine the calendar year with this PlainMonthDay's stored monthCode+day.
        if ($calendar !== null) {
            $monthCode = $this->monthCode;
            $day = $this->day;
            [$isoY, $isoM, $isoD] = $calendar->calendarToIsoFromMonthCode($year, $monthCode, $day, 'constrain');
            return new PlainDate($isoY, $isoM, $isoD, $this->calendarId);
        }

        // ISO path: constrain day to valid range for this year-month.
        $maxDay = CalendarMath::calcDaysInMonth($year, $this->isoMonth);
        $day = min($this->isoDay, $maxDay);

        return new PlainDate($year, $this->isoMonth, $day);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Parses an ISO 8601 month-day string into a PlainMonthDay.
     *
     * Accepted formats:
     *   --MM-DD (canonical PlainMonthDay format, no year info → referenceISOYear=1972)
     *   MM-DD (compact form without -- prefix → referenceISOYear=1972)
     *   YYYY-MM-DD, ±YYYYYY-MM-DD (full date strings → referenceISOYear=1972, year from string dropped)
     *   YYYYMMDD, ±YYYYYYMMDD (compact date strings → referenceISOYear=1972)
     * Optional trailing time, offset (only when time is present), and bracket annotations.
     * Z (UTC designator) is never valid for PlainMonthDay.
     * UTC offsets without a time component are not valid.
     *
     * @throws RangeError for invalid or out-of-range dates.
     */
    private static function fromString(string $s): self
    {
        if ($s === '') {
            throw new RangeError('PlainMonthDay::from() received an empty string.');
        }
        // Reject non-ASCII minus sign (U+2212 = \xe2\x88\x92).
        if (str_contains($s, "\u{2212}")) {
            throw new RangeError("PlainMonthDay::from() cannot parse \"{$s}\": non-ASCII minus sign is not allowed.");
        }
        // Reject more than 9 fractional-second digits.
        if (preg_match('/[.,]\d{10,}/', $s) === 1) {
            throw new RangeError(
                "PlainMonthDay::from() cannot parse \"{$s}\": fractional seconds may have at most 9 digits.",
            );
        }

        // Try the --MM-DD or MM-DD format (canonical PlainMonthDay forms).
        // Both --MM-DD and MM-DD are accepted; optional time/offset/brackets may follow.
        // UTC offsets/Z without time are NOT valid.
        // Pattern captures: (1) month, (2) day, (3) hour, (4) min, (5) sec, (6) frac, (7) brackets
        // The double-dash prefix (--) is optional.
        // optional '--' prefix + MM-DD, optional T+time, optional offset, bracket annotations
        // Per DateSpecMonthDay (TwoDashes[opt] DateMonth -[opt] DateDay), the hyphen
        // between month and day is optional: MM-DD, MMDD, --MM-DD and --MMDD are all valid.
        $monthDayPattern = '/^(?:--)?(\d{2})-?(\d{2})(?:[Tt ](\d{2})(?::?(\d{2})(?::?(\d{2})([.,]\d+)?)?)?(?:[Zz]|[+-]\d{2}(?::\d{2}(?::\d{2}(?:[.,]\d+)?)?|\d{2}(?:\d{2}(?:[.,]\d+)?)?)?)?)?((?:\[[^\]]*\])*)$/';

        /** @var list<string> $m */
        $m = [];
        if (preg_match($monthDayPattern, $s, $m) === 1) {
            $month = (int) $m[1];
            $day = (int) $m[2];

            // Validate time portion if present.
            if ($m[3] !== '') {
                $hour = (int) $m[3];
                if ($hour > 23) {
                    throw new RangeError("PlainMonthDay::from() cannot parse \"{$s}\": hour {$hour} out of range.");
                }
                if ($m[4] !== '') {
                    $minute = (int) $m[4];
                    if ($minute > 59) {
                        throw new RangeError(
                            "PlainMonthDay::from() cannot parse \"{$s}\": minute {$minute} out of range.",
                        );
                    }
                    if ($m[5] !== '') {
                        $second = (int) $m[5];
                        if ($second > 60) {
                            throw new RangeError(
                                "PlainMonthDay::from() cannot parse \"{$s}\": second {$second} out of range.",
                            );
                        }
                    }
                }
                // Z is not valid for PlainMonthDay.
                // Determine the offset of the date part from the actual match:
                // TwoDashes (0 or 2) + 2 month digits + separator (0 or 1) + 2 day digits.
                $dashPrefix = str_starts_with($s, '--') ? 2 : 0;
                // A separator dash is present iff the month-day span exceeds MMDD (4 digits).
                $hasSeparator = $s[$dashPrefix + 2] === '-';
                $dateLen = $dashPrefix + 2 + ($hasSeparator ? 1 : 0) + 2;
                $afterDate = substr(string: $s, offset: $dateLen);
                $bracketPos = strpos(haystack: $afterDate, needle: '[');
                $timeOffset = $bracketPos !== false
                    ? substr(string: $afterDate, offset: 0, length: $bracketPos)
                    : $afterDate;
                if (preg_match('/[Zz]/', $timeOffset) === 1) {
                    throw new RangeError(
                        "PlainMonthDay::from() cannot parse \"{$s}\": Z (UTC) designator is not valid.",
                    );
                }
            }

            $calendarId = CalendarMath::validateAnnotations($m[7], $s);

            // Validate month and day.
            if ($month < 1 || $month > 12) {
                throw new RangeError("PlainMonthDay::from() cannot parse \"{$s}\": month {$month} out of range 1–12.");
            }
            if ($day < 1) {
                throw new RangeError("PlainMonthDay::from() cannot parse \"{$s}\": day {$day} must be at least 1.");
            }
            $maxDay = CalendarMath::calcDaysInMonth(1972, $month);
            if ($day > $maxDay) {
                throw new RangeError(
                    "PlainMonthDay::from() cannot parse \"{$s}\": day {$day} exceeds {$maxDay} for month {$month}.",
                );
            }

            // Per TC39 spec: month-day form (no year) with non-ISO calendar is invalid,
            // because a year is required to resolve the reference ISO year.
            if ($calendarId !== null && $calendarId !== 'iso8601') {
                throw new RangeError(
                    "PlainMonthDay::from() cannot parse \"{$s}\": month-day form requires a full date (YYYY-MM-DD) with non-ISO calendar \"{$calendarId}\".",
                );
            }

            // --MM-DD or MM-DD form: referenceISOYear = 1972 (canonical default).
            return new self($month, $day, $calendarId ?? 'iso8601', 1972);
        }

        // Try full date string formats: YYYY-MM-DD, ±YYYYYY-MM-DD, YYYYMMDD, ±YYYYYYMMDD
        // Also handles MM-DD (without --) as a bare month-day string.
        // date: year + rest, optional T+time, optional offset, bracket annotations
        $datePattern = '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2}|\d{4})(?:[Tt ](\d{2})(?::?(\d{2})(?::?(\d{2})([.,]\d+)?)?)?(?:[Zz]|[+-]\d{2}(?::\d{2}(?::\d{2}(?:[.,]\d+)?)?|\d{2}(?:\d{2}(?:[.,]\d+)?)?)?)?)?((?:\[[^\]]*\])*)$/';

        /** @var list<string> $m */
        $m = [];
        if (preg_match($datePattern, $s, $m) !== 1) {
            throw new RangeError("PlainMonthDay::from() cannot parse \"{$s}\": invalid ISO 8601 date string.");
        }

        [, $yearRaw, $dateRest] = $m;

        // Reject minus-zero extended year (-000000).
        if (preg_match('/^-0{6}$/', $yearRaw) === 1) {
            throw new RangeError('Cannot use negative zero as extended year.');
        }

        // Extract month and day from the date rest.
        if (!str_starts_with($dateRest, '-')) {
            $month = (int) substr(string: $dateRest, offset: 0, length: 2);
            $day = (int) substr(string: $dateRest, offset: 2, length: 2);
        } else {
            $month = (int) substr(string: $dateRest, offset: 1, length: 2);
            $day = (int) substr(string: $dateRest, offset: 4, length: 2);
        }

        // Validate the time portion if present.
        if ($m[3] !== '') {
            $hour = (int) $m[3];
            if ($hour > 23) {
                throw new RangeError("PlainMonthDay::from() cannot parse \"{$s}\": hour {$hour} out of range.");
            }
            if ($m[4] !== '') {
                $minute = (int) $m[4];
                if ($minute > 59) {
                    throw new RangeError("PlainMonthDay::from() cannot parse \"{$s}\": minute {$minute} out of range.");
                }
                if ($m[5] !== '') {
                    $second = (int) $m[5];
                    if ($second > 60) {
                        throw new RangeError(
                            "PlainMonthDay::from() cannot parse \"{$s}\": second {$second} out of range.",
                        );
                    }
                }
            }

            // Reject UTC designator (Z) — not valid for PlainMonthDay.
            $afterDate = substr(string: $s, offset: strlen($yearRaw) + strlen($dateRest));
            $bracketPos = strpos(haystack: $afterDate, needle: '[');
            $timeOffset = $bracketPos !== false
                ? substr(string: $afterDate, offset: 0, length: $bracketPos)
                : $afterDate;
            if (preg_match('/[Zz]/', $timeOffset) === 1) {
                throw new RangeError("PlainMonthDay::from() cannot parse \"{$s}\": Z (UTC) designator is not valid.");
            }
        }

        $calendarId = CalendarMath::validateAnnotations($m[7], $s);
        $isoYear = (int) $yearRaw;

        // For non-ISO calendars, project the ISO date through the calendar and find reference year.
        if ($calendarId !== null && $calendarId !== 'iso8601') {
            // Per ToTemporalMonthDay, the parsed ISO date must satisfy ISODateWithinLimits
            // before the calendar's month-day resolution runs. For the iso8601 calendar the
            // parsed year is discarded (referenceISOYear = 1972), so this only applies here.
            if (!self::isIsoDateWithinLimits($isoYear, $month, $day)) {
                throw new RangeError(sprintf(
                    'PlainMonthDay::from() cannot parse "%s": %d-%d-%d is outside the representable ISO date range.',
                    $s,
                    $isoYear,
                    $month,
                    $day,
                ));
            }
            $cal = CalendarFactory::get($calendarId);
            $mc = $cal->monthCode($isoYear, $month, $day);
            $d = $cal->day($isoYear, $month, $day);
            return self::resolveNonIsoReferenceYear($cal, $calendarId, $mc, $d);
        }

        // ISO calendar: always use 1972 as referenceISOYear for strings.
        return new self($month, $day, $calendarId ?? 'iso8601', 1972);
    }

    /**
     * Creates a PlainMonthDay from a property bag.
     *
     * Required fields: 'month'/'monthCode' AND 'day'.
     * Optional: 'year' (used for overflow validation only, determines leap year context).
     *
     * @param array<array-key,mixed> $bag
     * @throws \TypeError             if required fields are missing.
     * @throws RangeError if field values are invalid.
     */
    private static function fromPropertyBag(array $bag, string $overflow): self
    {
        $calendarId = array_key_exists('calendar', $bag)
            ? CalendarFactory::resolveBagCalendar($bag['calendar'], 'PlainMonthDay')
            : null;

        $hasMonth = array_key_exists('month', $bag) && $bag['month'] !== null;
        $hasMonthCode = array_key_exists('monthCode', $bag) && $bag['monthCode'] !== null;
        $hasDay = array_key_exists('day', $bag) && $bag['day'] !== null;
        $hasYear = array_key_exists('year', $bag) && $bag['year'] !== null;
        $hasEraAndEraYear = CalendarMath::hasEraAndEraYear($bag, $calendarId, 'PlainMonthDay');

        $calendar = $calendarId !== null && $calendarId !== 'iso8601' ? CalendarFactory::get($calendarId) : null;
        $hasYearLike = $hasYear || $calendar !== null && $hasEraAndEraYear;

        // For non-ISO calendars, year is required when using month (without monthCode).
        if ($calendar !== null) {
            if (!$hasMonthCode && !$hasYearLike) {
                throw new TypeError(
                    'PlainMonthDay::from() non-ISO calendar requires year when monthCode is not provided.',
                );
            }
            // When both month and monthCode are given, year is needed to resolve conflicts.
            if ($hasMonth && $hasMonthCode && !$hasYearLike) {
                throw new TypeError(
                    'PlainMonthDay::from() non-ISO calendar requires year when both month and monthCode are provided.',
                );
            }
        }

        // day is required.
        if (!$hasDay) {
            throw new TypeError('PlainMonthDay::from() property bag must have a day property.');
        }

        // Either month or monthCode is required.
        if (!$hasMonth && !$hasMonthCode) {
            throw new TypeError('PlainMonthDay::from() property bag must have a month or monthCode property.');
        }

        // Parse month.
        $month = 0;
        $monthCode = null;

        if ($hasMonthCode) {
            // MonthCode::validate: non-string TYPE => TypeError, ill-formed STRING =>
            // RangeError (type-then-syntax), matching PlainDate/PlainYearMonth bag paths.
            $monthCode = MonthCode::validate($bag['monthCode']);
        }
        if ($hasMonth) {
            $month = CalendarMath::toFiniteInt($bag['month'] ?? null, 'PlainMonthDay::from() month');
        }

        $day = CalendarMath::toFiniteInt($bag['day'], 'PlainMonthDay::from() day');

        // Determine the year for overflow/validation.
        $year = null;
        if ($hasYear) {
            $year = CalendarMath::toFiniteInt($bag['year'], 'PlainMonthDay::from() year');
        }

        // Resolve era + eraYear if present (overrides year for era-based calendars).
        if ($calendar !== null && $hasEraAndEraYear) {
            $resolved = CalendarMath::resolveYearFromEra(
                $calendar,
                $bag['era'],
                $bag['eraYear'],
                'PlainMonthDay::from()',
            );
            if ($resolved !== null) {
                if ($year !== null && $year !== $resolved) {
                    throw new RangeError("Conflicting year ({$year}) and era+eraYear (resolved to {$resolved}).");
                }
                $year = $resolved;
            }
        }

        // For non-ISO calendars, delegate to the non-ISO path with reference year resolution.
        if ($calendar !== null) {
            return self::fromPropertyBagNonIso(
                $calendar,
                $calendarId,
                $hasMonth,
                $month,
                $hasMonthCode,
                $monthCode,
                $day,
                $year,
                $overflow,
            );
        }

        // ISO path: default year to 1972 if not provided.
        if ($year === null) {
            $year = 1972;
        }

        // ISO path: resolve monthCode to month number.
        if ($hasMonthCode) {
            /** @var string $monthCode — guaranteed non-null when $hasMonthCode is true */
            $mcMonth = CalendarMath::monthCodeToMonth($monthCode);
            if ($hasMonth && $month !== $mcMonth) {
                throw new RangeError('Conflicting month and monthCode fields.');
            }
            $month = $mcMonth;
        }

        // month < 1 is always invalid.
        if ($month < 1) {
            throw new RangeError("Invalid month {$month}: must be at least 1.");
        }
        // day < 1 is always invalid.
        if ($day < 1) {
            throw new RangeError("Invalid day {$day}: must be at least 1.");
        }

        if ($overflow === 'constrain') {
            /**
             * @psalm-suppress UnnecessaryVarAnnotation — Mago can't narrow min()
             */
            $month = min(12, $month);
            $maxDay = CalendarMath::calcDaysInMonth($year, $month);
            $day = min($maxDay, $day);
        } else {
            // reject
            if ($month > 12) {
                throw new RangeError("Invalid month {$month}: must be in range 1–12.");
            }
            $maxDay = CalendarMath::calcDaysInMonth($year, $month);
            if ($day > $maxDay) {
                throw new RangeError("Invalid day {$day}: exceeds {$maxDay} days in month {$month} of year {$year}.");
            }
        }

        return new self($month, $day, $calendarId ?? 'iso8601', 1972);
    }

    /**
     * Handles the non-ISO calendar path for fromPropertyBag.
     *
     * Resolves calendar fields, validates/constrains using the user's year (if given),
     * then finds the reference ISO year: the latest ISO year at or before 1972
     * where the resolved monthCode+day exists in the calendar.
     *
     * @param Internal\Calendar\CalendarProtocol $calendar
     */
    private static function fromPropertyBagNonIso(
        Internal\Calendar\CalendarProtocol $calendar,
        ?string $calendarId,
        bool $hasMonth,
        int $month,
        bool $hasMonthCode,
        ?string $monthCode,
        int $day,
        ?int $year,
        string $overflow,
    ): self {
        // When year is provided: validate/constrain the date, then derive the final monthCode+day.
        if ($year !== null) {
            if ($hasMonthCode && $monthCode !== null) {
                $resolvedMonth = $calendar->monthCodeToMonth($monthCode, $year);
                if ($hasMonth && $month !== $resolvedMonth) {
                    throw new RangeError('Conflicting month and monthCode fields.');
                }
            }
            if ($month < 1 && !$hasMonthCode) {
                throw new RangeError("Invalid month {$month}: must be at least 1.");
            }
            if ($day < 1) {
                throw new RangeError("Invalid day {$day}: must be at least 1.");
            }

            // Resolve to ISO using the user-provided year to validate/constrain.
            if ($hasMonthCode && $monthCode !== null) {
                [$isoY, $isoM, $isoD] = $calendar->calendarToIsoFromMonthCode($year, $monthCode, $day, $overflow);
            } else {
                [$isoY, $isoM, $isoD] = $calendar->calendarToIso($year, $month, $day, $overflow);
            }

            // Validate the resolved ISO date is within the representable range.
            if (!self::isIsoDateWithinLimits($isoY, $isoM, $isoD)) {
                throw new RangeError(sprintf(
                    'Calendar year %d produces ISO date %d-%d-%d which is outside the representable range.',
                    $year,
                    $isoY,
                    $isoM,
                    $isoD,
                ));
            }

            // Read back the resolved calendar monthCode and day (after overflow processing).
            $resolvedMonthCode = $calendar->monthCode($isoY, $isoM, $isoD);
            $resolvedDay = $calendar->day($isoY, $isoM, $isoD);

            // Find the reference ISO year for this monthCode+day.
            return self::resolveNonIsoReferenceYear(
                $calendar,
                $calendarId,
                $resolvedMonthCode,
                $resolvedDay,
                $overflow,
            );
        }

        // No year: only monthCode path is allowed (validated above).
        if ($monthCode === null) {
            throw new TypeError('PlainMonthDay::from() non-ISO calendar requires year when monthCode is not provided.');
        }
        if ($day < 1) {
            throw new RangeError("Invalid day {$day}: must be at least 1.");
        }

        return self::resolveNonIsoReferenceYear($calendar, $calendarId, $monthCode, $day, $overflow);
    }

    /**
     * Attempts to resolve a constrained (clamped-day) candidate for the given
     * calendar year/monthCode/day, returning a candidate record or null when the
     * constrained result cannot be matched for the reference-year search.
     *
     * @return array{calYear: int, isoY: int, isoM: int, isoD: int, day: int}|null
     */
    private static function tryConstrainedCandidate(
        Internal\Calendar\CalendarProtocol $calendar,
        int $tryCalYear,
        string $monthCode,
        int $day,
    ): ?array {
        try {
            [$resIsoY, $resIsoM, $resIsoD] = $calendar->calendarToIsoFromMonthCode(
                $tryCalYear,
                $monthCode,
                $day,
                'constrain',
            );
        } catch (RangeError $e) {
            // monthCode itself doesn't exist; keep searching.
            unset($e);
            return null;
        }
        if ($resIsoY > 1972) {
            return null;
        }
        if ($calendar->monthCode($resIsoY, $resIsoM, $resIsoD) !== $monthCode) {
            return null;
        }
        return [
            'calYear' => $tryCalYear,
            'isoY' => $resIsoY,
            'isoM' => $resIsoM,
            'isoD' => $resIsoD,
            'day' => $calendar->day($resIsoY, $resIsoM, $resIsoD),
        ];
    }

    /**
     * NonISOMonthDayToISOReferenceDate for the chinese and dangi calendars.
     *
     * Implements the normative ISO reference-year table
     * (chinese-dangi-iso-reference-years) and its surrounding algorithm steps:
     *
     *  - daysInMonth is 30 for both calendars; a day above that is rejected
     *    (reject) or clamped to 30 (constrain).
     *  - If the row's "Days 1–29" cell is blank, or day = 30 and the "Day 30"
     *    cell is blank, then reject throws and constrain drops the leap suffix
     *    (CreateMonthCode with isLeap = false) and re-resolves the common month.
     *  - The reference ISO year for the (possibly de-leaped) month code and day
     *    is taken from the table; the result is the latest ISO date in that ISO
     *    year whose calendar month code and day match.
     *
     * @param Internal\Calendar\CalendarProtocol $calendar
     */
    private static function resolveChineseDangiReferenceYear(
        Internal\Calendar\CalendarProtocol $calendar,
        string $calendarId,
        string $monthCode,
        int $day,
        string $overflow,
    ): self {
        // daysInMonth is 30 for chinese/dangi: clamp or reject above it.
        if ($day > 30) {
            if ($overflow === 'reject') {
                throw new RangeError("monthCode \"{$monthCode}\" with day {$day} does not exist in this calendar.");
            }
            $day = 30;
        }

        $referenceYear = self::chineseDangiReferenceYear($calendarId, $monthCode, $day);

        // Blank table cell: this month-day is not known to occur. Reject throws;
        // constrain drops the leap suffix and resolves the common month instead.
        if ($referenceYear === null) {
            if ($overflow === 'reject') {
                throw new RangeError("monthCode \"{$monthCode}\" with day {$day} does not exist in this calendar.");
            }
            if (!str_ends_with($monthCode, 'L')) {
                throw new RangeError("monthCode \"{$monthCode}\" with day {$day} does not exist in this calendar.");
            }
            $commonCode = substr($monthCode, offset: 0, length: -1);
            return self::resolveChineseDangiReferenceYear($calendar, $calendarId, $commonCode, $day, $overflow);
        }

        // Return the latest ISO date in the reference ISO year whose calendar
        // month code and day match. The calendar year overlapping that ISO year
        // can start in the prior ISO year, so try both boundary calendar years
        // and keep the latest ISO date that lands within the reference ISO year.
        /** @var array{0: int, 1: int, 2: int}|null $best */
        $best = null;
        $calYearCandidates = array_unique([
            $calendar->year($referenceYear, 12, 31),
            $calendar->year($referenceYear, 1, 1),
        ]);
        foreach ($calYearCandidates as $calYear) {
            try {
                [$isoY, $isoM, $isoD] = $calendar->calendarToIsoFromMonthCode($calYear, $monthCode, $day, 'reject');
            } catch (RangeError $e) {
                unset($e);
                continue;
            }
            if ($isoY !== $referenceYear) {
                continue;
            }
            if (
                $calendar->monthCode($isoY, $isoM, $isoD) !== $monthCode
                || $calendar->day($isoY, $isoM, $isoD) !== $day
            ) {
                continue;
            }
            $epochDays = CalendarMath::toJulianDay($isoY, $isoM, $isoD);
            if ($best === null || $epochDays > $best[0]) {
                $best = [$epochDays, $isoM, $isoD];
            }
        }

        if ($best === null) {
            throw new RangeError("monthCode \"{$monthCode}\" with day {$day} does not exist in this calendar.");
        }

        return new self($best[1], $best[2], $calendarId, $referenceYear);
    }

    /**
     * Returns the ISO reference year for a chinese/dangi month code and day per
     * the normative chinese-dangi-iso-reference-years table, or null when the
     * applicable cell is blank ("—").
     *
     * Non-leap month codes M01–M12 use reference year 1972 for days 1–29; the
     * "Day 30" column differs for a handful of rows. Leap month codes use the
     * per-row reference year. M11L splits by day (1–10 → 2033, 11–29 → 2034),
     * and M03's day-30 reference year differs between chinese and dangi.
     */
    private static function chineseDangiReferenceYear(string $calendarId, string $monthCode, int $day): ?int
    {
        // "Day 30" column entries (days 1–29 default to 1972 for common months
        // and the leap-row value below). A blank entry is represented by null.
        if ($day === 30) {
            $day30 = [
                'M01' => 1970,
                'M02' => 1972,
                'M03' => $calendarId === 'dangi' ? 1968 : 1966,
                'M04' => 1970,
                'M05' => 1972,
                'M06' => 1971,
                'M07' => 1972,
                'M08' => 1971,
                'M09' => 1972,
                'M10' => 1972,
                'M11' => 1970,
                'M12' => 1972,
                'M01L' => null,
                'M02L' => null,
                'M03L' => 1955,
                'M04L' => 1944,
                'M05L' => 1952,
                'M06L' => 1941,
                'M07L' => 1938,
                'M08L' => null,
                'M09L' => null,
                'M10L' => null,
                'M11L' => null,
                'M12L' => null,
            ];

            return array_key_exists($monthCode, $day30) ? $day30[$monthCode] : null;
        }

        // Days 1–29 column.
        $days1to29 = [
            'M02L' => 1947,
            'M03L' => 1966,
            'M04L' => 1963,
            'M05L' => 1971,
            'M06L' => 1960,
            'M07L' => 1968,
            'M08L' => 1957,
            'M09L' => 2014,
            'M10L' => 1984,
            // M11L splits by day; handled below.
            'M01L' => null,
            'M12L' => null,
        ];

        if ($monthCode === 'M11L') {
            return $day <= 10 ? 2033 : 2034;
        }

        if (array_key_exists($monthCode, $days1to29)) {
            return $days1to29[$monthCode];
        }

        // Common month codes M01–M12: reference year 1972 for days 1–29.
        return 1972;
    }

    /**
     * Finds the latest ISO year at or before 1972 where the given calendar
     * monthCode+day exists, and returns a PlainMonthDay with that reference year.
     *
     * When overflow is 'constrain' and the exact day doesn't exist in any searched year,
     * the day is clamped to the maximum for that month across all searched years (so that
     * e.g. Coptic M13 day 7 constrains to 6 using a leap year, not 5 from a common year).
     * When overflow is 'reject', throws if the day exceeds every searched year's maximum.
     *
     * Searches backward from 1972 for up to 100 years to handle lunisolar
     * calendars (19-year Metonic cycle) and Islamic calendars (30-year cycle).
     *
     * @param Internal\Calendar\CalendarProtocol $calendar
     */
    private static function resolveNonIsoReferenceYear(
        Internal\Calendar\CalendarProtocol $calendar,
        ?string $calendarId,
        string $monthCode,
        int $day,
        string $overflow = 'constrain',
    ): self {
        // Chinese and dangi use a normative ISO reference-year table
        // (NonISOMonthDayToISOReferenceDate, chinese-dangi-iso-reference-years).
        // The generic backward search below only covers ISO years 1872–1972 and
        // therefore cannot find leap months whose reference year falls outside
        // that window (e.g. M09L→2014, M11L→2033/2034), nor distinguish the
        // chinese/dangi M03 day-30 difference. Resolve those via the table.
        if ($calendarId === 'chinese' || $calendarId === 'dangi') {
            return self::resolveChineseDangiReferenceYear($calendar, $calendarId, $monthCode, $day, $overflow);
        }

        // Phase 1: Try to find an exact match (the day fits without constraining).
        /** @var array{0: int, 1: int, 2: int}|null $bestMatch */
        $bestMatch = null;
        /** @var array<int, true> $triedCalYears */
        $triedCalYears = [];
        // Collect all candidate calendar years and their constrained days for phase 2.
        /** @var list<array{calYear: int, isoY: int, isoM: int, isoD: int, day: int}> $constrainedCandidates */
        $constrainedCandidates = [];

        for ($isoYear = 1972; $isoYear >= 1872; $isoYear--) {
            $calYearStart = $calendar->year($isoYear, 1, 1);
            $calYearEnd = $calendar->year($isoYear, 12, 31);

            $candidates = array_unique([$calYearEnd, $calYearStart]);
            rsort($candidates);

            foreach ($candidates as $tryCalYear) {
                if (array_key_exists($tryCalYear, $triedCalYears)) {
                    continue;
                }
                $triedCalYears[$tryCalYear] = true;

                // Try exact match first.
                try {
                    [$resIsoY, $resIsoM, $resIsoD] = $calendar->calendarToIsoFromMonthCode(
                        $tryCalYear,
                        $monthCode,
                        $day,
                        'reject',
                    );
                    if ($resIsoY > 1972) {
                        continue;
                    }
                    $rtMonthCode = $calendar->monthCode($resIsoY, $resIsoM, $resIsoD);
                    $rtDay = $calendar->day($resIsoY, $resIsoM, $resIsoD);
                    if ($rtMonthCode === $monthCode && $rtDay === $day) {
                        if ($bestMatch === null || $resIsoY > $bestMatch[0]) {
                            $bestMatch = [$resIsoY, $resIsoM, $resIsoD];
                        }
                    }
                } catch (RangeError $exactErr) {
                    // Exact day doesn't fit. Record the constrained result for phase 2.
                    unset($exactErr);
                    if ($overflow !== 'constrain') {
                        continue;
                    }
                    $candidate = self::tryConstrainedCandidate($calendar, $tryCalYear, $monthCode, $day);
                    if ($candidate !== null) {
                        $constrainedCandidates[] = $candidate;
                    }
                }
            }

            // If we found an exact match, return immediately.
            if ($bestMatch !== null) {
                return new self($bestMatch[1], $bestMatch[2], $calendarId, $bestMatch[0]);
            }
        }

        // Phase 2: No exact match. For constrain, find the candidate with the largest
        // constrained day, then resolve that day's reference year.
        if ($overflow === 'constrain' && $constrainedCandidates !== []) {
            // Find the maximum constrained day.
            $maxConstrainedDay = 0;
            foreach ($constrainedCandidates as $c) {
                if ($c['day'] <= $maxConstrainedDay) {
                    continue;
                }

                $maxConstrainedDay = $c['day'];
            }
            // Now find the reference year for the constrained monthCode+day.
            return self::resolveNonIsoReferenceYear($calendar, $calendarId, $monthCode, $maxConstrainedDay, 'reject');
        }

        // With 'reject' overflow, if no exact match was found, throw.
        if ($overflow === 'reject') {
            throw new RangeError("monthCode \"{$monthCode}\" with day {$day} does not exist in this calendar.");
        }

        // Fallback: should not normally be reached for supported calendars.
        $calYear = $calendar->year(1972, 7, 1);
        [$isoY, $isoM, $isoD] = $calendar->calendarToIsoFromMonthCode($calYear, $monthCode, $day, 'constrain');
        return new self($isoM, $isoD, $calendarId, $isoY);
    }

    #[\Override]
    protected function localeDefaultComponents(): LocaleComponents
    {
        return LocaleComponents::MonthDay;
    }

    #[\Override]
    protected function localeCalendarId(): string
    {
        return $this->calendarId;
    }

    #[\Override]
    protected function toLocaleTimestamp(): int
    {
        $dt = new \DateTime(
            sprintf('%04d-%02d-%02d 00:00:00', $this->referenceISOYear, $this->isoMonth, $this->isoDay),
            new \DateTimeZone('UTC'),
        );
        return $dt->getTimestamp();
    }
}
