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
use Temporal\Spec\Internal\HasEpochParts;
use Temporal\Spec\Internal\IntlFormatter;
use Temporal\Spec\Internal\Options;
use Temporal\Spec\Internal\TemporalSerde;
use Temporal\Spec\Internal\TimeZoneHelper;

use function assert;

/**
 * A date-time anchored to a specific timezone and instant.
 *
 * Stores the number of nanoseconds since the Unix epoch alongside a timezone
 * identifier and calendar identifier. Only the ISO 8601 calendar is supported.
 * Supported timezones: 'UTC', fixed-offset strings (±HH:MM), and IANA names
 * accepted by PHP's DateTimeZone.
 *
 * @psalm-api
 * @see https://tc39.es/proposal-temporal/#sec-temporal-zoneddatetime-objects
 */
final class ZonedDateTime implements Stringable
{
    use HasEpochParts;
    use TemporalSerde;

    private const int MS_PER_SECOND = 1_000;

    // Known ICU inconsistencies where an IANA link target is reported as
    // self-canonical instead of resolving to its true primary zone.
    /** @var array<non-empty-string, non-empty-string> */
    private const array ICU_FIXUPS = [
        'Antarctica/McMurdo' => 'Pacific/Auckland',
        'Antarctica/South_Pole' => 'Antarctica/McMurdo',
        'Asia/Choibalsan' => 'Asia/Ulaanbaatar',
        'CET' => 'Europe/Brussels',
        'CST6CDT' => 'America/Chicago',
        'EET' => 'Europe/Athens',
        'EST' => 'America/Panama',
        'EST5EDT' => 'America/New_York',
        'HST' => 'Pacific/Honolulu',
        'MET' => 'Europe/Brussels',
        'MST' => 'America/Phoenix',
        'MST7MDT' => 'America/Denver',
        'PST8PDT' => 'America/Los_Angeles',
        'WET' => 'Europe/Lisbon',
    ];

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

