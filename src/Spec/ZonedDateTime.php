<?php

declare(strict_types=1);

namespace Temporal\Spec;

use Stringable;
use Temporal\Exception\RangeError;
use Temporal\Exception\TypeError;
use Temporal\Spec\Internal\Calendar\CalendarFactory;
use Temporal\Spec\Internal\CalendarMath;
use Temporal\Spec\Internal\EpochLimits;
use Temporal\Spec\Internal\EpochRounding;
use Temporal\Spec\Internal\EpochValue;
use Temporal\Spec\Internal\FieldBag;
use Temporal\Spec\Internal\HasEpochParts;
use Temporal\Spec\Internal\IntlFormatter;
use Temporal\Spec\Internal\Options;
use Temporal\Spec\Internal\TemporalSerde;
use Temporal\Spec\Internal\TimeZoneHelper;
use Temporal\Spec\Internal\TimeZoneIdentity;
use Temporal\Spec\Internal\ZonedDateTimeArithmetic;
use Temporal\Spec\Internal\ZonedDateTimeDiff;
use Temporal\Spec\Internal\ZonedDateTimeFields;
use Temporal\Spec\Internal\ZonedDateTimeParse;

/**
 * A date-time anchored to a specific timezone and instant.
 *
 * Stores the number of nanoseconds since the Unix epoch alongside a timezone identifier
 * and a calendar identifier. Supported timezones: 'UTC', fixed-offset strings (±HH:MM),
 * and IANA names accepted by PHP's DateTimeZone.
 *
 * The value itself is that triple; every property is derived from it on demand and cached
 * as one local decomposition ({@see localComponents()}). What makes the type large is not
 * the value but the operations on it, because a ZonedDateTime is the only Temporal type
 * that holds an instant, a local reading, and the zone rule connecting them at once — and
 * a zone rule that makes the connection non-uniform, so the same nominal amount of time
 * means different things in the two coordinate systems. Those operations live in
 * `Internal\`, each owning one of them end to end:
 *
 *   {@see Internal\ZonedDateTimeParse}       ISO strings, including reconciling an inline
 *                                            offset against the bracketed zone
 *   {@see Internal\ZonedDateTimeFields}      property bags, for both from() and with()
 *   {@see Internal\ZonedDateTimeArithmetic}  add(), subtract(), round()
 *   {@see Internal\ZonedDateTimeDiff}        since(), until()
 *
 * They collaborate through the seams at the bottom of this class — the local
 * decomposition and the two factories — and are internal: this class is the public
 * surface.
 *
 * @psalm-api
 * @see https://tc39.es/proposal-temporal/#sec-temporal-zoneddatetime-objects
 */
final class ZonedDateTime implements Stringable
{
    use HasEpochParts;
    use TemporalSerde;

    private const int MS_PER_SECOND = 1_000;

    // -------------------------------------------------------------------------
    // Actual stored property
    // -------------------------------------------------------------------------

    /** @psalm-suppress PropertyNotSetInConstructor — set unconditionally in constructor */
    public readonly string $timeZoneId;

    /**
     * @psalm-suppress PropertyNotSetInConstructor — set unconditionally in constructor
     * @psalm-suppress PossiblyUnusedProperty — used from test262 scripts excluded from Psalm
     */
    public readonly string $calendarId;

    /** @var array{year:int, month:int<1,12>, day:int<1,31>, hour:int<0,23>, minute:int<0,59>, second:int<0,59>, millisecond:int<0,999>, microsecond:int<0,999>, nanosecond:int<0,999>, offsetSec:int, offset:string}|null $localCache */
    private ?array $localCache = null;

    /**
     * Canonical timezone ID for DateTimeZone operations (offset/transition lookups).
     *
     * Public so the Internal\ZonedDateTime* collaborators can resolve offsets against the
     * same zone this instance reads; it is not part of the TC39 surface.
     *
     * @internal
     * @psalm-internal Temporal\Spec
     */
    public readonly string $resolvedTimeZoneId;

