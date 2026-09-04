<?php

declare(strict_types=1);

namespace Calendrics\Spec;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\Internal\CalendarMath;
use Calendrics\Spec\Internal\EpochLimits;
use Calendrics\Spec\Internal\EpochRounding;
use Calendrics\Spec\Internal\EpochValue;
use Calendrics\Spec\Internal\FieldBag;
use Calendrics\Spec\Internal\HasEpochParts;
use Calendrics\Spec\Internal\HasStringRepresentations;
use Calendrics\Spec\Internal\IntlFormatter;
use Calendrics\Spec\Internal\LocaleComponentMode;
use Calendrics\Spec\Internal\Options;
use Calendrics\Spec\Internal\TimeZoneHelper;
use Calendrics\Spec\Internal\ZonedArithmetic;
use Calendrics\Spec\Internal\ZonedDifference;
use Calendrics\Spec\Internal\ZonedFields;
use Calendrics\Spec\Internal\ZonedParse;
use Calendrics\Spec\Internal\ZoneOffsets;
use Stringable;

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
    use HasStringRepresentations;

    private const int MS_PER_SECOND = 1_000;

    // Stands in for JS `undefined` on an optional argument whose omitted behavior
    // differs from its behavior for JS null. A null default would collapse the two,
    // which is what GetOptionsObject and ToTemporalTime distinguish.
    private const string OMITTED = "\0omitted";

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
            return CalendarFactory::get($this->calendarId)->year($c['year'], $c['month'], $c['day']);
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
            return CalendarFactory::get($this->calendarId)->month($c['year'], $c['month'], $c['day']);
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
            return CalendarFactory::get($this->calendarId)->day($c['year'], $c['month'], $c['day']);
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
            return CalendarFactory::get($this->calendarId)->dayOfYear($c['year'], $c['month'], $c['day']);
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
        // An int argument is the instant exactly, sentinel-valued or not: PHP_INT_MIN and
        // PHP_INT_MAX are ordinary nanosecond counts here, and stamping their true parts
        // keeps them from reading as the over-int64 clamp markers of the same value.
        $epoch = EpochValue::fromNanoseconds($epochNanoseconds);
        $this->epochNanoseconds = $epoch->epochNanoseconds;
        $this->applyEpoch($epoch);
        $this->timeZoneId = TimeZoneHelper::normalizeTimezoneId($timeZoneId, true);
        $this->calendarId = CalendarFactory::canonicalize($calendarId);
        $this->resolvedTimeZoneId = ZoneOffsets::canonicalize($this->timeZoneId);
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
     * @param array<array-key, mixed>|object $options Options array; supports 'disambiguation' (string).
     * @throws TypeError              for unsupported types.
     * @throws RangeError for invalid strings or property bags.
     * @psalm-api
     */
    public static function from(string|array|object $item, mixed $options = []): self
    {
        // Each branch of ToTemporalZonedDateTime reaches GetOptionsObject at a different
        // point, and the difference is observable on an options bag with accessors:
        // a string is PARSED first (so a malformed one is a RangeError before any option
        // is read), and a property bag is READ first (PrepareCalendarFields precedes
        // GetOptionsObject). Only the already-a-ZonedDateTime case reads options straight
        // away, having no fields to prepare.
        if ($item instanceof self) {
            ZonedFields::fromOptions($options);
            // Copy through the true epoch parts, not the public nanosecond field: the
            // field clamps for an over-int64 instant, and rebuilding from it would move
            // the copy to the clamp instead of reproducing the original.
            [$epochSec, $subNs] = $item->epochParts();
            return self::fromEpochParts($epochSec, $subNs, $item->timeZoneId, $item->calendarId);
        }
        if (is_string($item)) {
            // ZonedParse::parse reaches GetOptionsObject only once the string has parsed.
            return ZonedParse::parse($item, $options);
        }

        $bag = FieldBag::forCalendarType($item, ZonedFields::CALENDAR_FIELDS, ['offset', 'timeZone'], 'ZonedDateTime');
        $opts = ZonedFields::fromOptions($options);

        $overflow = array_key_exists('overflow', $opts) && is_string($opts['overflow'])
            ? $opts['overflow']
            : 'constrain';
        $disambiguation = array_key_exists('disambiguation', $opts) && is_string($opts['disambiguation'])
            ? $opts['disambiguation']
            : 'compatible';
        $offsetOption = array_key_exists('offset', $opts) && is_string($opts['offset']) ? $opts['offset'] : 'reject';

        return ZonedFields::fromBag($bag, $overflow, $disambiguation, $offsetOption);
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
     * Called with no argument the time is set to midnight (00:00:00); an explicit
     * null is a TypeError, as it is for JS `null` in ToTemporalTime.
     *
     * @param PlainTime|string|array<array-key, mixed>|object $time PlainTime, string, or array.
     * @psalm-api
     */
    public function withPlainTime(mixed $time = self::OMITTED): self
    {
        // When called with no arguments, use startOfDay semantics (TC39 spec).
        // This handles cross-midnight DST gaps correctly.
        if ($time === self::OMITTED) {
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
            && ZoneOffsets::comparisonKey($this->timeZoneId) === ZoneOffsets::comparisonKey($other->timeZoneId)
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
     * @param array<array-key, mixed>|object $options null, array, or object (treated as empty bag).
     * @throws TypeError              if option values have wrong types.
     * @throws RangeError if option values are invalid strings.
     * @psalm-api
     */
    #[\Override]
    public function toString(mixed $options = []): string
    {
        $options = Options::requireObject($options, [
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

        $offsetSec = ZoneOffsets::offsetAt($epochSec, $this->resolvedTimeZoneId);
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
     * @param array<array-key, mixed>|object $options Intl.DateTimeFormat options array.
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
        IntlFormatter::validateCalendar(
            $this->calendarId,
            $locale,
            $opts,
            defaultComponents: LocaleComponentMode::DateTime,
        );

        // TC39: ZDT's default format includes the timezone name — but only when the
        // caller named no components at all. Asking for one component (`{year:'numeric'}`)
        // asks for that component alone, exactly as it would from a legacy Date.
        if (!IntlFormatter::requestsAnyComponent($opts)) {
            $opts['timeZoneName'] = 'short';
        }

        // Validate style + component conflicts
        IntlFormatter::validateStyleConflicts($opts);

        $timeZone = $this->timeZoneId;
        $formatter = IntlFormatter::buildIntlFormatter($locale, $timeZone, $opts, LocaleComponentMode::DateTime);
        [$epochSec, $subNs] = $this->epochParts();
        $result = IntlFormatter::formatEpoch($formatter, $epochSec, $subNs, $timeZone, $locale);

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
     * @param array<array-key, mixed>|object $options Options array; supports 'overflow' ('constrain'|'reject').
     * @psalm-api
     */
    public function add(string|array|object $duration, mixed $options = []): self
    {
        $dur = $duration instanceof Duration ? $duration : Duration::from($duration);
        return ZonedArithmetic::add($this, 1, $dur, $options);
    }

    /**
     * Returns a new ZonedDateTime with the given duration subtracted.
     *
     * @param Duration|string|array<array-key,mixed>|object $duration Duration, ISO 8601 duration string, or property-bag array.
     * @param array<array-key, mixed>|object $options Options array; supports 'overflow' ('constrain'|'reject').
     * @psalm-api
     */
    public function subtract(string|array|object $duration, mixed $options = []): self
    {
        $dur = $duration instanceof Duration ? $duration : Duration::from($duration);
        return ZonedArithmetic::add($this, -1, $dur, $options);
    }

    /**
     * Returns the Duration from $other to this ZonedDateTime (this - other).
     *
     * Default largestUnit is 'hour' (per TC39 ZonedDateTime spec).
     *
     * @param self|string|array<array-key, mixed>|object $other   ZonedDateTime or ZDT string.
     * @param array<array-key, mixed>|object $options Options array with largestUnit, smallestUnit, roundingMode, roundingIncrement.
     * @psalm-api
     */
    public function since(string|array|object $other, mixed $options = []): Duration
    {
        $o = $other instanceof self ? $other : self::from($other);
        if ($this->calendarId !== $o->calendarId) {
            throw new RangeError(
                "Cannot compute since() between different calendars: \"{$this->calendarId}\" and \"{$o->calendarId}\".",
            );
        }
        return ZonedDifference::between($this, $o, 'since', $options);
    }

    /**
     * Returns the Duration from this ZonedDateTime to $other (other - this).
     *
     * Default largestUnit is 'hour' (per TC39 ZonedDateTime spec).
     *
     * @param self|string|array<array-key, mixed>|object $other   ZonedDateTime or ZDT string.
     * @param array<array-key, mixed>|object $options Options array with largestUnit, smallestUnit, roundingMode, roundingIncrement.
     * @psalm-api
     */
    public function until(string|array|object $other, mixed $options = []): Duration
    {
        $o = $other instanceof self ? $other : self::from($other);
        if ($this->calendarId !== $o->calendarId) {
            throw new RangeError(
                "Cannot compute until() between different calendars: \"{$this->calendarId}\" and \"{$o->calendarId}\".",
            );
        }
        return ZonedDifference::between($this, $o, 'until', $options);
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
            $options = Options::requireObject($options, ['roundingIncrement', 'roundingMode', 'smallestUnit']);
        }

        /** @var mixed $suRaw */
        $suRaw = $options['smallestUnit'] ?? null;
        if ($suRaw === null) {
            throw new RangeError('Calendrics\\ZonedDateTime::round() requires smallestUnit.');
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
            throw new RangeError("Invalid smallestUnit \"{$suRaw}\" for Calendrics\\ZonedDateTime::round().");
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
    public function with(array|object $fields, mixed $options = []): self
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

        $fields = FieldBag::forPartial($fields, ZonedFields::CALENDAR_FIELDS, $this->calendarId, ['offset']);

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

        // GetOptionsObject reads every recognized option once, in the spec's
        // alphabetical order. The resolvers below take that snapshot rather than the
        // raw bag, so an accessor fires exactly once and in that order.
        $opts = Options::requireObject($options, ['disambiguation', 'offset', 'overflow']);
        $overflow = Options::overflowFromBag($opts);
        $disambiguation = ZonedFields::disambiguationFromBag($opts);

        // Extract the 'offset' option (default is 'prefer' for with()).
        $offsetOption = 'prefer';
        if (array_key_exists('offset', $opts)) {
            /** @var mixed $offOpt */
            $offOpt = $opts['offset'];
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

            return ZonedFields::fromLocal(
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
        // With no offset field, 'use'/'prefer'/'reject' all try to preserve the offset the
        // receiver already has; only 'ignore' re-resolves the new wall time from scratch.
        if (!$hasOffsetField && $offsetOption !== 'ignore') {
            [$curEpochSec] = $this->epochParts();
            $currentOffsetSec = ZoneOffsets::offsetAt($curEpochSec, $this->resolvedTimeZoneId);

            $epochDays = CalendarMath::toJulianDay($year, $month, $day) - 2_440_588;
            $wallSec = ($epochDays * 86_400) + ($h * 3600) + ($min * 60) + $sec;
            $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;

            if ($offsetOption === 'use') {
                return self::fromEpochParts($wallSec - $currentOffsetSec, $subNs, $this->timeZoneId, $this->calendarId);
            }
            $epochFromOffset = $wallSec - $currentOffsetSec;
            if (ZoneOffsets::offsetAt($epochFromOffset, $this->resolvedTimeZoneId) === $currentOffsetSec) {
                return self::fromEpochParts($epochFromOffset, $subNs, $this->timeZoneId, $this->calendarId);
            }

            // The receiver's offset is not one this zone observes at the new wall time —
            // fall through to disambiguation.
        }

        if ($hasOffsetField && $offsetOption !== 'ignore') {
            /** @var string $offVal */
            $offVal = $fields['offset'];
            $givenOffsetSec = ZonedFields::offsetStringToSeconds($offVal);

            $epochDays = CalendarMath::toJulianDay($year, $month, $day) - 2_440_588;
            $wallSec = ($epochDays * 86_400) + ($h * 3600) + ($min * 60) + $sec;
            $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;

            if ($offsetOption === 'use') {
                // The stated offset wins outright; the zone only names the result's zone.
                return self::fromEpochParts($wallSec - $givenOffsetSec, $subNs, $this->timeZoneId, $this->calendarId);
            }

            $epochFromOffset = $wallSec - $givenOffsetSec;
            if (ZoneOffsets::offsetAt($epochFromOffset, $this->resolvedTimeZoneId) === $givenOffsetSec) {
                return self::fromEpochParts($epochFromOffset, $subNs, $this->timeZoneId, $this->calendarId);
            }
            if ($offsetOption === 'reject') {
                throw new RangeError("The offset {$offVal} does not match the timezone offset at the given instant.");
            }

            // 'prefer': fall through to disambiguation.
        }

        return ZonedFields::fromLocal(
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
                if ($curOffset !== $prevOffset) {
                    // A transition whose whole-second nanoseconds would overflow the int64
                    // epochNanoseconds field is not representable: transitionAt() returns
                    // null per spec (and avoids the int64 overflow $ts * NS_PER_SECOND hits).
                    return $this->transitionAt($transitions[$i]['ts']);
                }
                $prevOffset = $curOffset;
            }
            // Every entry in the window carries the same offset, so the zone has no
            // further transitions. Return here rather than falling through — the
            // 'previous' search below would happily report a historical transition
            // as this instant's *next* one.
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
     * The one shape every local-field consumer works from — the virtual property hooks
     * above, and the parsing, arithmetic and difference collaborators in
     * `Calendrics\Spec\Internal`, which is why this is public rather than private.
     *
     * @internal
     * @psalm-internal Calendrics\Spec
     * @return array{year:int, month:int<1,12>, day:int<1,31>, hour:int<0,23>, minute:int<0,59>, second:int<0,59>, millisecond:int<0,999>, microsecond:int<0,999>, nanosecond:int<0,999>, offsetSec:int, offset:string}
     */
    public function localComponents(): array
    {
        if ($this->localCache !== null) {
            return $this->localCache;
        }

        // Use stored true epoch parts when available (sentinel values).
        [$epochSec, $subNs] = $this->epochParts();

        $offsetSec = ZoneOffsets::offsetAt($epochSec, $this->resolvedTimeZoneId);
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

    // -------------------------------------------------------------------------
    // Helpers copied from Instant.php
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Private helpers for add/subtract/since/until/round/with
    // -------------------------------------------------------------------------

    /**
     * Creates a ZonedDateTime from UTC epoch seconds and sub-second nanoseconds.
     *
     * The seam every caller holding decomposed epoch parts builds through — the
     * Internal\Zoned* collaborators, {@see Instant::toZonedDateTimeISO()}, and
     * `PlainDate`/`PlainDateTime::toZonedDateTime()` — so none of them re-encodes through
     * an int64 nanosecond intermediate, which overflows near the ISO range boundary.
     * int64 overflow is handled here by storing a sentinel epochNanoseconds value while
     * preserving the true epoch seconds for later decomposition in
     * {@see localComponents()}.
     *
     * Named to match {@see Instant::fromEpochParts()}: it is the same operation on the
     * other class that carries an instant, and it used to answer to three names.
     *
     * $epochSec/$subNs accept int|float and are narrowed by
     * {@see EpochValue::narrowParts()}, which documents where float parts come from.
     *
     * @internal
     * @psalm-internal Calendrics\Spec
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
        // public field clamps to a sentinel. The constructor derives its own parts from
        // the int it is handed, which is the clamp rather than the instant in that case,
        // so re-stamp from the value that knows both.
        $epoch = EpochValue::fromParts($epochSec, $subNs);
        $zdt = new self($epoch->epochNanoseconds, $tzId, $calendarId);
        $zdt->applyEpoch($epoch);
        return $zdt;
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
}