    /** Canonical timezone ID for DateTimeZone operations (offset/transition lookups). */
    private readonly string $resolvedTimeZoneId;

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
     * @param string    $calendarId       Calendar identifier (only 'iso8601' is supported).
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
        $this->timeZoneId = TimeZoneHelper::normalizeTimezoneId($timeZoneId, true);
        $this->calendarId = CalendarFactory::canonicalize($calendarId);
        $this->resolvedTimeZoneId = self::resolveCanonicalTimezoneId($this->timeZoneId);
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
    public static function from(string|array|object $item, array|object|null $options = null): self
    {
        $opts = Options::normalizeOptions($options);

        // Validate 'disambiguation' option if present.
        if (array_key_exists('disambiguation', $opts)) {
            $dv = Options::coerceEnumOption($opts['disambiguation'], 'disambiguation');
            if (!in_array(needle: $dv, haystack: ['compatible', 'earlier', 'later', 'reject'], strict: true)) {
                throw new RangeError(
                    "Invalid disambiguation value \"{$dv}\"; must be 'compatible', 'earlier', 'later', or 'reject'.",
                );
            }
        }

        // Validate 'overflow' option.
        $overflow = 'constrain';
        if (array_key_exists('overflow', $opts)) {
            $overflow = Options::overflowOption($opts['overflow']);
        }

        // Validate 'offset' option if present.
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
            }
        }

        if ($item instanceof self) {
            return new self($item->epochNanoseconds, $item->timeZoneId, $item->calendarId);
        }
        if (is_string($item)) {
            return self::parseZdtString($item, $options);
        }
        $disambiguation = 'compatible';
        if (array_key_exists('disambiguation', $opts) && is_string($opts['disambiguation'])) {
            $disambiguation = $opts['disambiguation'];
        }
        $offsetOption = 'reject';
        if (array_key_exists('offset', $opts) && is_string($opts['offset'])) {
            $offsetOption = $opts['offset'];
        }
        $bag = is_object($item) ? get_object_vars($item) : $item;
        return self::fromPropertyBag($bag, $overflow, $disambiguation, $offsetOption);
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
        $normalizedTz = TimeZoneHelper::normalizeTimezoneId($timeZone);
        [$epochSec, $subNs] = $this->epochParts();
        return self::fromEpochParts($epochSec, $subNs, $normalizedTz, $this->calendarId);
    }

    /**
     * Returns a new ZonedDateTime with a different calendar.
     *
     * Only 'iso8601' is supported (case-insensitive).
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

        return self::createFromEpochParts($epochSec, 0, $this->timeZoneId, $this->calendarId);
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
            && self::canonicalizeTimezoneForComparison($this->timeZoneId) === self::canonicalizeTimezoneForComparison($other->timeZoneId)
            && $this->calendarId === $other->calendarId
        );
    }

    /**
     * Returns a comparison key for a timezone ID used by ZonedDateTime::equals().
     *
     * Per the TC39 Temporal spec, equals() compares timezones by canonical
     * primary identifier via ICU — so IANA aliases like Asia/Calcutta and
     * Asia/Kolkata compare equal, but two distinct canonical IDs that happen
     * to share offset rules (for example Antarctica/McMurdo and
     * Pacific/Auckland) are NOT equal.
     *
     * UTC-equivalent aliases and the '+00:00' / '-00:00' fixed offsets are
     * folded to 'UTC' so they compare equal.
     *
     * This path deliberately does NOT apply the McMurdo → Auckland ICU fixup
     * used by resolveCanonicalTimezoneId() for offset resolution, because
     * those IDs are distinct canonical primaries per IANA.
     */
    private static function canonicalizeTimezoneForComparison(string $id): string
    {
        /** @var array<string, string> $cache */
        static $cache = [];
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        return $cache[$id] = self::canonicalizeTimezoneForComparisonUncached($id);
    }

    private static function canonicalizeTimezoneForComparisonUncached(string $id): string
    {
        // UTC aliases all compare equal.
        /** @var list<string> $utcAliases */
        static $utcAliases = [
            'etc/utc',
            'etc/gmt',
            'etc/gmt+0',
            'etc/gmt-0',
            'etc/gmt0',
            'etc/greenwich',
            'etc/uct',
            'etc/universal',
            'etc/zulu',
            'gmt',
            'gmt+0',
            'gmt-0',
            'gmt0',
            'greenwich',
            'uct',
            'universal',
            'zulu',
            'utc',
        ];
        $lower = strtolower($id);
        if (in_array($lower, $utcAliases, strict: true)) {
            return 'UTC';
        }
        // Fixed offset +00:00 and -00:00 are equivalent to UTC.
        if ($id === '+00:00' || $id === '-00:00') {
            return 'UTC';
        }
        // Case-fold using the properly-cased IANA ID from PHP's timezone list
        // (ICU's getCanonicalID is case-sensitive).
        /** @var array<string, string>|null $lowerMap */
        static $lowerMap = null;
        if ($lowerMap === null) {
            $lowerMap = [];
            foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC) as $ident) {
                $lowerMap[strtolower($ident)] = $ident;
            }
        }
        $properCase = $lowerMap[$lower] ?? $id;
        // Antarctica/South_Pole and McMurdo are distinct IANA primaries that
        // share offset data with Pacific/Auckland. The offset-resolution fixup
        // (McMurdo -> Auckland) must NOT be applied for comparison.
        if ($properCase === 'Antarctica/South_Pole' || $properCase === 'Antarctica/McMurdo') {
            return 'Antarctica/McMurdo';
        }
        // Apply the shared fixup map (POSIX abbreviations, stale ICU links, etc.)
        // then run the result through ICU for a fully canonical comparison form.
        $resolved = self::resolveCanonicalTimezoneId($properCase);
        if (function_exists('intltz_get_canonical_id')) {
            $isSystem = false;
            $canon = \IntlTimeZone::getCanonicalID($resolved, $isSystem);
            if ($canon !== false && $canon !== '') {
                return $canon;
            }
        }
        return $resolved;
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
    public function toString(array|object|null $options = null): string
    {
        $options = Options::normalizeOptions($options);

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

        $offsetSec = $this->resolveOffsetSecondsAt($epochSec);
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
            $opts = is_array($options) ? $options : get_object_vars($options);
        }
        /** @psalm-var array<string, mixed> $opts */

        // TC39: timeZone option is disallowed for ZonedDateTime.toLocaleString.
        if (array_key_exists('timeZone', $opts) && $opts['timeZone'] !== null) {
            throw new TypeError('toLocaleString(): timeZone option is not allowed for ZonedDateTime.');
        }

        $locale = IntlFormatter::resolveLocale($locales);
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
    public function add(string|array|object $duration, array|object|null $options = null): self
    {
        $dur = $duration instanceof Duration ? $duration : Duration::from($duration);
        return $this->addDurationZdt(1, $dur, $options);
    }

    /**
     * Returns a new ZonedDateTime with the given duration subtracted.
     *
     * @param Duration|string|array<array-key,mixed>|object $duration Duration, ISO 8601 duration string, or property-bag array.
     * @param array<array-key, mixed>|object|null $options Options array; supports 'overflow' ('constrain'|'reject').
     * @psalm-api
     */
    public function subtract(string|array|object $duration, array|object|null $options = null): self
    {
        $dur = $duration instanceof Duration ? $duration : Duration::from($duration);
        return $this->addDurationZdt(-1, $dur, $options);
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
    public function since(string|array|object $other, array|object|null $options = null): Duration
    {
        $o = $other instanceof self ? $other : self::from($other);
        if ($this->calendarId !== $o->calendarId) {
            throw new RangeError(
                "Cannot compute since() between different calendars: \"{$this->calendarId}\" and \"{$o->calendarId}\".",
            );
        }
        return self::diffZdt($this, $o, 'since', $options);
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
    public function until(string|array|object $other, array|object|null $options = null): Duration
    {
        $o = $other instanceof self ? $other : self::from($other);
        if ($this->calendarId !== $o->calendarId) {
            throw new RangeError(
                "Cannot compute until() between different calendars: \"{$this->calendarId}\" and \"{$o->calendarId}\".",
            );
        }
        return self::diffZdt($this, $o, 'until', $options);
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
        if (is_string($options)) {
            $options = ['smallestUnit' => $options];
        } elseif (is_object($options)) {
            // TC39: if options is undefined, throw TypeError (required arg).
            if ($options instanceof \Stringable) {
                $str = (string) $options; // JsSymbol: throws; JsUndefined: returns 'undefined'
                if ($str === 'undefined') {
                    throw new TypeError('ZonedDateTime::round() requires a non-undefined options argument.');
                }
            }
            $options = Options::requireObject($options);
        }

        /** @var mixed $suRaw */
        $suRaw = $options['smallestUnit'] ?? null;
        if ($suRaw === null) {
            throw new RangeError('Temporal\\ZonedDateTime::round() requires smallestUnit.');
        }
        $suRaw = Options::coerceEnumOption($suRaw, 'smallestUnit');

        // [nsPerUnit, maxIncrement (next-unit size, or 1 for day)]
        $unitMap = [
            'day' => [86_400_000_000_000, 1],
            'days' => [86_400_000_000_000, 1],
            'hour' => [3_600_000_000_000, 24],
            'hours' => [3_600_000_000_000, 24],
            'minute' => [60_000_000_000, 60],
            'minutes' => [60_000_000_000, 60],
            'second' => [EpochLimits::NS_PER_SECOND, 60],
            'seconds' => [EpochLimits::NS_PER_SECOND, 60],
            'millisecond' => [EpochLimits::NS_PER_MILLISECOND, 1_000],
            'milliseconds' => [EpochLimits::NS_PER_MILLISECOND, 1_000],
            'microsecond' => [EpochLimits::NS_PER_MICROSECOND, 1_000],
            'microseconds' => [EpochLimits::NS_PER_MICROSECOND, 1_000],
            'nanosecond' => [1, 1_000],
            'nanoseconds' => [1, 1_000],
        ];
        if (!array_key_exists($suRaw, $unitMap)) {
            throw new RangeError("Invalid smallestUnit \"{$suRaw}\" for Temporal\\ZonedDateTime::round().");
        }
        [$nsPerUnit, $maxDivisor] = $unitMap[$suRaw];

        $roundingMode = 'halfExpand';
        if (array_key_exists('roundingMode', $options) && $options['roundingMode'] !== null) {
            $rmRaw = Options::coerceEnumOption($options['roundingMode'], 'roundingMode');
            $roundingMode = $rmRaw;
        }

        $increment = 1;
        if (array_key_exists('roundingIncrement', $options) && $options['roundingIncrement'] !== null) {
            // Per TC39 ToTemporalRoundingIncrement: GetOption with type «Number» calls ToNumber,
            // which coerces booleans/numeric strings. CalendarMath::toFiniteInt mirrors that.
            $rawIncrement = CalendarMath::toFiniteInt($options['roundingIncrement'], 'roundingIncrement');
            if ($rawIncrement < 1) {
                throw new RangeError('roundingIncrement must be a positive integer.');
            }
            $increment = $rawIncrement;
        }
        if ($maxDivisor === 1) {
            if ($increment !== 1) {
                throw new RangeError("roundingIncrement {$increment} is invalid for unit \"{$suRaw}\".");
            }
        } elseif ($increment >= $maxDivisor || ($maxDivisor % $increment) !== 0) {
            throw new RangeError(
                "roundingIncrement {$increment} does not evenly divide {$maxDivisor} for unit \"{$suRaw}\".",
            );
        }

        $nsIncrement = $nsPerUnit * $increment;
        $isDay = str_starts_with($suRaw, 'day');

        // ZonedDateTime rounding is always relative to local midnight (start of day).
        // Get local midnight epoch seconds and the offset from midnight in nanoseconds.
        $lc = $this->localComponents();
        $epochDays = CalendarMath::toJulianDay($lc['year'], $lc['month'], $lc['day']) - 2_440_588;
        $midnightWallSec = $epochDays * 86_400;
        $midnightEpochSec = TimeZoneHelper::wallSecToEpochSecStartOfDay($midnightWallSec, $this->resolvedTimeZoneId);

        // Compute offset from midnight using true epoch parts to handle sentinels.
        [$thisEpochSec, $thisSubNs] = $this->epochParts();
        $offsetFromMidnight = (($thisEpochSec - $midnightEpochSec) * EpochLimits::NS_PER_SECOND) + $thisSubNs;

        if ($isDay) {
            // Compute actual day length for DST-aware day rounding.
            $nextDayWallSec = $midnightWallSec + 86_400;
            $nextDayEpochSec = TimeZoneHelper::wallSecToEpochSecStartOfDay($nextDayWallSec, $this->resolvedTimeZoneId);

            // Spec (round step 18): GetStartOfDay(dateStart)/GetStartOfDay(dateEnd) must
            // throw when either day boundary falls outside the representable range.
            if (
                abs($midnightEpochSec) > EpochLimits::MAX_EPOCH_SECONDS
                || abs($nextDayEpochSec) > EpochLimits::MAX_EPOCH_SECONDS
            ) {
                throw new RangeError('ZonedDateTime day-rounding boundary is outside the representable range.');
            }

            $dayLengthNs = ($nextDayEpochSec - $midnightEpochSec) * EpochLimits::NS_PER_SECOND;

            $roundedOffsetNs = self::roundDayNs($offsetFromMidnight, $dayLengthNs, $roundingMode);
        } elseif ($nsIncrement === 1) {
            $roundedOffsetNs = $offsetFromMidnight;
        } else {
            // Round the offset from midnight, then add back midnight.
            $roundedOffsetNs = EpochRounding::roundAsIfPositive($offsetFromMidnight, $nsIncrement, $roundingMode);
        }

        // Compute the rounded result as epoch seconds + sub-ns.
        $roundedEpochSec = $midnightEpochSec + intdiv(num1: $roundedOffsetNs, num2: EpochLimits::NS_PER_SECOND);
        $roundedSubNs = $roundedOffsetNs % EpochLimits::NS_PER_SECOND;
        if ($roundedSubNs < 0) {
            $roundedEpochSec--;
            $roundedSubNs += EpochLimits::NS_PER_SECOND;
        }

        return self::fromEpochParts($roundedEpochSec, $roundedSubNs, $this->timeZoneId, $this->calendarId);
    }

    /**
     * Returns a new ZonedDateTime with the specified fields overridden.
     *
     * @param array<array-key,mixed>|object $fields   Property bag with fields to override.
     * @param array<array-key, mixed>|object|null       $options Options bag: ['overflow' => ..., 'disambiguation' => ...]
     * @psalm-api
     */
    public function with(array|object $fields, array|object|null $options = null): self
    {
        // Reject Temporal objects (IsPartialTemporalObject step 2).
        if (
            $fields instanceof PlainDate
            || $fields instanceof PlainDateTime
            || $fields instanceof PlainTime
            || $fields instanceof PlainYearMonth
            || $fields instanceof PlainMonthDay
            || $fields instanceof self
            || $fields instanceof Instant
            || $fields instanceof Duration
        ) {
            throw new TypeError('ZonedDateTime::with() argument must not be a Temporal object.');
        }

        $fields = is_object($fields) ? get_object_vars($fields) : $fields;

        if (array_key_exists('calendar', $fields) || array_key_exists('timeZone', $fields)) {
            throw new TypeError('ZonedDateTime::with() fields must not contain a calendar or timeZone property.');
        }

        $recognized = [
            'year',
            'month',
            'monthCode',
            'day',
            'hour',
            'minute',
            'second',
            'millisecond',
            'microsecond',
            'nanosecond',
            'offset',
            'era',
            'eraYear',
        ];
        $hasField = false;
        foreach ($recognized as $f) {
            if (!array_key_exists($f, $fields)) {
                continue;
            }

            $hasField = true;
            break;
        }
        if (!$hasField) {
            throw new TypeError('ZonedDateTime::with() requires at least one recognized property.');
        }

        $overflow = self::resolveOverflowOption($options);
        $disambiguation = self::extractDisambiguation($options);

        // Extract the 'offset' option (default is 'prefer' for with()).
        $offsetOption = 'prefer';
        if ($options !== null) {
            $optArr = is_array($options) ? $options : get_object_vars($options);
            if (array_key_exists('offset', $optArr)) {
                /** @var mixed $offOpt */
                $offOpt = $optArr['offset'];
                if ($offOpt !== null) {
                    $offOpt = Options::coerceEnumOption($offOpt, 'offset');
                    if (!in_array($offOpt, ['prefer', 'use', 'ignore', 'reject'], strict: true)) {
                        throw new RangeError(
                            "Invalid offset option \"{$offOpt}\": must be 'prefer', 'use', 'ignore', or 'reject'.",
                        );
                    }
                    $offsetOption = $offOpt;
                }
            }
        }

        // Validate the 'offset' field in the property bag.
        $hasOffsetField = array_key_exists('offset', $fields);
        if ($hasOffsetField) {
            /** @var mixed $offVal */
            $offVal = $fields['offset'];
            if (!is_string($offVal)) {
                throw new TypeError('ZonedDateTime::with() offset field must be a string.');
            }
            if (preg_match('/^[+-]\d{2}:\d{2}(:\d{2})?$/', $offVal) !== 1) {
                throw new RangeError("Invalid offset string \"{$offVal}\": must be ±HH:MM or ±HH:MM:SS.");
            }
        }

        $lc = $this->localComponents();
        $h = $lc['hour'];
        $min = $lc['minute'];
        $sec = $lc['second'];
        $ms = $lc['millisecond'];
        $us = $lc['microsecond'];
        $ns = $lc['nanosecond'];

        // --- Resolve time fields (shared by ISO and non-ISO paths) ---
        if (array_key_exists('hour', $fields)) {
            $h = CalendarMath::toFiniteInt($fields['hour'], 'ZonedDateTime::with() hour');
        }
        if (array_key_exists('minute', $fields)) {
            $min = CalendarMath::toFiniteInt($fields['minute'], 'ZonedDateTime::with() minute');
        }
        if (array_key_exists('second', $fields)) {
            $sec = CalendarMath::toFiniteInt($fields['second'], 'ZonedDateTime::with() second');
        }
        if (array_key_exists('millisecond', $fields)) {
            $ms = CalendarMath::toFiniteInt($fields['millisecond'], 'ZonedDateTime::with() millisecond');
        }
        if (array_key_exists('microsecond', $fields)) {
            $us = CalendarMath::toFiniteInt($fields['microsecond'], 'ZonedDateTime::with() microsecond');
        }
        if (array_key_exists('nanosecond', $fields)) {
            $ns = CalendarMath::toFiniteInt($fields['nanosecond'], 'ZonedDateTime::with() nanosecond');
        }

        // --- Constrain/reject time fields ---
        if ($overflow === 'constrain') {
            $h = max(0, min(23, $h));
            $min = max(0, min(59, $min));
            $sec = max(0, min(59, $sec));
            $ms = max(0, min(999, $ms));
            $us = max(0, min(999, $us));
            $ns = max(0, min(999, $ns));
        } else {
            if ($h < 0 || $h > 23) {
                throw new RangeError("Invalid hour {$h}: must be 0–23.");
            }
            if ($min < 0 || $min > 59) {
                throw new RangeError("Invalid minute {$min}: must be 0–59.");
            }
            if ($sec < 0 || $sec > 59) {
                throw new RangeError("Invalid second {$sec}: must be 0–59.");
            }
            if ($ms < 0 || $ms > 999) {
                throw new RangeError("Invalid millisecond {$ms}: must be 0–999.");
            }
            if ($us < 0 || $us > 999) {
                throw new RangeError("Invalid microsecond {$us}: must be 0–999.");
            }
            if ($ns < 0 || $ns > 999) {
                throw new RangeError("Invalid nanosecond {$ns}: must be 0–999.");
            }
        }

        $calendar = $this->calendarId !== 'iso8601' ? CalendarFactory::get($this->calendarId) : null;

        // --- Non-ISO calendar date resolution ---
        if ($calendar !== null) {
            $hasYear = array_key_exists('year', $fields);
            $hasEra = array_key_exists('era', $fields);
            $hasEraYear = array_key_exists('eraYear', $fields);
            $hasMonth = array_key_exists('month', $fields);
            $hasMonthCode = array_key_exists('monthCode', $fields);

            // Chinese/Dangi have no eras — providing era or eraYear is always a TypeError.
            if (($hasEra || $hasEraYear) && in_array($this->calendarId, ['chinese', 'dangi'], strict: true)) {
                throw new TypeError('eraYear and era are invalid for this calendar.');
            }

            // TC39: era without eraYear (or vice versa) is TypeError when year is not also provided.
            if ($hasEra && !$hasEraYear && !$hasYear) {
                throw new TypeError('era provided without eraYear in with() fields.');
            }
            if ($hasEraYear && !$hasEra && !$hasYear) {
                throw new TypeError('eraYear provided without era in with() fields.');
            }

            // Resolve year: era+eraYear takes precedence over the current year if both provided.
            // When $hasYear is false, $hasEra implies $hasEraYear (and vice versa) due to checks above.
            $year = $this->year;
            if ($hasYear) {
                $year = CalendarMath::toFiniteInt($fields['year'], 'ZonedDateTime::with() year');
            } elseif ($hasEra) {
                $resolved = CalendarMath::resolveYearFromEra(
                    $calendar,
                    $fields['era'],
                    $fields['eraYear'],
                    'ZonedDateTime::with()',
                );
                if ($resolved !== null) {
                    $year = $resolved;
                }
            }

            // Resolve monthCode/month with mutual exclusion.
            // When neither is provided, default to current monthCode (not ordinal month).
            $monthCode = null;
            $month = null;
            $useMonthCode = false;

            if ($hasMonthCode) {
                /** @var mixed $mc */
                $mc = $fields['monthCode'];
                if (!is_string($mc)) {
                    throw new RangeError('ZonedDateTime::with() monthCode must be a string.');
                }
                $monthCode = $mc;
                $useMonthCode = true;
            }
            if ($hasMonth) {
                $month = CalendarMath::toFiniteInt($fields['month'], 'ZonedDateTime::with() month');
                // Validate month/monthCode conflict.
                if ($monthCode !== null) {
                    $monthFromCode = $calendar->monthCodeToMonth($monthCode, $year);
                    if ($month !== $monthFromCode) {
                        throw new RangeError('Conflicting month and monthCode fields.');
                    }
                }
                $useMonthCode = false; // explicit month takes precedence
            }
            if (!$hasMonth && !$hasMonthCode) {
                // Default: preserve current monthCode.
                $monthCode = $this->monthCode;
                $useMonthCode = true;
            }

            $day = $this->day;
            if (array_key_exists('day', $fields)) {
                $day = CalendarMath::toFiniteInt($fields['day'], 'ZonedDateTime::with() day');
            }

            if ($day < 1) {
                throw new RangeError("Invalid day {$day}: must be at least 1.");
            }

            if ($useMonthCode && $monthCode !== null) {
                [$isoY, $isoM, $isoD] = $calendar->calendarToIsoFromMonthCode($year, $monthCode, $day, $overflow);
            } else {
                /** @var int $month */
                if ($month < 1) {
                    throw new RangeError("Invalid month {$month}: must be at least 1.");
                }
                [$isoY, $isoM, $isoD] = $calendar->calendarToIso($year, $month, $day, $overflow);
            }

            return self::localToZdt(
                $isoY,
                $isoM,
                $isoD,
                $h,
                $min,
                $sec,
                $ms,
                $us,
                $ns,
                $this->timeZoneId,
                $this->calendarId,
                $disambiguation,
            );
        }

        // --- ISO calendar date resolution ---
        $year = $lc['year'];
        $month = $lc['month'];
        $day = $lc['day'];

        if (array_key_exists('year', $fields)) {
            $year = CalendarMath::toFiniteInt($fields['year'], 'ZonedDateTime::with() year');
        }

        $hasMonth = array_key_exists('month', $fields);
        $hasMonthCode = array_key_exists('monthCode', $fields);
        if ($hasMonthCode) {
            /** @var mixed $mc */
            $mc = $fields['monthCode'];
            if (!is_string($mc)) {
                throw new RangeError('ZonedDateTime::with() monthCode must be a string.');
            }
            $month = CalendarMath::monthCodeToMonth($mc);
        }
        if ($hasMonth) {
            $newMonth = CalendarMath::toFiniteInt($fields['month'], 'ZonedDateTime::with() month');
            if ($hasMonthCode && $newMonth !== $month) {
                throw new RangeError('Conflicting month and monthCode fields.');
            }
            $month = $newMonth;
        }

        if (array_key_exists('day', $fields)) {
            $day = CalendarMath::toFiniteInt($fields['day'], 'ZonedDateTime::with() day');
        }

        if ($month < 1) {
            throw new RangeError("Invalid month {$month}: must be at least 1.");
        }
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
            // overflow === 'reject'
            if ($month > 12) {
                throw new RangeError("Invalid month {$month}: must be 1–12.");
            }
            $maxDay = CalendarMath::calcDaysInMonth($year, $month);
            if ($day > $maxDay) {
                throw new RangeError("Day {$day} is out of range for {$year}-{$month} (max {$maxDay}).");
            }
        }

        // If no offset field was provided but offset option requires preserving,
        // use the ZDT's current offset for wall-to-epoch conversion. Per TC39,
        // 'use'/'prefer'/'reject' all preserve the existing offset when possible.
        if (!$hasOffsetField && $offsetOption !== 'ignore') {
            [$curEpochSec] = $this->epochParts();
            $currentOffsetSec = self::staticResolveOffset($curEpochSec, $this->resolvedTimeZoneId);

            $epochDays = CalendarMath::toJulianDay($year, $month, $day) - 2_440_588;
            $wallSec = ($epochDays * 86_400) + ($h * 3600) + ($min * 60) + $sec;

            if ($offsetOption === 'use') {
                $epochSec = $wallSec - $currentOffsetSec;
                $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;
                return self::fromEpochParts($epochSec, $subNs, $this->timeZoneId, $this->calendarId);
            }
            // 'prefer'/'reject': check if current offset is valid at new wall time
            $epochFromOffset = $wallSec - $currentOffsetSec;
            $actualOffset = self::staticResolveOffset($epochFromOffset, $this->resolvedTimeZoneId);
            if ($actualOffset === $currentOffsetSec) {
                $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;
                return self::fromEpochParts($epochFromOffset, $subNs, $this->timeZoneId, $this->calendarId);
            }

            // Current offset not valid at new wall time — fall through to disambiguation
        }

        // Handle offset field with offset option (like from()).
        if ($hasOffsetField) {
            /** @var string $offVal */
            $offVal = $fields['offset'];
            $offSign = $offVal[0] === '+' ? 1 : -1;
            $offParts = explode(separator: ':', string: substr(string: $offVal, offset: 1));
            $givenOffsetSec =
                $offSign
                * (
                    ((int) $offParts[0] * 3600)
                    + ((int) $offParts[1] * 60)
                    + (array_key_exists(2, $offParts) ? (int) $offParts[2] : 0)
                );

            if ($offsetOption === 'ignore') {
                // Fall through to normal localToZdt.
            } else {
                $epochDays = CalendarMath::toJulianDay($year, $month, $day) - 2_440_588;
                $wallSec = ($epochDays * 86_400) + ($h * 3600) + ($min * 60) + $sec;

                if ($offsetOption === 'use') {
                    // Use the offset directly, regardless of timezone rules.
                    $epochSec = $wallSec - $givenOffsetSec;
                    $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;
                    return self::fromEpochParts($epochSec, $subNs, $this->timeZoneId, $this->calendarId);
                }

                // 'prefer' or 'reject': try using the given offset.
                $epochFromOffset = $wallSec - $givenOffsetSec;
                $actualOffset = self::staticResolveOffset($epochFromOffset, $this->resolvedTimeZoneId);
                if ($actualOffset === $givenOffsetSec) {
                    $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;
                    return self::fromEpochParts($epochFromOffset, $subNs, $this->timeZoneId, $this->calendarId);
                }
                if ($offsetOption === 'reject') {
                    throw new RangeError(
                        "The offset {$offVal} does not match the timezone offset at the given instant.",
                    );
                }

                // 'prefer': fall through to normal localToZdt.
            }
        }

        return self::localToZdt(
            $year,
            $month,
            $day,
            $h,
            $min,
            $sec,
            $ms,
            $us,
            $ns,
            $this->timeZoneId,
            $this->calendarId,
            $disambiguation,
        );
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
                $bag = get_object_vars($direction);
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

        if (preg_match('/^[+\-]\d{2}:\d{2}$/', $this->resolvedTimeZoneId) === 1) {
            return null;
        }

        // Sentinel-aware: derive the transition search anchor from the true epoch
        // parts, not the clamped epochNanoseconds field.
        [$epochSec, $subNs] = $this->epochParts();
        /** @psalm-suppress ArgumentTypeCoercion — timeZoneId is validated non-empty in constructor */
        $tz = new \DateTimeZone($this->resolvedTimeZoneId);

        if ($dir === 'next') {
            $transitions = TimeZoneHelper::safeGetTransitions($tz, $epochSec, $epochSec + (200 * 365 * 86_400));
            if (count($transitions) < 2) {
                return null;
            }
            // Skip index 0 (initial state at range start). Find first entry
            // with a DIFFERENT UTC offset (TC39 defines transition as offset change).
            $prevOffset = $transitions[0]['offset'];
            $nTransitions = count($transitions);
            for ($i = 1; $i < $nTransitions; $i++) {
                $curOffset = $transitions[$i]['offset'];
                // The ts guard makes the scan robust against getTransitions()
                // implementations that ignore the requested range start and
                // return the zone's full history (observed with newer tzdata
                // for zones whose only transition predates the range, e.g.
                // Asia/Riyadh's 1947 LMT switch): a historical transition at
                // or before the anchor is never "next".
                if ($curOffset !== $prevOffset && $transitions[$i]['ts'] > $epochSec) {
                    // A transition whose whole-second nanoseconds would overflow the int64
                    // epochNanoseconds field is not representable: transitionAt() returns
                    // null per spec (and avoids the int64 overflow $ts * NS_PER_SECOND hits).
                    return $this->transitionAt($transitions[$i]['ts']);
                }
                $prevOffset = $curOffset;
            }
            // No upcoming offset change in range. Without this return, the scan
            // would fall through into the 'previous' branch below and report a
            // historical transition as "next".
            return null;
        }

        // 'previous': find the most recent transition strictly BEFORE the current instant.
        // Use epochSec+1 as end bound so that a transition at exactly epochSec is always
        // included — some PHP/ICU versions exclude the boundary second from getTransitions().
        $transitions = TimeZoneHelper::safeGetTransitions($tz, $epochSec - (200 * 365 * 86_400), $epochSec + 1);
        if (count($transitions) < 2) {
            return null;
        }
        // Walk backwards from the end. Find entries where offset differs from
        // the following entry (= an actual UTC offset transition).
        // Skip index 0 (initial state).
        // A transition at exactly the current epoch nanosecond is NOT "previous".
        // ($subNs comes from epochParts() above — sentinel-aware.)
        $candidateTs = null;
        for ($i = count($transitions) - 1; $i >= 1; $i--) {
            $ts = $transitions[$i]['ts'];
            // Strictly before: ts < epochSec, or ts == epochSec only if there are sub-second ns.
            $isBefore = $ts < $epochSec || $ts === $epochSec && $subNs > 0;
            if ($transitions[$i]['offset'] !== $transitions[$i - 1]['offset'] && $isBefore) {
                $candidateTs = $ts;
                break;
            }
        }
        if ($candidateTs === null) {
            return null;
        }
        // Symmetric with the 'next' branch: a transition that would overflow the int64
        // epochNanoseconds field is not representable (the field would clamp to
        // PHP_INT_MAX/MIN and become indistinguishable from the anchor), so there is no
        // in-range previous transition and transitionAt() returns null.
        return $this->transitionAt($candidateTs);
    }

    /**
     * Builds the ZonedDateTime for a whole-second timezone-transition timestamp, or null
     * when that timestamp's nanoseconds would overflow the int64 epochNanoseconds field.
     *
     * Shared by the 'next' and 'previous' branches of {@see getTimeZoneTransition()}. The
     * bound is the bare int64 field limit (a whole-second transition carries no sub-second
     * remainder), not the spec-max instant in seconds: a transition below the spec max can
     * still clamp the field and become indistinguishable from the anchor, so it is rejected.
     */
    private function transitionAt(int $ts): ?self
    {
        if (abs($ts) > EpochLimits::MAX_EPOCH_SECONDS_FOR_INT64_NS_FIELD) {
            return null;
        }
        return new self($ts * EpochLimits::NS_PER_SECOND, $this->timeZoneId, $this->calendarId);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a ZonedDateTime from true UTC epoch seconds and sub-second
     * nanoseconds, preserving over-int64 values behind a sentinel.
     *
     * Internal seam for sibling Spec classes (e.g. Instant::toZonedDateTimeISO)
     * that hold true epoch parts and must hand them across the class boundary
     * without clamping. Not part of the TC39 public surface.
     *
     * @internal
     * @psalm-internal Temporal\Spec
     * @throws RangeError if the result is outside the representable range.
     */
    public static function fromInstantParts(
        int $epochSec,
        int $subNs,
        string $tzId,
        string $calendarId = 'iso8601',
    ): self {
        return self::fromEpochParts($epochSec, $subNs, $tzId, $calendarId);
    }

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
     * Computes the signed nanosecond difference ($b - $a) using true epoch parts.
     *
     * When both values fit in int64, uses plain arithmetic. Falls back to
     * seconds + sub-ns decomposition to avoid int overflow for proleptic dates.
     *
     * @return int Nanosecond difference (may still overflow for spans > ~292 years,
     *             but calendar-largest paths only use this for sign detection).
     */
    private static function diffEpochNs(self $a, self $b): int
    {
        [$aSec, $aSubNs] = $a->epochParts();
        [$bSec, $bSubNs] = $b->epochParts();
        $diffSec = $bSec - $aSec;
        $diffSubNs = $bSubNs - $aSubNs;
        // Safe multiplication check: |diffSec| * 1e9 fits int64 when |diffSec| < ~9.2e9
        $maxSafeSecDiff = 9_000_000_000;
        if ($diffSec > $maxSafeSecDiff || $diffSec < -$maxSafeSecDiff) {
            // Return a large sentinel value preserving sign; callers that need
            // the calendar path only use this for sign, not magnitude.
            return $diffSec > 0 ? PHP_INT_MAX : PHP_INT_MIN;
        }
        return ($diffSec * EpochLimits::NS_PER_SECOND) + $diffSubNs;
    }

    /**
     * Computes (and caches) all local date/time components for this instant in the stored timezone.
     *
     * @return array{year:int, month:int<1,12>, day:int<1,31>, hour:int<0,23>, minute:int<0,59>, second:int<0,59>, millisecond:int<0,999>, microsecond:int<0,999>, nanosecond:int<0,999>, offsetSec:int, offset:string}
     * @psalm-suppress UnusedMethod — called from PHP 8.4 property hooks that Psalm does not track
     */
    private function localComponents(): array
    {
        if ($this->localCache !== null) {
            return $this->localCache;
        }

        // Use stored true epoch parts when available (sentinel values).
        [$epochSec, $subNs] = $this->epochParts();

        $offsetSec = $this->resolveOffsetSecondsAt($epochSec);
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
     * Returns the UTC offset in seconds for this timezone at a given epoch second.
     *
     * - 'UTC' → 0.
     * - '±HH:MM' → ±(H*3600 + M*60).
     * - IANA name → use PHP DateTimeZone::getOffset().
     */
    /**
     * Resolves an IANA timezone ID to its canonical form for consistent
     * offset lookups. Uses ICU canonicalization plus fixups for known
     * inconsistencies where ICU and IANA disagree on canonical targets.
     */
    private static function resolveCanonicalTimezoneId(string $id): string
    {
        /** @var array<string, string> $cache */
        static $cache = [];
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        return $cache[$id] = self::resolveCanonicalTimezoneIdUncached($id);
    }

    private static function resolveCanonicalTimezoneIdUncached(string $id): string
    {
        if (array_key_exists($id, self::ICU_FIXUPS)) {
            $resolved = self::ICU_FIXUPS[$id];
            // Chain through fixups (e.g. South_Pole -> McMurdo -> Auckland).
            return self::ICU_FIXUPS[$resolved] ?? $resolved;
        }
        if (function_exists('intltz_get_canonical_id')) {
            $isSystem = false;
            $canon = \IntlTimeZone::getCanonicalID($id, $isSystem);
            if ($canon !== false && $canon !== '' && $canon !== $id) {
                // Apply fixups to the ICU result as well
                $canon = self::ICU_FIXUPS[$canon] ?? $canon;
                new \DateTimeZone($canon);
                return $canon;
            }
        }
        return $id;
    }

    private function resolveOffsetSecondsAt(int $epochSec): int
    {
        // Fixed offset ±HH:MM.
        $m = null;
        if (preg_match('/^([+\-])(\d{2}):(\d{2})$/', $this->resolvedTimeZoneId, $m) === 1) {
            $sign = $m[1] === '+' ? 1 : -1;
            return $sign * (((int) $m[2] * 3600) + ((int) $m[3] * 60));
        }
        // IANA timezone: use PHP to find the offset at the given instant.
        /** @var array<string, \DateTimeZone> $tzCache */
        static $tzCache = [];
        $tz = $tzCache[$this->resolvedTimeZoneId] ?? null;
        if ($tz === null) {
            /** @psalm-suppress ArgumentTypeCoercion — timeZoneId is validated to be non-empty in constructor */
            $tz = new \DateTimeZone($this->resolvedTimeZoneId);
            $tzCache[$this->resolvedTimeZoneId] = $tz;
        }
        return $tz->getOffset(new \DateTimeImmutable(sprintf('@%d', $epochSec)));
    }

    /**
     * Parses a ZonedDateTime ISO string (with required bracket timezone annotation).
     *
     * @param array<array-key, mixed>|object|null $options Options from from() (may contain 'offset' key).
     * @throws RangeError if the string is invalid.
     */
    private static function parseZdtString(string $text, array|object|null $options = null): self
    {
        $opts = Options::normalizeOptions($options);

        // Resolve the 'offset' option (default: 'reject').
        $offsetOption = 'reject';
        if (array_key_exists('offset', $opts)) {
            /** @var mixed $ov */
            $ov = $opts['offset'];
            if (
                is_string($ov) && in_array(needle: $ov, haystack: ['use', 'ignore', 'prefer', 'reject'], strict: true)
            ) {
                $offsetOption = $ov;
            }
        }

        // Resolve the 'disambiguation' option (default: 'compatible').
        $disambiguation = 'compatible';
        if (array_key_exists('disambiguation', $opts)) {
            /** @var mixed $dv */
            $dv = $opts['disambiguation'];
            if (is_string($dv) && in_array($dv, ['compatible', 'earlier', 'later', 'reject'], strict: true)) {
                $disambiguation = $dv;
            }
        }

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
        [$tzId, $calendarId] = self::extractTzFromAnnotations($annotationSection, $text);

        // Parse inline offset if present.
        $hasInlineOffset = $offsetRaw !== '';
        $inlineOffsetSec = 0;
        // Whether the inline offset string included a seconds component (e.g. +05:30:00 vs +05:30).
        $inlineOffsetHasSeconds = false;
        if ($hasInlineOffset) {
            [$inlineSign, $inlineAbsSec] = self::parseSimpleOffset($offsetRaw);
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
        $subNs = $fractionRaw !== '' ? self::parseFraction($fractionRaw) : 0;

        // Determine epoch seconds.
        if ($hasInlineOffset && (strtoupper($offsetRaw) === 'Z' || $offsetRaw === 'Z' || $offsetRaw === 'z')) {
            // Z → UTC, epochSec = wallSec.
            $epochSec = $wallSec;
        } elseif ($hasInlineOffset) {
            // Inline offset present: behavior depends on offset option.
            $normalizedTzId = TimeZoneHelper::normalizeTimezoneId($tzId);
            $resolvedTzId = self::resolveCanonicalTimezoneId($normalizedTzId);

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
                    $actualOffsetSec = self::staticResolveOffset($epochSec, $resolvedTzId);
                    if ($actualOffsetSec !== $inlineOffsetSec) {
                        $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
                    }
                } else {
                    // HH:MM: use timezone resolution first. If the resolved offset matches
                    // exactly, the inline offset successfully disambiguated. Otherwise, accept
                    // if it rounds to the resolved offset (sub-minute tolerance).
                    $tzEpoch = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
                    $tzOffset = self::staticResolveOffset($tzEpoch, $resolvedTzId);
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
                        $actualOffsetSec = self::staticResolveOffset($epochSec, $resolvedTzId);
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
                    $actualOffsetSec = self::staticResolveOffset($epochSec, $resolvedTzId);
                    if ($actualOffsetSec !== $inlineOffsetSec) {
                        // Also check against timezone resolution for the error case.
                        $tzEpoch = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
                        $tzOffset = self::staticResolveOffset($tzEpoch, $resolvedTzId);
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
                    $tzOffset = self::staticResolveOffset($tzEpoch, $resolvedTzId);
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
                        $actualOffsetSec = self::staticResolveOffset($epochSec, $resolvedTzId);
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
            $normalizedTzId = TimeZoneHelper::normalizeTimezoneId($tzId);
            $resolvedTzId = self::resolveCanonicalTimezoneId($normalizedTzId);
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

        return self::fromEpochParts($epochSec, $subNs, $tzId, $calendarId ?? 'iso8601');
    }

    /**
     * Creates a ZonedDateTime from a property-bag array.
     *
     * Required fields: a datetime bag (year/month/day/…), timeZone.
     *
     * @param array<array-key, mixed> $bag
     * @throws TypeError              if required fields are missing or wrong type.
     * @throws RangeError if values are invalid.
     */
    private static function fromPropertyBag(
        array $bag,
        string $overflow = 'constrain',
        string $disambiguation = 'compatible',
        string $offsetOption = 'reject',
    ): self {
        // Validate calendar first (spec validates calendar before required fields).
        $calendarId = 'iso8601';
        if (array_key_exists('calendar', $bag)) {
            $calendarId = CalendarFactory::resolveBagCalendar($bag['calendar'], 'ZonedDateTime');
        }

        // Must have a timeZone key.
        if (!array_key_exists('timeZone', $bag)) {
            throw new TypeError('ZonedDateTime property bag must have a timeZone field.');
        }
        /** @var mixed $tzRaw */
        $tzRaw = $bag['timeZone'];
        // Per TC39 ToTemporalTimeZoneIdentifier: a ZonedDateTime instance contributes its
        // [[TimeZone]] slot directly. Otherwise the value must be a string.
        if ($tzRaw instanceof self) {
            $tzRaw = $tzRaw->timeZoneId;
        } elseif (!is_string($tzRaw)) {
            throw new TypeError('ZonedDateTime timeZone must be a string.');
        }

        // Expect year/month/day/hour/minute/second fields.
        $hasEraAndEraYear = CalendarMath::hasEraAndEraYear($bag, $calendarId, 'ZonedDateTime');
        $calendarSupportsEras = CalendarMath::supportsEras($calendarId);

        if (!array_key_exists('year', $bag) && (!$hasEraAndEraYear || !$calendarSupportsEras)) {
            throw new TypeError('ZonedDateTime property bag must have a year field.');
        }
        if (!array_key_exists('day', $bag)) {
            throw new TypeError('ZonedDateTime property bag must have a day field.');
        }

        // month can come from 'month' or 'monthCode'.
        if (!array_key_exists('month', $bag) && !array_key_exists('monthCode', $bag)) {
            throw new TypeError('ZonedDateTime property bag must have a month or monthCode field.');
        }

        /** @var int|float|string|null $yr */
        $yr = $bag['year'] ?? null;
        /** @var int|float|string $dy */
        $dy = $bag['day'];
        /** @var int|float|string $hr */
        $hr = $bag['hour'] ?? 0;
        /** @var int|float|string $mn */
        $mn = $bag['minute'] ?? 0;
        /** @var int|float|string $sc */
        $sc = $bag['second'] ?? 0;
        /** @var int|float|string $ms */
        $ms = $bag['millisecond'] ?? 0;
        /** @var int|float|string $us */
        $us = $bag['microsecond'] ?? 0;
        /** @var int|float|string $ns */
        $ns = $bag['nanosecond'] ?? 0;

        // monthCode and offset SYNTAX (well-formedness) are validated before the
        // year field's TYPE is coerced (per TC39 PrepareCalendarFields ordering:
        // monthCode/offset string syntax is checked while reading the field, before
        // the year value is converted). A non-string monthCode is itself a TypeError;
        // a malformed monthCode FORMAT string is a RangeError. monthCode SUITABILITY
        // (whether the well-formed code names a real month) is validated later, after
        // the year type has been checked.
        if (array_key_exists('monthCode', $bag)) {
            /** @var mixed $mcRaw */
            $mcRaw = $bag['monthCode'];
            if (!is_string($mcRaw)) {
                throw new TypeError('ZonedDateTime monthCode must be a string.');
            }
            // Well-formed monthCode syntax: 'M' + two digits + optional leap 'L'.
            if (preg_match('/^M(\d{2})(L?)$/', $mcRaw) !== 1) {
                throw new RangeError("Invalid monthCode for ISO calendar: \"{$mcRaw}\".");
            }
        }

        // offset field SYNTAX is validated before the year field's TYPE is coerced;
        // offset MATCHING against the timezone happens later, after year coercion.
        // TC39: syntax validation runs even for offsetOption='ignore' — the offset
        // must be a syntactically valid string regardless of whether it is used.
        if (array_key_exists('offset', $bag)) {
            /** @var mixed $offSyntaxRaw */
            $offSyntaxRaw = $bag['offset'];
            if (!is_string($offSyntaxRaw)) {
                throw new TypeError('ZonedDateTime offset must be a string.');
            }
            if (preg_match('/^[+-]\d{2}:\d{2}(:\d{2})?$/', $offSyntaxRaw) !== 1) {
                throw new RangeError("Invalid offset string \"{$offSyntaxRaw}\": must be ±HH:MM or ±HH:MM:SS.");
            }
        }

        // Validate and cast numeric fields; reject INF/-INF.
        $numericFields = [
            'day' => $dy,
            'hour' => $hr,
            'minute' => $mn,
            'second' => $sc,
            'millisecond' => $ms,
            'microsecond' => $us,
            'nanosecond' => $ns,
        ];
        if ($yr !== null) {
            $numericFields['year'] = $yr;
        }
        /** @psalm-suppress MixedAssignment — array values are all typed as mixed via @var annotations above */
        foreach ($numericFields as $fname => $fval) {
            if (is_float($fval) && is_infinite($fval)) {
                throw new RangeError(sprintf(
                    'ZonedDateTime %s must be finite; got %s.',
                    $fname,
                    $fval > 0 ? 'INF' : '-INF',
                ));
            }
        }

        // Coerce year via toFiniteInt so a non-coercible type (e.g. a JS Symbol)
        // throws TypeError here — this is the year TYPE check the fixtures pin
        // between monthCode/offset syntax (RangeError) and monthCode suitability /
        // offset matching (RangeError).
        $year = $yr !== null ? CalendarMath::toFiniteInt($yr, 'ZonedDateTime year') : 0;
        $day = intval($dy);
        $hour = intval($hr);
        $minute = intval($mn);
        $second = intval($sc);
        $milli = intval($ms);
        $micro = intval($us);
        $nano = intval($ns);

        $calendar = $calendarId !== 'iso8601' ? CalendarFactory::get($calendarId) : null;

        // Resolve era + eraYear if present (overrides year for era-based calendars).
        if ($calendar !== null && array_key_exists('era', $bag) && array_key_exists('eraYear', $bag)) {
            $resolved = CalendarMath::resolveYearFromEra($calendar, $bag['era'], $bag['eraYear'], 'ZonedDateTime');
            if ($resolved !== null) {
                $year = $resolved;
            }
        }

        // Resolve month from 'month' and/or 'monthCode'.
        $month = null;
        $monthCode = null;
        $hasMonth = array_key_exists('month', $bag);
        $hasMC = array_key_exists('monthCode', $bag);

        if (array_key_exists('monthCode', $bag)) {
            /** @var string $mc — well-formedness already validated by the earlier monthCode syntax guard */
            $mc = $bag['monthCode'];
            $monthCode = $mc;
            $month = $calendar !== null ? $calendar->monthCodeToMonth($mc, $year) : CalendarMath::monthCodeToMonth($mc);
        }

        if ($hasMonth) {
            $newMonth = CalendarMath::toFiniteInt($bag['month'] ?? null, 'ZonedDateTime month');
            if ($hasMC && $newMonth !== $month) {
                throw new RangeError('Conflicting month and monthCode fields.');
            }
            $month = $newMonth;
        }
        /** @var int $month */

        // Apply overflow (constrain or reject).
        if ($month < 1) {
            throw new RangeError("Invalid month {$month}: must be at least 1.");
        }
        if ($day < 1) {
            throw new RangeError("Invalid day {$day}: must be at least 1.");
        }

        // Non-ISO calendar: resolve calendar fields to ISO via the calendar protocol.
        if ($calendar !== null) {
            if ($monthCode !== null) {
                [$isoY, $isoM, $isoD] = $calendar->calendarToIsoFromMonthCode($year, $monthCode, $day, $overflow);
            } else {
                [$isoY, $isoM, $isoD] = $calendar->calendarToIso($year, $month, $day, $overflow);
            }
            $year = $isoY;
            $month = $isoM;
            $day = $isoD;
        } else {
            if ($overflow === 'constrain') {
                /**
                 * @psalm-suppress UnnecessaryVarAnnotation — Mago can't narrow min()
                 */
                $month = min(12, $month);
                $maxDay = CalendarMath::calcDaysInMonth($year, $month);
                $day = min($maxDay, $day);
            } else {
                // overflow === 'reject'
                if ($month > 12) {
                    throw new RangeError("Invalid month {$month}: must be 1–12.");
                }
                $maxDay = CalendarMath::calcDaysInMonth($year, $month);
                if ($day > $maxDay) {
                    throw new RangeError("Invalid day {$day}: exceeds {$maxDay} for {$year}-{$month}.");
                }
            }
        }

        // Constrain time fields.
        if ($overflow === 'constrain') {
            $hour = max(0, min(23, $hour));
            $minute = max(0, min(59, $minute));
            $second = max(0, min(59, $second));
            $milli = max(0, min(999, $milli));
            $micro = max(0, min(999, $micro));
            $nano = max(0, min(999, $nano));
        }

        // Use JDN-based computation to handle extreme years (DateTimeImmutable
        // cannot represent years beyond ~9999 or negative years reliably).
        $epochDays = CalendarMath::toJulianDay($year, $month, $day) - 2_440_588;
        $wallSec = ($epochDays * 86_400) + ($hour * 3600) + ($minute * 60) + $second;
        // ISODateTimeWithinLimits check.
        if ($wallSec > EpochLimits::MAX_EPOCH_SECONDS || $wallSec < -EpochLimits::MAX_EPOCH_SECONDS) {
            throw new RangeError('ZonedDateTime property bag: local date-time is outside the representable range.');
        }

        $normalTzId = TimeZoneHelper::normalizeTimezoneId($tzRaw);
        $resolvedTzId = self::resolveCanonicalTimezoneId($normalTzId);
        $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
        $subNs = ($milli * EpochLimits::NS_PER_MILLISECOND) + ($micro * EpochLimits::NS_PER_MICROSECOND) + $nano;

        // Handle 'offset' field if provided: depends on offset option.
        if (array_key_exists('offset', $bag) && $offsetOption !== 'ignore') {
            /** @var string $offRaw — syntax already validated by the earlier offset guard */
            $offRaw = $bag['offset'];
            $offSign = $offRaw[0] === '+' ? 1 : -1;
            $offParts = explode(separator: ':', string: substr(string: $offRaw, offset: 1));
            $givenOffsetSec =
                $offSign
                * (
                    ((int) $offParts[0] * 3600)
                    + ((int) $offParts[1] * 60)
                    + (array_key_exists(2, $offParts) ? (int) $offParts[2] : 0)
                );

            if ($offsetOption === 'use') {
                // Use the offset directly, regardless of timezone rules.
                $epochSec = $wallSec - $givenOffsetSec;
            } else {
                // 'prefer' or 'reject': try using the given offset.
                $epochFromOffset = $wallSec - $givenOffsetSec;
                $actualOffset = self::staticResolveOffset($epochFromOffset, $resolvedTzId);
                if ($actualOffset === $givenOffsetSec) {
                    // The offset is valid at this instant — use it.
                    $epochSec = $epochFromOffset;
                } elseif ($offsetOption === 'reject') {
                    // Offset doesn't match timezone at this wall time → reject.
                    throw new RangeError(
                        "The offset {$offRaw} does not match the timezone {$normalTzId} offset at the given instant.",
                    );
                }

                // 'prefer': keep disambiguation-resolved epochSec.
            }
        }

        return self::fromEpochParts($epochSec, $subNs, $normalTzId, $calendarId);
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
    private static function extractTzFromAnnotations(string $section, string $original): array
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

    /**
     * Parses a simple offset string (Z, ±HH, ±HH:MM, ±HHMM, ±HH:MM:SS, etc.)
     * into [sign, absSec].
     *
     * @return array{int, int} [sign (+1|-1), absSec]
     */
    private static function parseSimpleOffset(string $offset): array
    {
        if ($offset === 'Z' || $offset === 'z') {
            return [1, 0];
        }
        [$sign, $absSec] = self::parseOffset($offset);
        return [$sign, $absSec];
    }

    /**
     * Resolves the UTC offset (in seconds) for a given timezone at a given epoch second.
     * Static version for use in parseZdtString.
     */
    private static function staticResolveOffset(int $epochSec, string $tzId): int
    {
        $m = null;
        if (preg_match('/^([+\-])(\d{2}):(\d{2})$/', $tzId, $m) === 1) {
            $sign = $m[1] === '+' ? 1 : -1;
            return $sign * (((int) $m[2] * 3600) + ((int) $m[3] * 60));
        }
        /** @psalm-suppress ArgumentTypeCoercion — $tzId is validated non-empty before this call */
        $tz = new \DateTimeZone($tzId);
        return $tz->getOffset(new \DateTimeImmutable(sprintf('@%d', $epochSec)));
    }

    // -------------------------------------------------------------------------
    // Helpers copied from Instant.php
    // -------------------------------------------------------------------------

    /** @return int<0, 999999999> */
    private static function parseFraction(string $fractionRaw): int
    {
        $digits = substr($fractionRaw, offset: 1);
        /** @var int<0, 999999999> — 9 decimal digits, range 000000000–999999999 */
        return (int) str_pad(substr($digits, offset: 0, length: 9), length: 9, pad_string: '0');
    }

    /**
     * Parses an offset string into [sign, absSec, fracNs].
     *
     * @return array{-1|1, int<0, 86399>, int<0, 999999999>}  [sign (+1|-1), absSec, fracNs]
     * @throws RangeError if the offset is out of range.
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
                        $fracNs = self::parseFraction($rest);
                    }
                }
            } else {
                $minutes = (int) substr(string: $rest, offset: 0, length: 2);
                $rest = substr(string: $rest, offset: 2);
                if (strlen($rest) >= 2) {
                    $seconds = (int) substr(string: $rest, offset: 0, length: 2);
                    $rest = substr(string: $rest, offset: 2);
                    if (str_starts_with($rest, '.') || str_starts_with($rest, ',')) {
                        $fracNs = self::parseFraction($rest);
                    }
                }
            }
        }

        $absSec = ($hours * 3600) + ($minutes * 60) + $seconds;
        /** @var int<0, 86399> $absSec — range validated above */

        return [$sign, $absSec, $fracNs];
    }

    // -------------------------------------------------------------------------
    // Private helpers for add/subtract/since/until/round/with
    // -------------------------------------------------------------------------

    /**
     * Shared add/subtract implementation for ZonedDateTime.
     *
     * Calendar units are applied to local date fields and re-resolved;
     * time units are added as nanoseconds to the epoch.
     *
     * @param array<array-key, mixed>|object|null $options
     */
    private function addDurationZdt(int $sign, Duration $dur, array|object|null $options): self
    {
        // Sentinel epoch values: if we don't have trueEpochSec for pure-time-unit
        // arithmetic on a sentinel, reject (calendar-unit path uses localComponents
        // which handles sentinels correctly via trueEpochSec).
        if ($this->epochNanoseconds === PHP_INT_MAX || $this->epochNanoseconds === PHP_INT_MIN) {
            $isBlank =
                $dur->years === 0
                && $dur->months === 0
                && $dur->weeks === 0
                && $dur->days === 0
                && $dur->hours === 0
                && $dur->minutes === 0
                && $dur->seconds === 0
                && $dur->milliseconds === 0
                && $dur->microseconds === 0
                && $dur->nanoseconds === 0;
            $hasCalendar = $dur->years !== 0 || $dur->months !== 0 || $dur->weeks !== 0 || $dur->days !== 0;
            if (!$isBlank && !$hasCalendar && $this->trueEpochSec === null) {
                throw new RangeError('ZonedDateTime arithmetic result is outside the representable range.');
            }
        }

        $overflow = self::resolveOverflowOption($options);

        $years = $sign * (int) $dur->years;
        $months = $sign * (int) $dur->months;
        $weeks = $sign * (int) $dur->weeks;
        $days = $sign * (int) $dur->days;
        $hours = $sign * (int) $dur->hours;
        $minutes = $sign * (int) $dur->minutes;
        $seconds = $sign * (int) $dur->seconds;
        $ms = $sign * (int) $dur->milliseconds;
        $us = $sign * (int) $dur->microseconds;
        $ns = $sign * (int) $dur->nanoseconds;

        $hasCalendarUnits = $years !== 0 || $months !== 0 || $weeks !== 0 || $days !== 0;

        if ($hasCalendarUnits) {
            // Get local date/time, apply calendar units, then re-resolve to ZDT.
            $lc = $this->localComponents();

            // Use calendar protocol for non-ISO calendars.
            if ($this->calendarId !== 'iso8601') {
                $cal = CalendarFactory::get($this->calendarId);
                [$newYear, $newMonth, $newDay] = $cal->dateAdd(
                    $lc['year'],
                    $lc['month'],
                    $lc['day'],
                    $years,
                    $months,
                    $weeks,
                    $days,
                    $overflow,
                );
            } else {
                $newYear = $lc['year'] + $years;
                $newMonth = $lc['month'] + $months;

                // Normalize month into 1-12, carrying into year.
                if ($newMonth > 12) {
                    $newYear += intdiv(num1: $newMonth - 1, num2: 12);
                    $newMonth = (($newMonth - 1) % 12) + 1;
                } elseif ($newMonth < 1) {
                    $newYear += intdiv(num1: $newMonth - 12, num2: 12);
                    $newMonth = (((($newMonth - 1) % 12) + 12) % 12) + 1;
                }

                // Clamp or reject day.
                $newDay = $lc['day'];
                $maxDay = CalendarMath::calcDaysInMonth($newYear, $newMonth);
                if ($newDay > $maxDay) {
                    if ($overflow === 'constrain') {
                        $newDay = $maxDay;
                    } else {
                        throw new RangeError("Day {$newDay} is out of range for {$newYear}-{$newMonth}.");
                    }
                }

                // Add weeks and days via JDN.
                $totalDays = ($weeks * 7) + $days;
                $jdn = CalendarMath::toJulianDay($newYear, $newMonth, $newDay) + $totalDays;
                [$newYear, $newMonth, $newDay] = CalendarMath::fromJulianDay($jdn);
            }

            // TC39 AddZonedDateTime: first resolve the new local date+time to
            // an intermediate ZDT epoch, then add time units to the epoch.
            // This correctly handles DST day length differences.

            // Balance time units to nanoseconds.
            $timeNs =
                ($hours * 3_600_000_000_000)
                + ($minutes * 60_000_000_000)
                + ($seconds * EpochLimits::NS_PER_SECOND)
                + ($ms * EpochLimits::NS_PER_MILLISECOND)
                + ($us * EpochLimits::NS_PER_MICROSECOND)
                + $ns;

            if ($timeNs === 0) {
                // No time units: just resolve the new local date with original time.
                return self::localToZdt(
                    $newYear,
                    $newMonth,
                    $newDay,
                    $lc['hour'],
                    $lc['minute'],
                    $lc['second'],
                    $lc['millisecond'],
                    $lc['microsecond'],
                    $lc['nanosecond'],
                    $this->timeZoneId,
                    $this->calendarId,
                    'compatible',
                );
            }

            // Step 1: Resolve new date + original time to intermediate epoch.
            $epochDays = CalendarMath::toJulianDay($newYear, $newMonth, $newDay) - 2_440_588;
            $wallSec = ($epochDays * 86_400) + ($lc['hour'] * 3600) + ($lc['minute'] * 60) + $lc['second'];
            $intermediateEpochSec = TimeZoneHelper::wallSecToEpochSec(
                $wallSec,
                $this->resolvedTimeZoneId,
                'compatible',
            );
            $intermediateSubNs =
                ($lc['millisecond'] * EpochLimits::NS_PER_MILLISECOND)
                + ($lc['microsecond'] * EpochLimits::NS_PER_MICROSECOND)
                + $lc['nanosecond'];

            // Step 2: Add time nanoseconds to the epoch.
            $totalSubNs = $intermediateSubNs + $timeNs;
            $overflowSec = CalendarMath::floorDiv($totalSubNs, EpochLimits::NS_PER_SECOND);
            $resultSubNs = $totalSubNs - ($overflowSec * EpochLimits::NS_PER_SECOND);
            $resultEpochSec = $intermediateEpochSec + $overflowSec;

            return self::fromEpochParts($resultEpochSec, $resultSubNs, $this->timeZoneId, $this->calendarId);
        }

        // Pure time units: balance to days + sub-day ns to avoid int64 overflow.
        // Step-by-step carry approach (same as PlainDateTime).
        $hDays = intdiv(num1: $hours, num2: 24);
        $hRem = $hours % 24;

        $totalMin = ($hRem * 60) + $minutes;
        $mDays = intdiv(num1: $totalMin, num2: 1_440);
        $mRem = $totalMin % 1_440;

        $totalSec = ($mRem * 60) + $seconds;
        $sDays = intdiv(num1: $totalSec, num2: 86_400);
        $sRem = $totalSec % 86_400;

        $totalMs = ($sRem * 1_000) + $ms;
        $msDays = intdiv(num1: $totalMs, num2: 86_400_000);
        $msRem = $totalMs % 86_400_000;

        $totalUs = ($msRem * 1_000) + $us;
        $usDays = intdiv(num1: $totalUs, num2: 86_400_000_000);
        $usRem = $totalUs % 86_400_000_000;

        $totalNsRem = ($usRem * 1_000) + $ns;
        $nsDays = intdiv(num1: $totalNsRem, num2: 86_400_000_000_000);
        $nsRem = $totalNsRem % 86_400_000_000_000;

        $totalDays = $hDays + $mDays + $sDays + $msDays + $usDays + $nsDays;

        // Convert days to epoch seconds and add the sub-day ns.
        [$epochSec, $subNsOrig] = $this->epochParts();

        $newEpochSec = $epochSec + ($totalDays * 86_400);
        $newSubNs = $subNsOrig + $nsRem;

        // Carry from sub-ns.
        if ($newSubNs >= EpochLimits::NS_PER_SECOND) {
            $carry = intdiv(num1: $newSubNs, num2: EpochLimits::NS_PER_SECOND);
            $newEpochSec += $carry;
            $newSubNs -= $carry * EpochLimits::NS_PER_SECOND;
        } elseif ($newSubNs < 0) {
            $carry = (int) ceil(-$newSubNs / EpochLimits::NS_PER_SECOND);
            $newEpochSec -= $carry;
            $newSubNs += $carry * EpochLimits::NS_PER_SECOND;
        }

        return self::fromEpochParts($newEpochSec, $newSubNs, $this->timeZoneId, $this->calendarId);
    }

    /**
     * Converts local date/time components to a ZonedDateTime via the given timezone.
     *
     * Uses JDN-based epoch-day arithmetic to handle extreme years correctly
     * (DateTimeImmutable cannot represent years beyond ~9999 or negative years reliably).
     *
     * @param string $disambiguation 'compatible', 'earlier', 'later', or 'reject'.
     * @psalm-suppress UnusedParam — $disambiguation reserved for future DST-aware resolution
     */
    private static function localToZdt(
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
        $resolvedTzId = self::resolveCanonicalTimezoneId($tzId);
        $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);

        $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;

        return self::fromEpochParts($epochSec, $subNs, $tzId, $calendarId);
    }

    /**
     * Creates a ZonedDateTime from UTC epoch seconds and sub-second nanoseconds.
     *
     * Used by PlainDate::toZonedDateTime() and external callers that have decomposed
     * epoch components and need to construct a ZonedDateTime without re-encoding through
     * an int64 nanosecond intermediate (which overflows for dates near the ISO range
     * boundary). The method handles int64 overflow internally by storing a sentinel
     * epochNanoseconds value while preserving the true epoch seconds for later
     * decomposition.
     */
    public static function createFromEpochParts(
        int $epochSec,
        int $subNs,
        string $tzId,
        string $calendarId = 'iso8601',
    ): self {
        return self::fromEpochParts($epochSec, $subNs, $tzId, $calendarId);
    }

    /**
     * Creates a ZonedDateTime from UTC epoch seconds and sub-second nanoseconds.
     *
     * Handles int64 overflow by storing a sentinel epochNanoseconds value while
     * preserving the true epoch seconds for later decomposition in localComponents().
     */
    private static function fromEpochParts(
        int $epochSec,
        int $subNs,
        string $tzId,
        string $calendarId = 'iso8601',
    ): self {
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

    /**
     * Core diff implementation for ZonedDateTime since() and until().
     *
     * TC39 CalendarDateUntil is always called as (temporalDate, other). For
     * "since", the final result is negated.
     *
     * @param string $operation 'since' or 'until'
     * @param array<array-key, mixed>|object|null $options
     */
    private static function diffZdt(
        self $temporalDate,
        self $other,
        string $operation,
        array|object|null $options,
    ): Duration {
        /** @var list<string> $validUnits */
        static $validUnits = [
            'auto',
            'day',
            'days',
            'week',
            'weeks',
            'month',
            'months',
            'year',
            'years',
            'hour',
            'hours',
            'minute',
            'minutes',
            'second',
            'seconds',
            'millisecond',
            'milliseconds',
            'microsecond',
            'microseconds',
            'nanosecond',
            'nanoseconds',
        ];
        /** @var array<string, int> $unitRank */
        static $unitRank = [
            'year' => 8,
            'years' => 8,
            'month' => 7,
            'months' => 7,
            'week' => 6,
            'weeks' => 6,
            'day' => 5,
            'days' => 5,
            'auto' => 4,
            'hour' => 4,
            'hours' => 4,
            'minute' => 3,
            'minutes' => 3,
            'second' => 2,
            'seconds' => 2,
            'millisecond' => 1,
            'milliseconds' => 1,
            'microsecond' => 1,
            'microseconds' => 1,
            'nanosecond' => 1,
            'nanoseconds' => 1,
        ];

        // Default for ZDT: largestUnit = 'hour' (not 'day').
        $largestUnit = 'hour';
        $largestUnitExplicit = false;
        $smallestUnit = null;
        $roundingMode = 'trunc';
        $roundingIncrement = 1;

        if ($options !== null) {
            $opts = Options::normalizeOptions($options);

            if (array_key_exists('largestUnit', $opts)) {
                /** @var mixed $lu */
                $lu = $opts['largestUnit'];
                if ($lu !== null) {
                    $lu = Options::coerceEnumOption($lu, 'largestUnit');
                }
                if (is_string($lu)) {
                    if (!in_array($lu, $validUnits, strict: true)) {
                        throw new RangeError("Invalid largestUnit value: \"{$lu}\".");
                    }
                    $largestUnit = $lu;
                    $largestUnitExplicit = true;
                }
            }

            if (array_key_exists('roundingIncrement', $opts)) {
                /** @var mixed $ri */
                $ri = $opts['roundingIncrement'];
                if ($ri !== null) {
                    $roundingIncrement = CalendarMath::validateRoundingIncrement($ri);
                }
            }

            if (array_key_exists('roundingMode', $opts)) {
                /** @var mixed $rm */
                $rm = $opts['roundingMode'];
                if ($rm !== null) {
                    $rm = Options::coerceEnumOption($rm, 'roundingMode');
                }
                if (is_string($rm)) {
                    $roundingMode = Options::roundingMode($rm);
                }
            }

            if (array_key_exists('smallestUnit', $opts)) {
                /** @var mixed $su */
                $su = $opts['smallestUnit'];
                if ($su !== null) {
                    $su = Options::coerceEnumOption($su, 'smallestUnit');
                }
                if (is_string($su)) {
                    if (!in_array($su, $validUnits, strict: true)) {
                        throw new RangeError("Invalid smallestUnit value: \"{$su}\".");
                    }
                    $smallestUnit = $su;
                }
            }
        }

        if ($smallestUnit === null) {
            $smallestUnit = 'nanosecond';
        }

        $normLargest = match ($largestUnit) {
            'years' => 'year',
            'months' => 'month',
            'weeks' => 'week',
            'days' => 'day',
            'auto' => 'hour',
            'hours' => 'hour',
            'minutes' => 'minute',
            'seconds' => 'second',
            'milliseconds' => 'millisecond',
            'microseconds' => 'microsecond',
            'nanoseconds' => 'nanosecond',
            default => $largestUnit,
        };
        $normSmallest = match ($smallestUnit) {
            'years' => 'year',
            'months' => 'month',
            'weeks' => 'week',
            'days' => 'day',
            'hours' => 'hour',
            'minutes' => 'minute',
            'seconds' => 'second',
            'milliseconds' => 'millisecond',
            'microseconds' => 'microsecond',
            'nanoseconds' => 'nanosecond',
            default => $smallestUnit,
        };

        /** @var array<string, int> $canonRank */
        static $canonRank = [
            'year' => 10,
            'month' => 9,
            'week' => 8,
            'day' => 7,
            'hour' => 6,
            'minute' => 5,
            'second' => 4,
            'millisecond' => 3,
            'microsecond' => 2,
            'nanosecond' => 1,
        ];
        $suRank = $canonRank[$normSmallest] ?? 1;
        $luRank = $canonRank[$normLargest] ?? 4;

        if ($suRank > $luRank) {
            if ($largestUnitExplicit) {
                throw new RangeError(
                    "smallestUnit \"{$normSmallest}\" cannot be larger than largestUnit \"{$normLargest}\".",
                );
            }
            $normLargest = $normSmallest;
        }

        // Validate roundingIncrement against smallest unit.
        if ($roundingIncrement > 1) {
            /** @var array<string, int> $maxIncrementForUnit */
            static $maxIncrementForUnit = [
                'hour' => 24,
                'minute' => 60,
                'second' => 60,
                'millisecond' => 1000,
                'microsecond' => 1000,
                'nanosecond' => 1000,
            ];
            $maxIncrement = $maxIncrementForUnit[$normSmallest] ?? 0;
            if (
                $maxIncrement > 0
                && ($roundingIncrement >= $maxIncrement || ($maxIncrement % $roundingIncrement) !== 0)
            ) {
                throw new RangeError("roundingIncrement {$roundingIncrement} is invalid for unit \"{$normSmallest}\".");
            }
        }

        // Validate that rounding increment for day units doesn't exceed the date range.
        if ($roundingIncrement > 1 && in_array($normSmallest, ['day', 'week'], strict: true)) {
            $incDays = $normSmallest === 'week' ? $roundingIncrement * 7 : $roundingIncrement;
            $maxEpochDays = 100_000_000;
            // Check both directions from the earlier/later endpoints.
            $recLocal = $temporalDate->localComponents();
            $recEpochDays =
                CalendarMath::toJulianDay($recLocal['year'], $recLocal['month'], $recLocal['day']) - 2_440_588;
            if ((abs($recEpochDays) + $incDays) > $maxEpochDays) {
                throw new RangeError(
                    "roundingIncrement {$roundingIncrement} for unit \"{$normSmallest}\" would exceed the representable date range.",
                );
            }
        }

        $isCalendarLargest = in_array($normLargest, ['year', 'month', 'week', 'day'], strict: true);

        // TC39: for calendar-largest units, require matching canonical timezones.
        if (
            $isCalendarLargest
            && self::canonicalizeTimezoneForComparison($temporalDate->timeZoneId) !== self::canonicalizeTimezoneForComparison($other->timeZoneId)
        ) {
            throw new RangeError(
                "Cannot compute {$operation}() with largestUnit '{$normLargest}' between different timezones.",
            );
        }

        // Epoch ns difference: other − temporalDate.
        // Positive when other > temporalDate (the "until" direction).
        $diffNs = self::diffEpochNs($temporalDate, $other);

        // Overall sign.
        $sign = $diffNs <=> 0;

        // For "since", negate the output sign per TC39 spec.
        $outputSign = $operation === 'since' ? -$sign : $sign;

        // Negate directional rounding modes for negative output durations so that
        // floor/ceil behave correctly toward -infinity/+infinity.
        $effectiveMode = $outputSign < 0 ? self::negateRoundingMode($roundingMode) : $roundingMode;

        if ($isCalendarLargest) {
            // Use local date/time fields for calendar-aware diff.
            $tdLocal = $temporalDate->localComponents();
            $otherLocal = $other->localComponents();

            // Assign earlier/later so we always diff in the positive direction.
            if ($sign >= 0) {
                $earlierLocal = $tdLocal;
                $laterLocal = $otherLocal;
            } else {
                $earlierLocal = $otherLocal;
                $laterLocal = $tdLocal;
            }

            // Date diff in JDN.
            $laterJdn = CalendarMath::toJulianDay($laterLocal['year'], $laterLocal['month'], $laterLocal['day']);
            $earlierJdn = CalendarMath::toJulianDay(
                $earlierLocal['year'],
                $earlierLocal['month'],
                $earlierLocal['day'],
            );
            $laterTimeNs =
                ($laterLocal['hour'] * 3_600_000_000_000)
                + ($laterLocal['minute'] * 60_000_000_000)
                + ($laterLocal['second'] * EpochLimits::NS_PER_SECOND)
                + ($laterLocal['millisecond'] * EpochLimits::NS_PER_MILLISECOND)
                + ($laterLocal['microsecond'] * EpochLimits::NS_PER_MICROSECOND)
                + $laterLocal['nanosecond'];
            $earlierTimeNs =
                ($earlierLocal['hour'] * 3_600_000_000_000)
                + ($earlierLocal['minute'] * 60_000_000_000)
                + ($earlierLocal['second'] * EpochLimits::NS_PER_SECOND)
                + ($earlierLocal['millisecond'] * EpochLimits::NS_PER_MILLISECOND)
                + ($earlierLocal['microsecond'] * EpochLimits::NS_PER_MICROSECOND)
                + $earlierLocal['nanosecond'];

            $dateDiff = $laterJdn - $earlierJdn;
            $timeDiffNs = $laterTimeNs - $earlierTimeNs;

            // Borrow one day if time part is negative.
            if ($timeDiffNs < 0) {
                $dateDiff--;
                $timeDiffNs += 86_400_000_000_000;
            }

            // Calendar diff. adjOtherJdn is the adjusted other date after borrow.
            $adjOtherJdn = $earlierJdn + $dateDiff;
            [$adjY2, $adjM2, $adjD2] = CalendarMath::fromJulianDay($adjOtherJdn);
            $calId = $temporalDate->calendarId;
            $tc39AdjJdn = null;

            if ($normLargest === 'day') {
                $days = $dateDiff;
                [$years, $months, $weeks] = [0, 0, 0];
            } elseif ($normLargest === 'week') {
                $weeks = intdiv(num1: $dateDiff, num2: 7);
                $days = $dateDiff - ($weeks * 7);
                [$years, $months] = [0, 0];
            } elseif ($calId !== 'iso8601') {
                // TC39 CalendarDateUntil(temporalDate, adjustedOther) — always
                // in (this, other) order. Compute adjustedOther per TC39
                // DifferenceISODateTime: only borrow when signs conflict.
                $tdJdn = CalendarMath::toJulianDay($tdLocal['year'], $tdLocal['month'], $tdLocal['day']);
                $otherJdn2 = CalendarMath::toJulianDay($otherLocal['year'], $otherLocal['month'], $otherLocal['day']);
                $rawTdTimeNs =
                    ($tdLocal['hour'] * 3_600_000_000_000)
                    + ($tdLocal['minute'] * 60_000_000_000)
                    + ($tdLocal['second'] * EpochLimits::NS_PER_SECOND)
                    + ($tdLocal['millisecond'] * EpochLimits::NS_PER_MILLISECOND)
                    + ($tdLocal['microsecond'] * EpochLimits::NS_PER_MICROSECOND)
                    + $tdLocal['nanosecond'];
                $rawOtherTimeNs =
                    ($otherLocal['hour'] * 3_600_000_000_000)
                    + ($otherLocal['minute'] * 60_000_000_000)
                    + ($otherLocal['second'] * EpochLimits::NS_PER_SECOND)
                    + ($otherLocal['millisecond'] * EpochLimits::NS_PER_MILLISECOND)
                    + ($otherLocal['microsecond'] * EpochLimits::NS_PER_MICROSECOND)
                    + $otherLocal['nanosecond'];
                $rawTD = $rawOtherTimeNs - $rawTdTimeNs;
                $tS = $rawTD <=> 0;
                $dS = $tdJdn <=> $otherJdn2;
                $tc39AdjJdn = $otherJdn2;
                if ($tS !== 0 && $tS === -$dS) {
                    $tc39AdjJdn = $otherJdn2 - $tS;
                }
                [$tc39Y, $tc39M, $tc39D] = CalendarMath::fromJulianDay($tc39AdjJdn);
                $cal = CalendarFactory::get($calId);
                [$years, $months, , $days] = $cal->dateUntil(
                    $tdLocal['year'],
                    $tdLocal['month'],
                    $tdLocal['day'],
                    $tc39Y,
                    $tc39M,
                    $tc39D,
                    $normLargest,
                );
                $years = abs($years);
                $months = abs($months);
                $days = abs($days);
                $weeks = 0;
            } else {
                // ISO calendar: calendarDiff expects (smaller, larger).
                $receiverIsLater = $sign < 0;
                [$years, $months, $days] = self::calendarDiff(
                    $earlierLocal['year'],
                    $earlierLocal['month'],
                    $earlierLocal['day'],
                    $adjY2,
                    $adjM2,
                    $adjD2,
                    $receiverIsLater,
                );
                $weeks = 0;
            }

            // Convert years to months when largestUnit is 'month'.
            if ($normLargest === 'month') {
                $months = ($years * 12) + $months;
                $years = 0;
            }

            // TC39 DifferenceZonedDateTime: recompute timeDiffNs using actual
            // epoch arithmetic when the timezone is an IANA zone (not fixed-offset).
            // This correctly handles DST transitions where wall-clock time
            // differs from elapsed time.
            $tzForRecompute = $temporalDate->timeZoneId;
            $isIanaTz = $tzForRecompute !== 'UTC' && preg_match('/^[+\-]\d{2}:\d{2}$/', $tzForRecompute) !== 1;
            if ($isIanaTz && ($years !== 0 || $months !== 0 || $weeks !== 0 || $days !== 0)) {
                // Add date portion to the earlier ZDT, measure remaining ns.
                $earlierZ = $sign >= 0 ? $temporalDate : $other;
                $laterZ = $sign >= 0 ? $other : $temporalDate;
                $intermediate = $earlierZ->add(new Duration(
                    years: $years,
                    months: $months,
                    weeks: $weeks,
                    days: $days,
                ));
                [$intSec, $intSub] = $intermediate->epochParts();
                [$latSec, $latSub] = $laterZ->epochParts();
                $recomputedNs = (($latSec - $intSec) * 1_000_000_000) + ($latSub - $intSub);
                if ($recomputedNs >= 0) {
                    $timeDiffNs = $recomputedNs;
                } elseif ($days > 0) {
                    // Negative time means the date portion overshot (DST gap at
                    // the intermediate date). Reduce days by 1 and recompute.
                    $days--;
                    $intermediate2 = $earlierZ->add(new Duration(
                        years: $years,
                        months: $months,
                        weeks: $weeks,
                        days: $days,
                    ));
                    [$intSec2, $intSub2] = $intermediate2->epochParts();
                    $recomputedNs2 = (($latSec - $intSec2) * 1_000_000_000) + ($latSub - $intSub2);
                    if ($recomputedNs2 >= 0) {
                        $timeDiffNs = $recomputedNs2;
                    }
                }
            } elseif ($isIanaTz) {
                // Same date, no date diff: use raw epoch diff for the time part.
                $absDiffNsSameDay = $sign < 0 ? -$diffNs : $diffNs;
                if ($absDiffNsSameDay >= 0) {
                    $timeDiffNs = $absDiffNsSameDay;
                }
            }

            $isSmallestCalendar = in_array($normSmallest, ['year', 'month', 'week', 'day'], strict: true);

            // The receiver (temporalDate) is the later date when sign < 0.
            $receiverIsLater = $sign < 0;

            // For rounding, determine earlier/later local components.
            if ($sign >= 0) {
                $earlierLocal = $tdLocal;
                $laterLocal = $otherLocal;
            } else {
                $earlierLocal = $otherLocal;
                $laterLocal = $tdLocal;
            }

            // For IANA timezones, compute the actual day length at the intermediate
            // date (after adding date portion). This is needed for DST-aware
            // progress computation where 24h might not equal 1 day.
            $nsPerDayF = 86_400_000_000_000.0;
            if ($isIanaTz && ($years !== 0 || $months !== 0 || $weeks !== 0 || $days !== 0)) {
                try {
                    $earlierZ3 = $sign >= 0 ? $temporalDate : $other;
                    $intermediate3 = $earlierZ3->add(new Duration(
                        years: $years,
                        months: $months,
                        weeks: $weeks,
                        days: $days,
                    ));
                    $actualHours = $intermediate3->hoursInDay;
                    if ($actualHours !== 24 && $actualHours > 0) {
                        $nsPerDayF = (float) $actualHours * 3_600_000_000_000.0;
                    }
                } catch (\Throwable $e) {
                    // Keep default 24h
                    unset($e);
                }
            }

            if ($isSmallestCalendar) {
                // Calendar-unit rounding: zero out time and round the calendar part.

                // Receiver's local components for calendar-aware rounding.
                $recLocal = $tdLocal;

                if ($normSmallest === 'year') {
                    $floorCount = intdiv(num1: $years, num2: $roundingIncrement) * $roundingIncrement;

                    $progress = self::calcYearProgress(
                        $recLocal,
                        $earlierLocal,
                        $laterLocal,
                        $floorCount,
                        $roundingIncrement,
                        $days,
                        $timeDiffNs,
                        $receiverIsLater,
                    );
                    $roundUp = CalendarMath::applyCalendarRoundingProgress(
                        $years,
                        $progress,
                        $roundingIncrement,
                        $effectiveMode,
                    );
                    $roundedYears = $roundUp ? $floorCount + $roundingIncrement : $floorCount;
                    return new Duration(years: $outputSign * $roundedYears);
                }
                if ($normSmallest === 'month') {
                    $totalMonths = ($years * 12) + $months;
                    $floorCount = intdiv(num1: $totalMonths, num2: $roundingIncrement) * $roundingIncrement;

                    $progress = self::calcMonthProgress(
                        $recLocal,
                        $earlierLocal,
                        $laterLocal,
                        $floorCount,
                        $roundingIncrement,
                        $days,
                        $timeDiffNs,
                        $receiverIsLater,
                    );
                    $roundUp = CalendarMath::applyCalendarRoundingProgress(
                        $totalMonths,
                        $progress,
                        $roundingIncrement,
                        $effectiveMode,
                    );
                    $roundedMonths = $roundUp ? $floorCount + $roundingIncrement : $floorCount;
                    if ($normLargest === 'year') {
                        $ry = intdiv(num1: $roundedMonths, num2: 12);
                        $rm = $roundedMonths - ($ry * 12);
                        return new Duration(years: $outputSign * $ry, months: $outputSign * $rm);
                    }
                    return new Duration(months: $outputSign * $roundedMonths);
                }
                if ($normSmallest === 'week') {
                    $totalDays = ($weeks * 7) + $days;
                    $progress = $timeDiffNs > 0 ? (float) $timeDiffNs / $nsPerDayF : 0.0;
                    $weekDays = $totalDays;
                    $weekIncrement = $roundingIncrement * 7;
                    $roundUp = CalendarMath::applyCalendarRoundingProgress(
                        $weekDays,
                        $progress,
                        $weekIncrement,
                        $effectiveMode,
                    );
                    $q = intdiv(num1: $weekDays, num2: $weekIncrement);
                    $roundedDays = $roundUp ? ($q + 1) * $weekIncrement : $q * $weekIncrement;
                    // Preserve the years/months from the date difference. Per TC39
                    // NudgeToCalendarUnit (unit=week), the years+months portion is held fixed
                    // (AdjustDateDurationRecord(duration.[[Date]], 0, 0)) and only the
                    // weeks+days remainder is rounded. With largestUnit=month/year these can be
                    // nonzero; dropping them lost a whole month (e.g. P1M weeks..months → 0).
                    // For largestUnit=week they are already 0, so this is a no-op there.
                    return new Duration(
                        years: $outputSign * $years,
                        months: $outputSign * $months,
                        weeks: $outputSign * intdiv(num1: $roundedDays, num2: 7),
                    );
                }
                // normSmallest === 'day'
                $progress = $timeDiffNs > 0 ? (float) $timeDiffNs / $nsPerDayF : 0.0;
                $roundUp = CalendarMath::applyCalendarRoundingProgress(
                    $days,
                    $progress,
                    $roundingIncrement,
                    $effectiveMode,
                );
                $q = intdiv(num1: $days, num2: $roundingIncrement);
                $roundedDays = $roundUp ? ($q + 1) * $roundingIncrement : $q * $roundingIncrement;
                if ($normLargest === 'day') {
                    return new Duration(days: $outputSign * $roundedDays);
                }
                if ($normLargest === 'week') {
                    $totalDays = ($weeks * 7) + $roundedDays;
                    $roundedWeeks = intdiv(num1: $totalDays, num2: 7);
                    $remDays = $totalDays - ($roundedWeeks * 7);
                    return new Duration(weeks: $outputSign * $roundedWeeks, days: $outputSign * $remDays);
                }
                return new Duration(
                    years: $outputSign * $years,
                    months: $outputSign * $months,
                    days: $outputSign * $roundedDays,
                );
            }

            // smallestUnit is a time unit but largestUnit is a calendar unit.
            $absTimeNs = $timeDiffNs;
            $nsPerSmallest = match ($normSmallest) {
                'hour' => 3_600_000_000_000,
                'minute' => 60_000_000_000,
                'second' => EpochLimits::NS_PER_SECOND,
                'millisecond' => EpochLimits::NS_PER_MILLISECOND,
                'microsecond' => EpochLimits::NS_PER_MICROSECOND,
                default => 1,
            };
            /** @psalm-var int<1, 1000> $roundingIncrement */
            $nsIncrement = $nsPerSmallest * $roundingIncrement;
            $absTimeNs = EpochRounding::roundAsIfPositive($absTimeNs, $nsIncrement, $effectiveMode);

            // Handle day overflow from rounding time.
            // Use DST-aware day length for IANA timezones.
            $nsPerDayForOverflow = (int) $nsPerDayF;
            $overflowDays = intdiv(num1: $absTimeNs, num2: $nsPerDayForOverflow);
            $absTimeNs %= $nsPerDayForOverflow;
            $days += $overflowDays;

            // Re-balance calendar units when day overflow pushes past month boundaries.
            if ($overflowDays > 0 && in_array($normLargest, ['year', 'month'], strict: true)) {
                if ($calId !== 'iso8601') {
                    // Non-ISO: shift tc39AdjJdn by overflow in the diff direction.
                    // $tc39AdjJdn was assigned in the earlier non-ISO branch above.
                    assert($tc39AdjJdn !== null, description: 'non-ISO branch above must have defined $tc39AdjJdn');
                    $tc39Jdn2 = $tc39AdjJdn + ($sign >= 0 ? $overflowDays : -$overflowDays);
                    [$anchorY, $anchorM, $anchorD] = CalendarMath::fromJulianDay($tc39Jdn2);
                    $cal2 = CalendarFactory::get($calId);
                    [$years, $months, , $days] = $cal2->dateUntil(
                        $tdLocal['year'],
                        $tdLocal['month'],
                        $tdLocal['day'],
                        $anchorY,
                        $anchorM,
                        $anchorD,
                        $normLargest,
                    );
                    $years = abs($years);
                    $months = abs($months);
                    $days = abs($days);
                } else {
                    // ISO: use swap-based adjOtherJdn + overflow.
                    $isoAdjJdn2 = $adjOtherJdn + $overflowDays;
                    [$anchorY, $anchorM, $anchorD] = CalendarMath::fromJulianDay($isoAdjJdn2);
                    [$years, $months, $days] = self::calendarDiff(
                        $earlierLocal['year'],
                        $earlierLocal['month'],
                        $earlierLocal['day'],
                        $anchorY,
                        $anchorM,
                        $anchorD,
                        $sign < 0,
                    );
                }
                if ($normLargest === 'month') {
                    $months = ($years * 12) + $months;
                    $years = 0;
                }
                $weeks = 0;
            }

            $h = intdiv(num1: $absTimeNs, num2: 3_600_000_000_000);
            $rem = $absTimeNs % 3_600_000_000_000;
            $min = intdiv(num1: $rem, num2: 60_000_000_000);
            $rem %= 60_000_000_000;
            $sec = intdiv(num1: $rem, num2: EpochLimits::NS_PER_SECOND);
            $rem %= EpochLimits::NS_PER_SECOND;
            $msR = intdiv(num1: $rem, num2: EpochLimits::NS_PER_MILLISECOND);
            $rem %= EpochLimits::NS_PER_MILLISECOND;
            $usR = intdiv(num1: $rem, num2: EpochLimits::NS_PER_MICROSECOND);
            $nsR = $rem % EpochLimits::NS_PER_MICROSECOND;

            return new Duration(
                years: $outputSign * $years,
                months: $outputSign * $months,
                weeks: $outputSign * $weeks,
                days: $outputSign * $days,
                hours: $outputSign * $h,
                minutes: $outputSign * $min,
                seconds: $outputSign * $sec,
                milliseconds: $outputSign * $msR,
                microseconds: $outputSign * $usR,
                nanoseconds: $outputSign * $nsR,
            );
        }

        // Time-only units: hybrid (sec, subNs) decomposition. Avoids the int64
        // overflow that would otherwise occur for spans approaching the spec's
        // representable range (~±275,760 years from epoch). diffEpochNs returns
        // a PHP_INT_MIN/MAX sentinel for those spans, and `-PHP_INT_MIN` overflows
        // to float; sourcing the diff directly from (sec, subNs) sidesteps both.
        [$tdSec, $tdSubNs] = $temporalDate->epochParts();
        [$otherSec, $otherSubNs] = $other->epochParts();
        $absDiffSec = $sign < 0 ? $tdSec - $otherSec : $otherSec - $tdSec;
        $absDiffSubNs = $sign < 0 ? $tdSubNs - $otherSubNs : $otherSubNs - $tdSubNs;
        // Borrow if subNs is negative.
        if ($absDiffSubNs < 0) {
            $absDiffSec--;
            $absDiffSubNs += EpochLimits::NS_PER_SECOND;
        }
        // Now: $absDiffSec >= 0 and 0 <= $absDiffSubNs < NS_PER_SECOND. Both fit
        // int64 since |epochSec| < 8.64×10¹² (the spec range).

        $nsPerSmallest = match ($normSmallest) {
            'hour' => 3_600_000_000_000,
            'minute' => 60_000_000_000,
            'second' => EpochLimits::NS_PER_SECOND,
            'millisecond' => EpochLimits::NS_PER_MILLISECOND,
            'microsecond' => EpochLimits::NS_PER_MICROSECOND,
            default => 1,
        };
        /** @psalm-var int<1, 1000> $roundingIncrement */
        $nsIncrement = $nsPerSmallest * $roundingIncrement;

        // Round the non-negative (absDiffSec, absDiffSubNs) pair by nsIncrement. The
        // shared helper dispatches both regimes internally: a strictly sub-second
        // increment rounds only the sub-second remainder (carrying into seconds), while
        // a second-or-coarser increment rounds in the seconds domain so the combined
        // nanosecond value never has to fit int64. Inputs are pre-absoluted above
        // (absDiffSec ≥ 0, absDiffSubNs in [0, 1e9)), matching the helper's contract.
        [$absDiffSec, $absDiffSubNs] = EpochRounding::round($absDiffSec, $absDiffSubNs, $nsIncrement, $effectiveMode);

        /** @var array<string,int> $timeUnitRank */
        static $timeUnitRank = [
            'hour' => 6,
            'minute' => 5,
            'second' => 4,
            'millisecond' => 3,
            'microsecond' => 2,
            'nanosecond' => 1,
        ];
        $luTimeRank = $timeUnitRank[$normLargest] ?? 6;

        // Decompose (absDiffSec, absDiffSubNs) into time units. The seconds part
        // covers hour/minute/second; the sub-second part covers ms/µs/ns. Both
        // halves stay in int64 throughout.
        $h = $luTimeRank >= 6 ? intdiv(num1: $absDiffSec, num2: 3_600) : 0;
        $remSec = $luTimeRank >= 6 ? $absDiffSec % 3_600 : $absDiffSec;
        $min = $luTimeRank >= 5 ? intdiv(num1: $remSec, num2: 60) : 0;
        $remSec = $luTimeRank >= 5 ? $remSec % 60 : $remSec;
        $sec = $luTimeRank >= 4 ? $remSec : 0;
        $rem = $luTimeRank >= 4 ? $absDiffSubNs : ($remSec * EpochLimits::NS_PER_SECOND) + $absDiffSubNs;
        $msR = $luTimeRank >= 3 ? intdiv(num1: $rem, num2: EpochLimits::NS_PER_MILLISECOND) : 0;
        $rem = $luTimeRank >= 3 ? $rem % EpochLimits::NS_PER_MILLISECOND : $rem;
        $usR = $luTimeRank >= 2 ? intdiv(num1: $rem, num2: EpochLimits::NS_PER_MICROSECOND) : 0;
        $nsR = $luTimeRank >= 2 ? $rem % EpochLimits::NS_PER_MICROSECOND : $rem;

        return new Duration(
            hours: $outputSign * $h,
            minutes: $outputSign * $min,
            seconds: $outputSign * $sec,
            milliseconds: $outputSign * $msR,
            microseconds: $outputSign * $usR,
            nanoseconds: $outputSign * $nsR,
        );
    }

    /**
     * Calendar-aware year/month/day breakdown between two dates.
     *
     * @param int<1, 12> $m1
     * @param int<1, 12> $m2
     * @return array{0: int, 1: int, 2: int} [years, months, days] — all non-negative.
     */
    private static function calendarDiff(
        int $y1,
        int $m1,
        int $d1,
        int $y2,
        int $m2,
        int $d2,
        bool $receiverIsY2 = true,
    ): array {
        $sign = $y2 > $y1 || $y2 === $y1 && ($m2 > $m1 || $m2 === $m1 && $d2 >= $d1) ? 1 : -1;

        $receiverIsY2AfterSwap = $receiverIsY2;

        if ($sign < 0) {
            [$y1, $m1, $d1, $y2, $m2, $d2] = [$y2, $m2, $d2, $y1, $m1, $d1];
            $receiverIsY2AfterSwap = !$receiverIsY2;
        }

        $years = $y2 - $y1;
        $months = $m2 - $m1;

        if ($months < 0) {
            $years--;
            $months += 12;
        }

        if ($d2 < $d1) {
            if ($months > 0) {
                $months--;
            } else {
                $years--;
                $months = 11;
            }
        }

        if ($receiverIsY2AfterSwap) {
            $anchorMonth = $m2 - $months;
            $anchorYear = $y2 - $years;
            if ($anchorMonth <= 0) {
                $anchorYear--;
                $anchorMonth += 12;
            }
            $anchorMaxDay = CalendarMath::calcDaysInMonth($anchorYear, $anchorMonth);
            $anchorDay = min($d2, $anchorMaxDay);
            $days =
                CalendarMath::toJulianDay($anchorYear, $anchorMonth, $anchorDay)
                - CalendarMath::toJulianDay($y1, $m1, $d1);
        } else {
            $anchorMonth = $m1 + $months;
            $anchorYear = $y1 + $years;
            if ($anchorMonth > 12) {
                $anchorYear++;
                $anchorMonth -= 12;
            }
            $anchorMaxDay = CalendarMath::calcDaysInMonth($anchorYear, $anchorMonth);
            $anchorDay = min($d1, $anchorMaxDay);
            $days =
                CalendarMath::toJulianDay($y2, $m2, $d2)
                - CalendarMath::toJulianDay($anchorYear, $anchorMonth, $anchorDay);
        }

        return [$sign * $years, $sign * $months, $sign * $days];
    }

    /**
     * Rounds a nanosecond offset within a day for day-level rounding.
     *
     * Uses the actual day length (which may differ from 86400s due to DST).
     */
    private static function roundDayNs(int $offsetNs, int $dayLengthNs, string $mode): int
    {
        if ($mode === 'halfEven') {
            $cmp = $offsetNs * 2;
            if ($cmp < $dayLengthNs) {
                return 0;
            }
            return $cmp > $dayLengthNs ? $dayLengthNs : 0;
        }
        return match ($mode) {
            'trunc', 'floor' => 0,
            'ceil', 'expand' => $offsetNs === 0 ? 0 : $dayLengthNs,
            'halfExpand', 'halfCeil' => ($offsetNs * 2) >= $dayLengthNs ? $dayLengthNs : 0,
            'halfTrunc', 'halfFloor' => ($offsetNs * 2) > $dayLengthNs ? $dayLengthNs : 0,
            default => throw new RangeError("Invalid roundingMode \"{$mode}\"."),
        };
    }

    /**
     * Extracts and validates the 'overflow' option.
     *
     * Returns 'constrain' or 'reject', defaulting to 'constrain' when $options is null
     * or has no overflow key. When the key is present the value is coerced/validated by
     * {@see Options::overflowFromBag()} → {@see Options::overflowOption()}: a string
     * keyword passes; a JsSymbol sentinel's throwing __toString surfaces as TypeError;
     * every other type (including null) is a RangeError. This is the canonical
     * contract; PlainTime/PlainDateTime diverge (null value, Symbol-sentinel object)
     * and so encode their own scaffolding around the same helper.
     *
     * @param array<array-key, mixed>|object|null $options
     */
    private static function resolveOverflowOption(array|object|null $options): string
    {
        return Options::overflowFromBag($options);
    }

    /**
     * Extracts and validates the 'disambiguation' option.
     *
     * @param array<array-key, mixed>|object|null $options
     */
    private static function extractDisambiguation(array|object|null $options): string
    {
        if ($options === null) {
            return 'compatible';
        }
        $options = Options::normalizeOptions($options);
        if (!array_key_exists('disambiguation', $options)) {
            return 'compatible';
        }
        /** @var mixed $val */
        $val = $options['disambiguation'];
        $val = Options::coerceEnumOption($val, 'disambiguation');
        if (!in_array(needle: $val, haystack: ['compatible', 'earlier', 'later', 'reject'], strict: true)) {
            throw new RangeError(
                "Invalid disambiguation value \"{$val}\"; must be 'compatible', 'earlier', 'later', or 'reject'.",
            );
        }
        return $val;
    }

    /**
     * Computes the fractional progress for year-level rounding using actual calendar dates.
     *
     * Adds floorYears to the receiver date, then floorYears+increment to get
     * the true interval length, and measures how far the remainder extends.
     *
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $recLocal
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $earlierLocal
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $laterLocal
     */
    private static function calcYearProgress(
        array $recLocal,
        array $earlierLocal,
        array $laterLocal,
        int $floorCount,
        int $increment,
        int $days,
        int $timeDiffNs,
        bool $receiverIsLater,
    ): float {
        $nsPerDayF = 86_400_000_000_000.0;
        if ($receiverIsLater) {
            // Anchor from the later date backward.
            $floorDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                -$floorCount,
                0,
            );
            $nextDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                -($floorCount + $increment),
                0,
            );
            // Remaining: from earlier to the floor anchor.
            $floorJdn = CalendarMath::toJulianDay($floorDate[0], $floorDate[1], $floorDate[2]);
            $earlierJdn = CalendarMath::toJulianDay(
                $earlierLocal['year'],
                $earlierLocal['month'],
                $earlierLocal['day'],
            );
            $remDays = $floorJdn - $earlierJdn;
        } else {
            // Anchor from the earlier date forward.
            $floorDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                $floorCount,
                0,
            );
            $nextDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                $floorCount + $increment,
                0,
            );
            // Remaining: from the floor anchor to the later date.
            $floorJdn = CalendarMath::toJulianDay($floorDate[0], $floorDate[1], $floorDate[2]);
            $laterJdn = CalendarMath::toJulianDay($laterLocal['year'], $laterLocal['month'], $laterLocal['day']);
            $remDays = $laterJdn - $floorJdn;
        }
        $nextJdn = CalendarMath::toJulianDay($nextDate[0], $nextDate[1], $nextDate[2]);
        $intervalDays = abs($nextJdn - $floorJdn);

        $totalRemNs = (float) (($remDays * 86_400_000_000_000) + $timeDiffNs);
        return $intervalDays > 0 ? $totalRemNs / ((float) $intervalDays * $nsPerDayF) : 0.0;
    }

    /**
     * Computes the fractional progress for month-level rounding using actual calendar dates.
     *
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $recLocal
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $earlierLocal
     * @param array{year:int,month:int,day:int,hour:int,minute:int,second:int,millisecond:int,microsecond:int,nanosecond:int,offsetSec:int,offset:string} $laterLocal
     */
    private static function calcMonthProgress(
        array $recLocal,
        array $earlierLocal,
        array $laterLocal,
        int $floorCount,
        int $increment,
        int $days,
        int $timeDiffNs,
        bool $receiverIsLater,
    ): float {
        $nsPerDayF = 86_400_000_000_000.0;
        if ($receiverIsLater) {
            $floorDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                0,
                -$floorCount,
            );
            $nextDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                0,
                -($floorCount + $increment),
            );
            $floorJdn = CalendarMath::toJulianDay($floorDate[0], $floorDate[1], $floorDate[2]);
            $earlierJdn = CalendarMath::toJulianDay(
                $earlierLocal['year'],
                $earlierLocal['month'],
                $earlierLocal['day'],
            );
            $remDays = $floorJdn - $earlierJdn;
        } else {
            $floorDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                0,
                $floorCount,
            );
            $nextDate = self::addYearsMonthsToDate(
                $recLocal['year'],
                $recLocal['month'],
                $recLocal['day'],
                0,
                $floorCount + $increment,
            );
            $floorJdn = CalendarMath::toJulianDay($floorDate[0], $floorDate[1], $floorDate[2]);
            $laterJdn = CalendarMath::toJulianDay($laterLocal['year'], $laterLocal['month'], $laterLocal['day']);
            $remDays = $laterJdn - $floorJdn;
        }
        $nextJdn = CalendarMath::toJulianDay($nextDate[0], $nextDate[1], $nextDate[2]);
        $intervalDays = abs($nextJdn - $floorJdn);

        $totalRemNs = (float) (($remDays * 86_400_000_000_000) + $timeDiffNs);
        return $intervalDays > 0 ? $totalRemNs / ((float) $intervalDays * $nsPerDayF) : 0.0;
    }

    /**
     * Adds years and months to a date, clamping the day to the new month's max.
     *
     * @return array{0:int, 1:int, 2:int} [year, month, day]
     */
    private static function addYearsMonthsToDate(int $year, int $month, int $day, int $addYears, int $addMonths): array
    {
        $newYear = $year + $addYears;
        $newMonth = $month + $addMonths;
        if ($newMonth > 12) {
            $newYear += intdiv(num1: $newMonth - 1, num2: 12);
            $newMonth = (($newMonth - 1) % 12) + 1;
        } elseif ($newMonth < 1) {
            $newYear += intdiv(num1: $newMonth - 12, num2: 12);
            $newMonth = (((($newMonth - 1) % 12) + 12) % 12) + 1;
        }
        $maxDay = CalendarMath::calcDaysInMonth($newYear, $newMonth);
        return [$newYear, $newMonth, min($day, $maxDay)];
    }

    /**
     * Negates directional rounding modes for use on absolute values of negative durations.
     *
     * Symmetric modes (trunc, expand, halfTrunc, halfExpand, halfEven) are unchanged.
     */
    private static function negateRoundingMode(string $mode): string
    {
        return match ($mode) {
            'floor' => 'ceil',
            'ceil' => 'floor',
            'halfFloor' => 'halfCeil',
            'halfCeil' => 'halfFloor',
            default => $mode,
        };
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
    protected function toLocaleTimestamp(): int
    {
        // ZonedDateTime has its own toLocaleString implementation and does not
        // rely on the trait's default formatter path; this value is not consumed
        // by that override, but the trait contract requires it.
        [$epochSec] = $this->epochParts();
        return $epochSec;
    }
}