    // -------------------------------------------------------------------------
    // Virtual (get-only) date/time properties
    // -------------------------------------------------------------------------

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $year {
        get {
            $c = $this->localComponents();
            return $this->calendarId === 'iso8601'
                ? $c['year']
                : CalendarFactory::get($this->calendarId)->year($c['year'], $c['month'], $c['day']);
        }
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int
     */
    public int $month {
        get {
            $c = $this->localComponents();
            return $this->calendarId === 'iso8601'
                ? $c['month']
                : CalendarFactory::get($this->calendarId)->month($c['year'], $c['month'], $c['day']);
        }
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int
     */
    public int $day {
        get {
            $c = $this->localComponents();
            return $this->calendarId === 'iso8601'
                ? $c['day']
                : CalendarFactory::get($this->calendarId)->day($c['year'], $c['month'], $c['day']);
        }
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int<0, 23>
     */
    public int $hour {
        get => $this->localComponents()['hour'];
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int<0, 59>
     */
    public int $minute {
        get => $this->localComponents()['minute'];
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int<0, 59>
     */
    public int $second {
        get => $this->localComponents()['second'];
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int<0, 999>
     */
    public int $millisecond {
        get => $this->localComponents()['millisecond'];
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int<0, 999>
     */
    public int $microsecond {
        get => $this->localComponents()['microsecond'];
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int<0, 999>
     */
    public int $nanosecond {
        get => $this->localComponents()['nanosecond'];
    }

    /**
     * Milliseconds since the Unix epoch (floor-divided from nanoseconds).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $epochMilliseconds {
        get {
            [$epochSec, $subNs] = $this->epochParts();
            return ($epochSec * self::MS_PER_SECOND) + intdiv($subNs, EpochLimits::NS_PER_MILLISECOND);
        }
    }

    /**
     * The UTC offset string for this instant in this timezone (e.g. '+05:30').
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public string $offset {
        get => $this->localComponents()['offset'];
    }

    /**
     * The UTC offset in nanoseconds for this instant in this timezone.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $offsetNanoseconds {
        get => $this->localComponents()['offsetSec'] * EpochLimits::NS_PER_SECOND;
    }

    // -------------------------------------------------------------------------
    // Virtual calendar properties
    // -------------------------------------------------------------------------

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public ?string $era {
        get {
            $c = $this->localComponents();
            return CalendarFactory::get($this->calendarId)->era($c['year'], $c['month'], $c['day']);
        }
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public ?int $eraYear {
        get {
            $c = $this->localComponents();
            return CalendarFactory::get($this->calendarId)->eraYear($c['year'], $c['month'], $c['day']);
        }
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public string $monthCode {
        get {
            $c = $this->localComponents();
            return CalendarFactory::get($this->calendarId)->monthCode($c['year'], $c['month'], $c['day']);
        }
    }

    /**
     * ISO 8601 day of week: 1 = Monday, 7 = Sunday.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int<1, 7>
     */
    public int $dayOfWeek {
        get {
            $c = $this->localComponents();
            return CalendarMath::isoWeekday($c['year'], $c['month'], $c['day']);
        }
    }

    /**
     * Ordinal day of the year: 1–366.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int
     */
    public int $dayOfYear {
        get {
            $c = $this->localComponents();
            return $this->calendarId === 'iso8601'
                ? CalendarMath::calcDayOfYear($c['year'], $c['month'], $c['day'])
                : CalendarFactory::get($this->calendarId)->dayOfYear($c['year'], $c['month'], $c['day']);
        }
    }

    /**
     * ISO 8601 week number: 1–53, or null for non-ISO calendars.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public ?int $weekOfYear {
        get {
            if ($this->calendarId !== 'iso8601') {
                return null;
            }
            $c = $this->localComponents();
            return CalendarMath::isoWeekInfo($c['year'], $c['month'], $c['day'])['week'];
        }
    }

    /**
     * ISO 8601 week-year, or null for non-ISO calendars.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public ?int $yearOfWeek {
        get {
            if ($this->calendarId !== 'iso8601') {
                return null;
            }
            $c = $this->localComponents();
            return CalendarMath::isoWeekInfo($c['year'], $c['month'], $c['day'])['year'];
        }
    }

    /**
     * Number of days in this date's month.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int
     */
    public int $daysInMonth {
        get {
            $c = $this->localComponents();
            return CalendarFactory::get($this->calendarId)->daysInMonth($c['year'], $c['month'], $c['day']);
        }
    }

    /**
     * Always 7 (ISO 8601 calendar).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int<7, 7>
     */
    public int $daysInWeek {
        get => 7;
    }

    /**
     * 365 or 366, depending on whether this date's year is a leap year.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int
     */
    public int $daysInYear {
        get {
            $c = $this->localComponents();
            return CalendarFactory::get($this->calendarId)->daysInYear($c['year'], $c['month'], $c['day']);
        }
    }

    /**
     * Always 12 (ISO 8601 calendar).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int
     */
    public int $monthsInYear {
        get {
            $c = $this->localComponents();
            return CalendarFactory::get($this->calendarId)->monthsInYear($c['year'], $c['month'], $c['day']);
        }
    }

    /**
     * True if this date's year is a leap year.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public bool $inLeapYear {
        get {
            $c = $this->localComponents();
            return CalendarFactory::get($this->calendarId)->inLeapYear($c['year'], $c['month'], $c['day']);
        }
    }

    /**
     * Number of hours in the current day (always 24 for UTC/fixed-offset timezones).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int|float $hoursInDay {
        get {
            // Compute the actual hours in the local day by finding
            // start-of-day for today and tomorrow, accounting for DST.
            $lc = $this->localComponents();
            $todayJdn = CalendarMath::toJulianDay($lc['year'], $lc['month'], $lc['day']);
            $todayEpochDays = $todayJdn - 2_440_588;
            $tomorrowEpochDays = $todayEpochDays + 1;

            $todayWallSec = $todayEpochDays * 86_400;
            $tomorrowWallSec = $tomorrowEpochDays * 86_400;

            $todayEpochSec = TimeZoneHelper::wallSecToEpochSecStartOfDay($todayWallSec, $this->resolvedTimeZoneId);
            $tomorrowEpochSec = TimeZoneHelper::wallSecToEpochSecStartOfDay(
                $tomorrowWallSec,
                $this->resolvedTimeZoneId,
            );

            // Spec (get hoursInDay steps 7-8): GetStartOfDay(today)/GetStartOfDay(tomorrow)
            // must throw when either boundary falls outside the representable range.
            if (
                abs($todayEpochSec) > EpochLimits::MAX_EPOCH_SECONDS
                || abs($tomorrowEpochSec) > EpochLimits::MAX_EPOCH_SECONDS
            ) {
                throw new RangeError('ZonedDateTime hoursInDay boundary is outside the representable range.');
            }

            $diffSec = $tomorrowEpochSec - $todayEpochSec;
            $hours = (float) $diffSec / 3600.0;

            // Return int when it's a whole number, float otherwise.
            return $hours === (float) (int) $hours ? (int) $hours : $hours;
        }
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param int|float|bool $epochNanoseconds Nanoseconds since the Unix epoch, as an
     *        integer (the PHP stand-in for a BigInt). TC39 coerces this argument with
     *        ToBigInt; ToBigInt(bool) = 0n/1n, so booleans are accepted and coerced.
     *        ToBigInt(Number) is a TypeError, so a PHP float is rejected.
     *        Over-int64 instants are built via {@see fromEpochParts()}.
     * @param string    $timeZoneId       Timezone identifier: 'UTC', '±HH:MM', or an IANA name.
     * @param string    $calendarId       Calendar identifier, e.g. 'iso8601' or 'hebrew'.
     * @throws TypeError if epochNanoseconds is a float.
     * @throws RangeError if the timezone is invalid.
     */
    public function __construct(int|float|bool $epochNanoseconds, string $timeZoneId, string $calendarId = 'iso8601')
    {
        // TC39 step 2: ToBigInt(epochNanoseconds). ToBigInt(bool) = 0n/1n, so PHP booleans
        // must be accepted and coerced. ToBigInt(Number) throws a TypeError, so a PHP float
        // (our Number stand-in) is rejected rather than truncated. An over-int64 instant is
        // constructed through fromEpochParts(), which carries the true epoch parts behind an
        // int sentinel without float-precision loss.
        if (is_bool($epochNanoseconds)) {
            $epochNanoseconds = (int) $epochNanoseconds;
        }
        if (is_float($epochNanoseconds)) {
            throw new TypeError('ZonedDateTime epochNanoseconds must be an integer, not a float.');
        }
        $this->epochNanoseconds = $epochNanoseconds;
        $this->timeZoneId = TimeZoneIdentity::normalize($timeZoneId, true);
        $this->calendarId = CalendarFactory::canonicalize($calendarId);
        $this->resolvedTimeZoneId = TimeZoneIdentity::canonicalId($this->timeZoneId);
    }

    // -------------------------------------------------------------------------
    // Static factory / comparison methods
    // -------------------------------------------------------------------------

    /**
     * Creates a ZonedDateTime from another ZonedDateTime, a ZDT ISO string,
     * or a property-bag array/object.
     *
     * String format: ISO datetime with REQUIRED bracket timezone annotation,
     * e.g. '2020-01-01T12:00:00+05:30[Asia/Kolkata]'.
     *
     * @param self|string|array<array-key, mixed>|object $item    ZonedDateTime, ISO string, or property-bag array/object.
     * @param array<array-key, mixed>|object|null $options Options array; supports 'disambiguation' (string).
     * @throws TypeError              for unsupported types.
     * @throws RangeError for invalid strings or property bags.
     * @psalm-api
     */
    public static function from(string|array|object $item, mixed $options = null): self
    {
        // Each branch of ToTemporalZonedDateTime reaches GetOptionsObject at a different
        // point, and the difference is observable on an options bag with accessors:
        // a string is PARSED first (so a malformed one is a RangeError before any option
        // is read), and a property bag is READ first (PrepareCalendarFields precedes
        // GetOptionsObject). Only the already-a-ZonedDateTime case reads options straight
        // away, having no fields to prepare.
        if ($item instanceof self) {
            self::validateFromOptions($options);
            return new self($item->epochNanoseconds, $item->timeZoneId, $item->calendarId);
        }
        if (is_string($item)) {
            // parseZdtString reaches GetOptionsObject only once the string has parsed.
            return ZonedDateTimeParse::parse($item, $options);
        }

        $bag = FieldBag::forCalendarType(
            $item,
            ZonedDateTimeFields::CALENDAR_FIELDS,
            ['offset', 'timeZone'],
            'ZonedDateTime',
        );
        $opts = self::validateFromOptions($options);

        $overflow = array_key_exists('overflow', $opts) && is_string($opts['overflow'])
            ? $opts['overflow']
            : 'constrain';
        $disambiguation = array_key_exists('disambiguation', $opts) && is_string($opts['disambiguation'])
            ? $opts['disambiguation']
            : 'compatible';
        $offsetOption = array_key_exists('offset', $opts) && is_string($opts['offset']) ? $opts['offset'] : 'reject';

        return ZonedDateTimeFields::fromBag($bag, $overflow, $disambiguation, $offsetOption);
    }

    /**
     * GetOptionsObject for from(): reads the three recognized options once, in the
     * spec's alphabetical order, and validates each keyword.
     *
     * Returns the snapshot with each keyword replaced by its coerced string, so callers
     * resolve values from it without touching the original bag — or re-running ToString
     * on a value that supplies it through an accessor — a second time.
     *
     * Shared with {@see Internal\ZonedDateTimeParse}, which reaches GetOptionsObject only
     * after the string has parsed.
     *
     * @return array<array-key, mixed>
     * @internal
     * @psalm-internal Temporal\Spec
     */
    public static function validateFromOptions(mixed $options): array
    {
        $opts = Options::normalizeOptions($options, ['disambiguation', 'offset', 'overflow']);

        if (array_key_exists('disambiguation', $opts)) {
            $dv = Options::coerceEnumOption($opts['disambiguation'], 'disambiguation');
            if (!in_array(needle: $dv, haystack: ['compatible', 'earlier', 'later', 'reject'], strict: true)) {
                throw new RangeError(
                    "Invalid disambiguation value \"{$dv}\"; must be 'compatible', 'earlier', 'later', or 'reject'.",
                );
            }
            $opts = array_merge($opts, ['disambiguation' => $dv]);
        }

        if (array_key_exists('overflow', $opts)) {
            $opts = array_merge($opts, ['overflow' => Options::overflowOption($opts['overflow'])]);
        }

        if (array_key_exists('offset', $opts)) {
            /** @var mixed $offOpt */
            $offOpt = $opts['offset'];
            if ($offOpt !== null) {
                $offOpt = Options::coerceEnumOption($offOpt, 'offset');
                if (!in_array(needle: $offOpt, haystack: ['use', 'ignore', 'prefer', 'reject'], strict: true)) {
                    throw new RangeError(
                        "Invalid offset option \"{$offOpt}\"; must be 'use', 'ignore', 'prefer', or 'reject'.",
                    );
                }
                $opts = array_merge($opts, ['offset' => $offOpt]);
            }
        }

        return $opts;
    }

    /**
     * Compares two ZonedDateTimes by their epoch nanoseconds.
     *
     * @param self|string|array<array-key, mixed>|object $one ZonedDateTime or value coercible via from().
     * @param self|string|array<array-key, mixed>|object $two ZonedDateTime or value coercible via from().
     * @return int -1, 0, or 1.
     * @psalm-api
     */
    public static function compare(string|array|object $one, string|array|object $two): int
    {
        $a = $one instanceof self ? $one : self::from($one);
        $b = $two instanceof self ? $two : self::from($two);
        return self::compareInstants($a, $b);
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Returns an Instant representing the same point in time.
     *
     * @psalm-api
     */
    public function toInstant(): Instant
    {
        [$epochSec, $subNs] = $this->epochParts();
        return Instant::fromEpochParts($epochSec, $subNs);
    }

    /**
     * Returns a PlainDate containing the local date in this timezone.
     *
     * @psalm-api
     */
    public function toPlainDate(): PlainDate
    {
        $c = $this->localComponents();
        return new PlainDate($c['year'], $c['month'], $c['day'], $this->calendarId);
    }

    /**
     * Returns a PlainTime containing the local time in this timezone.
     *
     * @psalm-api
     */
    public function toPlainTime(): PlainTime
    {
        return new PlainTime(
            $this->hour,
            $this->minute,
            $this->second,
            $this->millisecond,
            $this->microsecond,
            $this->nanosecond,
        );
    }

    /**
     * Returns a PlainDateTime containing the local date and time in this timezone.
     *
     * @psalm-api
     */
    public function toPlainDateTime(): PlainDateTime
    {
        $c = $this->localComponents();
        return new PlainDateTime(
            $c['year'],
            $c['month'],
            $c['day'],
            $c['hour'],
            $c['minute'],
            $c['second'],
            $c['millisecond'],
            $c['microsecond'],
            $c['nanosecond'],
            $this->calendarId,
        );
    }

    /**
     * Returns a new ZonedDateTime with a different timezone.
     *
     * The epoch nanoseconds remain the same; only the local time display changes.
     *
     * @throws TypeError              if $timeZone is not a string.
     * @throws RangeError if the timezone is invalid.
     * @psalm-api
     */
    public function withTimeZone(string $timeZone): self
    {
        // Normalize before constructing so datetime strings are accepted here
        // (the constructor rejects them with $rejectDatetimeStrings = true).
        $normalizedTz = TimeZoneIdentity::normalize($timeZone);
        [$epochSec, $subNs] = $this->epochParts();
        return self::fromEpochParts($epochSec, $subNs, $normalizedTz, $this->calendarId);
    }

    /**
     * Returns a new ZonedDateTime with a different calendar.
     *
     * The instant is unchanged; only the calendar the date fields are read through
     * differs. Identifiers are matched case-insensitively.
     *
     * @throws RangeError if an unsupported calendar is given.
     * @psalm-api
     */
    public function withCalendar(string $calendar): self
    {
        $calId = CalendarFactory::extractCalendarFromString($calendar);
        [$epochSec, $subNs] = $this->epochParts();
        return self::fromEpochParts($epochSec, $subNs, $this->timeZoneId, $calId);
    }

    /**
     * Returns a new ZonedDateTime with the time portion replaced.
     *
     * If $time is null the time is set to midnight (00:00:00).
     * Accepts PlainTime, null, a time string, or a property-bag array.
     *
     * @param PlainTime|string|array<array-key, mixed>|object|null $time PlainTime, null, string, or array.
     * @psalm-api
     */
    public function withPlainTime(string|array|object|null $time = null): self
    {
        // When called with no arguments, use startOfDay semantics (TC39 spec).
        // This handles cross-midnight DST gaps correctly.
        if ($time === null) {
            return $this->startOfDay();
        }
        if ($time instanceof PlainTime) {
            $h = $time->hour;
            $m = $time->minute;
            $s = $time->second;
            $ms = $time->millisecond;
            $us = $time->microsecond;
            $ns = $time->nanosecond;
        } else {
            $pt = PlainTime::from($time);
            $h = $pt->hour;
            $m = $pt->minute;
            $s = $pt->second;
            $ms = $pt->millisecond;
            $us = $pt->microsecond;
            $ns = $pt->nanosecond;
        }

        // Compute the local wall-clock seconds for the new datetime using the existing
        // ISO date. Build the wall seconds from the integer Julian-day count rather than
        // a sprintf'd ISO string: a 5-digit (extended) year would be silently mis-parsed
        // by DateTimeImmutable (e.g. 33658 → 2008), corrupting the date. This mirrors
        // startOfDay()/with(), which already use the toJulianDay path.
        $lc = $this->localComponents();
        $epochDays = CalendarMath::toJulianDay($lc['year'], $lc['month'], $lc['day']) - 2_440_588;
        $wallSec = ($epochDays * 86_400) + ($h * 3_600) + ($m * 60) + $s;

        // Determine the timezone offset at this new wall-clock second.
        // For a fixed offset timezone we can use it directly; for IANA we need
        // to do a wall-clock → UTC conversion.
        $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $this->resolvedTimeZoneId);

        $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;

        return self::fromEpochParts($epochSec, $subNs, $this->timeZoneId, $this->calendarId);
    }

    /**
     * Returns a new ZonedDateTime representing the start of this date's day
     * in the same timezone.
     *
     * For most timezones this is midnight (00:00:00), but DST transitions that
     * skip midnight may produce a different start-of-day time.
     *
     * @throws RangeError if the resulting epoch nanoseconds are out of range.
     * @psalm-api
     */
    public function startOfDay(): self
    {
        // Compute wall-clock midnight for the current local date.
        $lc = $this->localComponents();
        $epochDays = CalendarMath::toJulianDay($lc['year'], $lc['month'], $lc['day']) - 2_440_588;
        $wallSec = $epochDays * 86_400; // midnight in wall-clock seconds

        // For cross-midnight DST gaps (e.g., 1919-03-31 America/Toronto where
        // midnight doesn't exist), startOfDay should return the transition
        // epoch itself — the first valid instant of the day.
        $epochSec = TimeZoneHelper::wallSecToEpochSecStartOfDay($wallSec, $this->resolvedTimeZoneId);

        return self::fromEpochParts($epochSec, 0, $this->timeZoneId, $this->calendarId);
    }

    /**
     * Returns true if this ZonedDateTime represents the same instant, timezone, and calendar.
     *
     * @param self|string|array<array-key, mixed>|object $other ZonedDateTime, string, or array.
     * @throws TypeError for unsupported types.
     * @psalm-api
     */
    public function equals(string|array|object $other): bool
    {
        if (!$other instanceof self) {
            $other = self::from($other);
        }
        return (
            self::compareInstants($this, $other) === 0
            && TimeZoneIdentity::comparisonId($this->timeZoneId) === TimeZoneIdentity::comparisonId($other->timeZoneId)
            && $this->calendarId === $other->calendarId
        );
    }

    /**
     * Returns an ISO 8601 representation with timezone and calendar annotations.
     *
     * Options (all optional):
     *   - fractionalSecondDigits: 'auto' (default) | 0–9
     *   - offset: 'auto' (default, include offset) | 'never' (omit offset)
     *   - timeZoneName: 'auto' (default, include name) | 'never' | 'critical'
     *   - calendarName: 'auto' (default, omit for iso8601) | 'always' | 'never' | 'critical'
     *
     * @param array<array-key, mixed>|object|null $options null, array, or object (treated as empty bag).
     * @throws TypeError              if option values have wrong types.
     * @throws RangeError if option values are invalid strings.
     * @psalm-api
     */
    #[\Override]
    public function toString(mixed $options = null): string
    {
        $options = Options::normalizeOptions($options, [
            'calendarName',
            'fractionalSecondDigits',
            'offset',
            'roundingMode',
            'smallestUnit',
            'timeZoneName',
        ]);

        $digits = -2; // -2 = 'auto'
        $offsetMode = 'auto';
        $tzNameMode = 'auto';
        $calendarName = 'auto';
        $isMinute = false;
        $roundMode = 'trunc';

        if (array_key_exists('fractionalSecondDigits', $options)) {
            $fsd = Options::fractionalSecondDigits($options['fractionalSecondDigits']);
            if ($fsd !== null) {
                $digits = $fsd;
            }
        }

        // smallestUnit overrides fractionalSecondDigits.
        if (array_key_exists('smallestUnit', $options) && $options['smallestUnit'] !== null) {
            $su = Options::coerceEnumOption($options['smallestUnit'], 'smallestUnit');
            [$digits, $isMinute] = match ($su) {
                'minute', 'minutes' => [-1, true],
                'second', 'seconds' => [0, false],
                'millisecond', 'milliseconds' => [3, false],
                'microsecond', 'microseconds' => [6, false],
                'nanosecond', 'nanoseconds' => [9, false],
                default => throw new RangeError("Invalid smallestUnit \"{$su}\"."),
            };
        }

        // roundingMode (default 'trunc').
        if (array_key_exists('roundingMode', $options) && $options['roundingMode'] !== null) {
            $rm = Options::coerceEnumOption($options['roundingMode'], 'roundingMode');
            $roundMode = $rm;
        }

        if (array_key_exists('offset', $options)) {
            $ov = Options::coerceEnumOption($options['offset'], 'offset');
            if ($ov !== 'auto' && $ov !== 'never') {
                throw new RangeError("Invalid offset option \"{$ov}\"; must be 'auto' or 'never'.");
            }
            $offsetMode = $ov;
        }

        if (array_key_exists('timeZoneName', $options)) {
            $tzn = Options::coerceEnumOption($options['timeZoneName'], 'timeZoneName');
            if ($tzn !== 'auto' && $tzn !== 'never' && $tzn !== 'critical') {
                throw new RangeError("Invalid timeZoneName option \"{$tzn}\".");
            }
            $tzNameMode = $tzn;
        }

        if (array_key_exists('calendarName', $options)) {
            $cn = Options::coerceEnumOption($options['calendarName'], 'calendarName');
            if ($cn !== 'auto' && $cn !== 'always' && $cn !== 'never' && $cn !== 'critical') {
                throw new RangeError("Invalid calendarName value: \"{$cn}\".");
            }
            $calendarName = $cn;
        }

        // Compute rounding increment in nanoseconds.
        if ($isMinute) {
            $increment = 60_000_000_000;
        } else {
            $increment = match ($digits) {
                0 => 1_000_000_000,
                1 => 100_000_000,
                2 => 10_000_000,
                3 => 1_000_000,
                4 => 100_000,
                5 => 10_000,
                6 => 1_000,
                7 => 100,
                8 => 10,
                default => 1,
            };
        }

        // Round using RoundNumberToIncrementAsIfPositive, operating on the TRUE
        // epoch parts (sentinel-aware) so out-of-int64 instants render their real
        // calendar year, matching toLocaleString(). Rounding is decomposed into
        // (epochSec, subNs) to avoid int64 overflow on the combined nanosecond value.
        [$trueSec, $trueSubNs] = $this->epochParts();
        [$epochSec, $roundedSubNs] = EpochRounding::round($trueSec, $trueSubNs, $increment, $roundMode);

        $offsetSec = TimeZoneHelper::offsetSecondsAt($this->resolvedTimeZoneId, $epochSec);
        $localSec = $epochSec + $offsetSec;
        $dt = new \DateTimeImmutable(sprintf('@%d', $localSec));

        $year = (int) $dt->format('Y');
        $month = (int) $dt->format('n');
        $day = (int) $dt->format('j');
        $hour = (int) $dt->format('G');
        $min = (int) $dt->format('i');
        $sec = (int) $dt->format('s');

        $ms = intdiv(num1: $roundedSubNs, num2: EpochLimits::NS_PER_MILLISECOND);
        $us = intdiv(num1: $roundedSubNs % EpochLimits::NS_PER_MILLISECOND, num2: EpochLimits::NS_PER_MICROSECOND);
        $ns = $roundedSubNs % EpochLimits::NS_PER_MICROSECOND;

        // Build offset string: ±HH:MM (rounded to minutes per FormatUTCOffsetRounded).
        // TC39 toString() uses FormatUTCOffsetRounded, which applies halfExpand
        // rounding of offset nanoseconds to the nearest minute, then formats ±HH:MM.
        $absOffsetNs = abs($offsetSec) * EpochLimits::NS_PER_SECOND;
        $totalMinutes = intdiv($absOffsetNs + 30_000_000_000, num2: 60_000_000_000);
        $offH = intdiv($totalMinutes, num2: 60);
        $offM = $totalMinutes % 60;
        $offSign = $offsetSec >= 0 ? '+' : '-';
        $offsetStr = sprintf('%s%02d:%02d', $offSign, $offH, $offM);

        // Year formatting: normal 4-digit, extended ±YYYYYY for out-of-range.
        if ($year < 0) {
            $yearStr = sprintf('-%06d', abs($year));
        } elseif ($year > 9999) {
            $yearStr = sprintf('+%06d', $year);
        } else {
            $yearStr = sprintf('%04d', $year);
        }

        $datePart = sprintf('%s-%02d-%02d', $yearStr, $month, $day);

        // Sub-second nanoseconds: ms * 1e6 + us * 1e3 + ns
        $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;

        if ($isMinute) {
            $timePart = sprintf('%02d:%02d', $hour, $min);
        } elseif ($digits === -2) {
            // 'auto': strip trailing zeros.
            if ($subNs === 0) {
                $timePart = sprintf('%02d:%02d:%02d', $hour, $min, $sec);
            } else {
                $fraction = rtrim(sprintf('%09d', $subNs), characters: '0');
                $timePart = sprintf('%02d:%02d:%02d.%s', $hour, $min, $sec, $fraction);
            }
        } elseif ($digits === 0) {
            $timePart = sprintf('%02d:%02d:%02d', $hour, $min, $sec);
        } else {
            $fraction = substr(string: sprintf('%09d', $subNs), offset: 0, length: $digits);
            $timePart = sprintf('%02d:%02d:%02d.%s', $hour, $min, $sec, $fraction);
        }

        $result = sprintf('%sT%s', $datePart, $timePart);

        if ($offsetMode !== 'never') {
            $result .= $offsetStr;
        }

        if ($tzNameMode !== 'never') {
            if ($tzNameMode === 'critical') {
                $result .= sprintf('[!%s]', $this->timeZoneId);
            } else {
                $result .= sprintf('[%s]', $this->timeZoneId);
            }
        }

        if ($calendarName === 'always') {
            $result .= sprintf('[u-ca=%s]', $this->calendarId);
        } elseif ($calendarName === 'critical') {
            $result .= sprintf('[!u-ca=%s]', $this->calendarId);
        } elseif ($calendarName === 'auto' && $this->calendarId !== 'iso8601') {
            $result .= sprintf('[u-ca=%s]', $this->calendarId);
        }
        // 'never': omit calendar annotation entirely.

        return $result;
    }

    /**
     * Returns a locale-sensitive string for this ZonedDateTime using IntlDateFormatter.
     *
     * Supports a subset of Intl.DateTimeFormat options:
     *   - dateStyle: "full" | "long" | "medium" | "short"
     *   - timeStyle: "full" | "long" | "medium" | "short"
     *   - timeZone: IANA timezone string (defaults to this ZonedDateTime's timezone)
     *   - calendar: calendar identifier appended as u-ca locale extension
     *
     * @param string|array<array-key, mixed>|null $locales BCP 47 locale string or array of strings.
     * @param array<array-key, mixed>|object|null $options Intl.DateTimeFormat options array.
     * @psalm-api
     */
    public function toLocaleString(string|array|null $locales = null, array|object|null $options = null): string
    {
        if ($options === null) {
            $opts = [];
        } else {
            $opts = Options::bagSnapshot($options, IntlFormatter::OPTION_NAMES);
        }
        /** @psalm-var array<string, mixed> $opts */

        // TC39: timeZone option is disallowed for ZonedDateTime.toLocaleString.
        if (array_key_exists('timeZone', $opts) && $opts['timeZone'] !== null) {
            throw new TypeError('toLocaleString(): timeZone option is not allowed for ZonedDateTime.');
        }

        $locale = IntlFormatter::resolveLocale($locales);
        IntlFormatter::validateCalendar($this->calendarId, $locale, $opts, defaultComponents: 'datetime');

        $timeZone = $this->timeZoneId;
        $opts['_locale'] = $locale;

        // TC39: ZDT's default format includes the timezone name.
        if (
            !array_key_exists('timeZoneName', $opts)
            && !array_key_exists('dateStyle', $opts)
            && !array_key_exists('timeStyle', $opts)
        ) {
            $opts['timeZoneName'] = 'short';
        }

        // Validate style + component conflicts
        IntlFormatter::validateStyleConflicts($opts);

        $formatter = IntlFormatter::buildIntlFormatter($locale, $timeZone, $opts, 'datetime');
        [$epochSec] = $this->epochParts();
        $result = $formatter->format($epochSec);

        return $result !== false ? $result : $this->toString();
    }

    // -------------------------------------------------------------------------
    // Arithmetic methods
    // -------------------------------------------------------------------------

    /**
     * Returns a new ZonedDateTime with the given duration added.
     *
     * Calendar units (years/months) modify local date fields and re-resolve to ZDT.
     * Time units add nanoseconds directly to the epoch.
     *
     * @param Duration|string|array<array-key, mixed>|object $duration Duration, ISO 8601 duration string, or property-bag array.
     * @param array<array-key, mixed>|object|null $options Options array; supports 'overflow' ('constrain'|'reject').
     * @psalm-api
     */
    public function add(string|array|object $duration, mixed $options = null): self
    {
        $dur = $duration instanceof Duration ? $duration : Duration::from($duration);
        return ZonedDateTimeArithmetic::addDuration($this, 1, $dur, $options);
    }

    /**
     * Returns a new ZonedDateTime with the given duration subtracted.
     *
     * @param Duration|string|array<array-key,mixed>|object $duration Duration, ISO 8601 duration string, or property-bag array.
     * @param array<array-key, mixed>|object|null $options Options array; supports 'overflow' ('constrain'|'reject').
     * @psalm-api
     */
    public function subtract(string|array|object $duration, mixed $options = null): self
    {
        $dur = $duration instanceof Duration ? $duration : Duration::from($duration);
        return ZonedDateTimeArithmetic::addDuration($this, -1, $dur, $options);
    }

    /**
     * Returns the Duration from $other to this ZonedDateTime (this - other).
     *
     * Default largestUnit is 'hour' (per TC39 ZonedDateTime spec).
     *
     * @param self|string|array<array-key, mixed>|object $other   ZonedDateTime or ZDT string.
     * @param array<array-key, mixed>|object|null $options Options array with largestUnit, smallestUnit, roundingMode, roundingIncrement.
     * @psalm-api
     */
    public function since(string|array|object $other, mixed $options = null): Duration
    {
        $o = $other instanceof self ? $other : self::from($other);
        if ($this->calendarId !== $o->calendarId) {
            throw new RangeError(
                "Cannot compute since() between different calendars: \"{$this->calendarId}\" and \"{$o->calendarId}\".",
            );
        }
        return ZonedDateTimeDiff::between($this, $o, 'since', $options);
    }

    /**
     * Returns the Duration from this ZonedDateTime to $other (other - this).
     *
     * Default largestUnit is 'hour' (per TC39 ZonedDateTime spec).
     *
     * @param self|string|array<array-key, mixed>|object $other   ZonedDateTime or ZDT string.
     * @param array<array-key, mixed>|object|null $options Options array with largestUnit, smallestUnit, roundingMode, roundingIncrement.
     * @psalm-api
     */
    public function until(string|array|object $other, mixed $options = null): Duration
    {
        $o = $other instanceof self ? $other : self::from($other);
        if ($this->calendarId !== $o->calendarId) {
            throw new RangeError(
                "Cannot compute until() between different calendars: \"{$this->calendarId}\" and \"{$o->calendarId}\".",
            );
        }
        return ZonedDateTimeDiff::between($this, $o, 'until', $options);
    }

    /**
     * Returns a new ZonedDateTime rounded to the given unit and increment.
     *
     * For 'day': rounds relative to local midnight in the timezone.
     * For sub-day units: rounds the epoch nanoseconds directly.
     *
     * @param string|array<array-key, mixed>|object $options string smallestUnit or array with keys:
     *   - smallestUnit (required): 'day'|'hour'|'minute'|'second'|'millisecond'|'microsecond'|'nanosecond'
     *   - roundingMode (default 'halfExpand')
     *   - roundingIncrement (default 1)
     * @psalm-api
     */
    public function round(string|array|object $options): self
    {
        return ZonedDateTimeArithmetic::round($this, $options);
    }

    /**
     * Returns a new ZonedDateTime with the specified fields overridden.
     *
     * @param array<array-key,mixed>|object $fields   Property bag with fields to override.
     * @param array<array-key, mixed>|object|null       $options Options bag: ['overflow' => ..., 'disambiguation' => ...]
     * @psalm-api
     */
    public function with(array|object $fields, mixed $options = null): self
    {
        return ZonedDateTimeFields::with($this, $fields, $options);
    }

    /**
     * Finds the next or previous DST transition relative to this instant.
     *
     * Returns null for fixed-offset timezones (UTC, ±HH:MM).
     *
     * @param mixed $direction 'next' or 'previous', or an array/object with a 'direction' key.
     * @psalm-api
     */
    public function getTimeZoneTransition(mixed $direction): ?self
    {
        // Spec: directionParam must be a String, or be passed to GetOptionsObject (a
        // real options Object). Accept only those valid shapes — a string, an options
        // array, or a non-Stringable object — and reject everything else with a single
        // TypeError. This covers two cases that both fail GetOptionsObject:
        //   - a Symbol (JsSymbol sentinel, which is \Stringable): caught here BEFORE
        //     get_object_vars() (which would silently yield an empty bag and mis-route
        //     to a RangeError), so it surfaces as TypeError, not RangeError;
        //   - any other non-Object, non-undefined primitive (number/bigint/boolean/null):
        //     routed through a spec-layer TypeError rather than letting PHP's parameter-
        //     type guard fire (which the test262 runner treats as a representational
        //     artifact, not a faithful TC39 TypeError).
        if (is_string($direction)) {
            $dir = $direction;
        } else {
            if (is_array($direction)) {
                $bag = $direction;
            } elseif (is_object($direction) && !$direction instanceof \Stringable) {
                $bag = Options::bagSnapshot($direction, ['direction']);
            } else {
                // Anything else fails GetOptionsObject and surfaces as a single
                // TypeError.
                throw new TypeError(
                    'ZonedDateTime::getTimeZoneTransition() direction argument must be a string or options object.',
                );
            }
            $dir = null;
            if (array_key_exists('direction', $bag)) {
                $dir = Options::coerceEnumOption($bag['direction'], 'direction');
            }
            if ($dir === null) {
                throw new RangeError(
                    "ZonedDateTime::getTimeZoneTransition() requires a valid 'direction' option ('next' or 'previous').",
                );
            }
        }

        if ($dir !== 'next' && $dir !== 'previous') {
            throw new RangeError("Invalid direction \"{$dir}\": must be 'next' or 'previous'.");
        }

        // Sentinel-aware: derive the transition search anchor from the true epoch
        // parts, not the clamped epochNanoseconds field.
        [$epochSec, $subNs] = $this->epochParts();
        $ts = TimeZoneHelper::findTransition($this->resolvedTimeZoneId, $epochSec, $subNs, $dir);
        if ($ts === null) {
            return null;
        }

        // A transition whose whole-second nanoseconds would overflow the int64
        // epochNanoseconds field is not representable: the field would clamp to
        // PHP_INT_MAX/MIN and become indistinguishable from the anchor, so per spec there
        // is no in-range transition in that direction. The bound is the bare int64 field
        // limit — a whole-second transition carries no sub-second remainder — not the
        // spec-max instant in seconds.
        if (abs($ts) > EpochLimits::MAX_EPOCH_SECONDS_FOR_INT64_NS_FIELD) {
            return null;
        }
        return new self($ts * EpochLimits::NS_PER_SECOND, $this->timeZoneId, $this->calendarId);
    }

    // -------------------------------------------------------------------------
    // Representation seams
    //
    // The two directions between an instant and a local reading, plus the
    // factories the Internal\ZonedDateTime* collaborators build results through.
    // -------------------------------------------------------------------------

    /**
     * Compares two ZonedDateTimes by their true epoch instant, handling sentinels.
     *
     * @return int -1, 0, or 1
     */
    private static function compareInstants(self $a, self $b): int
    {
        [$aSec, $aSubNs] = $a->epochParts();
        [$bSec, $bSubNs] = $b->epochParts();
        $cmp = $aSec <=> $bSec;
        return $cmp !== 0 ? $cmp : $aSubNs <=> $bSubNs;
    }

    /**
     * Computes (and caches) all local date/time components for this instant in the stored timezone.
     *
     * Public so the Internal\ZonedDateTime* collaborators can read the same cached
     * decomposition the property hooks do, rather than each recomputing it; it is not part
     * of the TC39 surface.
     *
     * @return array{year:int, month:int<1,12>, day:int<1,31>, hour:int<0,23>, minute:int<0,59>, second:int<0,59>, millisecond:int<0,999>, microsecond:int<0,999>, nanosecond:int<0,999>, offsetSec:int, offset:string}
     * @internal
     * @psalm-internal Temporal\Spec
     * @psalm-suppress UnusedMethod — called from PHP 8.4 property hooks that Psalm does not track
     */
    public function localComponents(): array
    {
        if ($this->localCache !== null) {
            return $this->localCache;
        }

        // Use stored true epoch parts when available (sentinel values).
        [$epochSec, $subNs] = $this->epochParts();

        $offsetSec = TimeZoneHelper::offsetSecondsAt($this->resolvedTimeZoneId, $epochSec);
        $localSec = $epochSec + $offsetSec;

        // Split seconds-since-epoch into whole days + remainder, floor-divided
        // so negative local seconds produce the correct pre-1970 date. The JDN
        // roundtrip hits CalendarMath's cached fromJulianDay — cheaper than a
        // DateTimeImmutable + 6 format() calls.
        $localDay = CalendarMath::floorDiv($localSec, 86_400);
        $timeOfDaySec = $localSec - ($localDay * 86_400);
        [$year, $month, $day] = CalendarMath::fromJulianDay($localDay + 2_440_588);
        /** @var int<0, 23> $hour */
        $hour = intdiv(num1: $timeOfDaySec, num2: 3600);
        $rem = $timeOfDaySec - ($hour * 3600);
        /** @var int<0, 59> $minute */
        $minute = intdiv(num1: $rem, num2: 60);
        /** @var int<0, 59> $second */
        $second = $rem - ($minute * 60);

        /** @var int<0, 999> $ms — $subNs < 10^9, dividing by 10^6 gives 0–999 */
        $ms = intdiv(num1: $subNs, num2: EpochLimits::NS_PER_MILLISECOND);
        /** @var int<0, 999> $us — remainder mod 10^6 / 10^3 gives 0–999 */
        $us = intdiv(num1: $subNs % EpochLimits::NS_PER_MILLISECOND, num2: EpochLimits::NS_PER_MICROSECOND);
        /** @var int<0, 999> $ns — remainder mod 10^3 gives 0–999 */
        $ns = $subNs % EpochLimits::NS_PER_MICROSECOND;

        // Build offset string: ±HH:MM or ±HH:MM:SS when seconds are non-zero.
        $absOffsetSec = abs($offsetSec);
        $offH = intdiv(num1: $absOffsetSec, num2: 3600);
        $offM = intdiv(num1: $absOffsetSec % 3600, num2: 60);
        $offS = $absOffsetSec % 60;
        $offSign = $offsetSec >= 0 ? '+' : '-';
        $offsetStr = $offS !== 0
            ? sprintf('%s%02d:%02d:%02d', $offSign, $offH, $offM, $offS)
            : sprintf('%s%02d:%02d', $offSign, $offH, $offM);

        $this->localCache = [
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'hour' => $hour,
            'minute' => $minute,
            'second' => $second,
            'millisecond' => $ms,
            'microsecond' => $us,
            'nanosecond' => $ns,
            'offsetSec' => $offsetSec,
            'offset' => $offsetStr,
        ];

        return $this->localCache;
    }

    /**
     * Builds a ZonedDateTime from local (wall-clock) date and time components.
     *
     * The counterpart to {@see localComponents()}, and the seam every collaborator that
     * has resolved a local reading — `with()`, calendar-unit arithmetic — returns through.
     * Epoch-day arithmetic goes through the Julian day number rather than
     * DateTimeImmutable, which cannot represent years beyond ~9999 or negative years
     * reliably.
     *
     * @param string $disambiguation 'compatible', 'earlier', 'later', or 'reject'.
     * @internal
     * @psalm-internal Temporal\Spec
     */
    public static function fromLocalParts(
        int $year,
        int $month,
        int $day,
        int $h,
        int $min,
        int $sec,
        int $ms,
        int $us,
        int $ns,
        string $tzId,
        string $calendarId,
        string $disambiguation,
    ): self {
        // Compute wall-clock seconds from JDN to handle extreme years.
        $epochDays = CalendarMath::toJulianDay($year, $month, $day) - 2_440_588;
        $wallSec = ($epochDays * 86_400) + ($h * 3600) + ($min * 60) + $sec;
        $resolvedTzId = TimeZoneIdentity::canonicalId($tzId);
        $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);

        $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;

        return self::fromEpochParts($epochSec, $subNs, $tzId, $calendarId);
    }

    /**
     * Creates a ZonedDateTime from UTC epoch seconds and sub-second nanoseconds.
     *
     * The internal seam every sibling that holds decomposed epoch parts builds through —
     * `Instant::toZonedDateTimeISO()`, `PlainDate`/`PlainDateTime::toZonedDateTime()`, and
     * this class's own arithmetic — so no caller has to re-encode through an int64
     * nanosecond intermediate, which overflows near the ISO range boundary. int64 overflow
     * is handled here by storing a sentinel epochNanoseconds value while preserving the
     * true epoch seconds for later decomposition in {@see localComponents()}.
     *
     * $epochSec/$subNs accept int|float and are narrowed by
     * {@see EpochValue::narrowParts()}, which documents where float parts come from.
     *
     * @internal
     * @psalm-internal Temporal\Spec
     * @throws RangeError if the result is outside the representable range.
     */
    public static function fromEpochParts(
        int|float $epochSec,
        int|float $subNs,
        string $tzId,
        string $calendarId = 'iso8601',
    ): self {
        $parts = EpochValue::narrowParts($epochSec, $subNs);
        if ($parts === null) {
            throw new RangeError('ZonedDateTime arithmetic result is outside the representable range.');
        }
        [$epochSec, $subNs] = $parts;

        // Range check (message is ZonedDateTime-specific, so it stays here; the
        // int64-fit / sentinel packing is shared via EpochValue::fromParts()).
        $absEpochSec = abs($epochSec);
        if (
            $absEpochSec > EpochLimits::MAX_EPOCH_SECONDS
            || $absEpochSec === EpochLimits::MAX_EPOCH_SECONDS && $subNs > 0
        ) {
            throw new RangeError('ZonedDateTime arithmetic result is outside the representable range.');
        }

        // When the full nanosecond value fits int64, pack it exactly; otherwise the
        // public field clamps to a sentinel and the true parts are carried (the
        // fits-int64 case returns the null/0 defaults already on the object).
        $epoch = EpochValue::fromParts($epochSec, $subNs);
        $zdt = new self($epoch->epochNanoseconds, $tzId, $calendarId);
        $zdt->applyEpoch($epoch);
        return $zdt;
    }

    #[\Override]
    protected function localeDefaultComponents(): string
    {
        return 'datetime';
    }

    #[\Override]
    protected function localeIsDateOnly(): bool
    {
        return false;
    }

    #[\Override]
    protected function localeIsTimeOnly(): bool
    {
        return false;
    }

    #[\Override]
    protected function localeCalendarId(): string
    {
        return $this->calendarId;
    }

    #[\Override]
    protected function toLocaleTimestamp(): int
    {
        // ZonedDateTime has its own toLocaleString implementation and does not
        // rely on the trait's default formatter path; this value is not consumed
        // by that override, but the trait contract requires it.
        [$epochSec] = $this->epochParts();
        return $epochSec;
    }
}
