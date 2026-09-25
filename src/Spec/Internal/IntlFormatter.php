<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\Internal\Calendar\IntlCalendarFactory;

/**
 * Owns the IntlDateFormatter construction and locale/pattern helpers used by
 * toLocaleString() across all Temporal spec classes.
 *
 * The public surface is: buildIntlFormatter() (central entry point) and formatEpoch(),
 * the locale and calendar resolvers resolveLocale() and resolveCalendar(), the checks
 * each caller runs in its own spec order — validateOptionValues(), validateCalendar()
 * and validateStyleConflicts() — plus requestsAnyComponent() and
 * stripPatternComponents(). Everything else is a private helper.
 *
 * @internal
 */
final class IntlFormatter
{
    /**
     * Every option name toLocaleString() reads, for snapshotting an object options bag
     * through {@see Options::bagSnapshot()}.
     *
     * @var list<string>
     */
    public const array OPTION_NAMES = [
        'calendar',
        'dateStyle',
        'day',
        'dayPeriod',
        'era',
        'fractionalSecondDigits',
        'hour',
        'hour12',
        'hourCycle',
        'minute',
        'month',
        'second',
        'timeStyle',
        'timeZone',
        'timeZoneName',
        'weekday',
        'year',
    ];

    /** @var list<string> Individual date/time component options that conflict with dateStyle/timeStyle. */
    private const COMPONENT_OPTIONS = [
        'weekday',
        'era',
        'year',
        'month',
        'day',
        'hour',
        'minute',
        'second',
        'dayPeriod',
        'fractionalSecondDigits',
        'timeZoneName',
    ];

    /**
     * Values ECMA-402 accepts for `weekday`, `era` and `dayPeriod`.
     *
     * @var non-empty-list<'narrow'|'short'|'long'>
     */
    private const array TEXT_WIDTHS = ['narrow', 'short', 'long'];

    /**
     * Values ECMA-402 accepts for `year`, `day`, `hour`, `minute` and `second`.
     *
     * @var non-empty-list<'numeric'|'2-digit'>
     */
    private const array NUMBER_WIDTHS = ['numeric', '2-digit'];

    /**
     * Values ECMA-402 accepts for `month`.
     *
     * @var non-empty-list<'numeric'|'2-digit'|'narrow'|'short'|'long'>
     */
    private const array MONTH_WIDTHS = ['numeric', '2-digit', 'narrow', 'short', 'long'];

    /**
     * Values ECMA-402 accepts for `timeZoneName`.
     *
     * @var non-empty-list<'short'|'long'|'shortOffset'|'longOffset'|'shortGeneric'|'longGeneric'>
     */
    private const array TIME_ZONE_NAME_STYLES = [
        'short',
        'long',
        'shortOffset',
        'longOffset',
        'shortGeneric',
        'longGeneric',
    ];

    /**
     * Values ECMA-402 accepts for `dateStyle` and `timeStyle`.
     *
     * @var non-empty-list<'full'|'long'|'medium'|'short'>
     */
    private const array FORMAT_STYLES = ['full', 'long', 'medium', 'short'];

    /**
     * Values ECMA-402 accepts for `hourCycle`.
     *
     * @var non-empty-list<'h11'|'h12'|'h23'|'h24'>
     */
    private const array HOUR_CYCLES = ['h11', 'h12', 'h23', 'h24'];

    /**
     * Standalone-hour width changes in current CLDR data that are absent from ICU 76.
     *
     * @var list<string>
     */
    private const array NUMERIC_HOUR_CLDR_LOCALES = ['be', 'en-IL', 'ie', 'it', 'mk', 'ru', 'sv-FI', 'uk'];

    /** @var list<string> */
    private const array TWO_DIGIT_HOUR_CLDR_LOCALES = ['es-BR', 'es-BZ'];

    /** @var list<string> */
    private const array TWO_DIGIT_ZONE_HOUR_CLDR_LOCALES = ['en-IL', 'gsw', 'smn-FI', 'yue-CN'];

    /** @var list<string> */
    private const array NUMERIC_ZONE_MINUTE_CLDR_LOCALES = ['ckb-IR', 'lrc-IR', 'mzn-IR', 'th-TH'];

    /** @var list<string> */
    private const array NUMERIC_ZONE_ANY_MINUTE_CLDR_LOCALES = ['fa'];

    /** The IntlDateFormatter constant each {@see self::FORMAT_STYLES} value selects. */
    private const array FORMAT_STYLE_CONSTANTS = [
        'full' => \IntlDateFormatter::FULL,
        'long' => \IntlDateFormatter::LONG,
        'medium' => \IntlDateFormatter::MEDIUM,
        'short' => \IntlDateFormatter::SHORT,
    ];

    /**
     * Resolves a locale value from a string, array, or null.
     *
     * Returns the first non-empty string from the input, or the system default locale.
     *
     * @param string|array<array-key, mixed>|null $locales
     */
    public static function resolveLocale(string|array|null $locales): string
    {
        if (is_string($locales) && $locales !== '') {
            return $locales;
        }
        if (is_array($locales)) {
            $values = array_values($locales);
            for ($i = 0, $n = count($values); $i < $n; $i++) {
                /** @var mixed $candidate */
                $candidate = $values[$i];
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
        }
        return \Locale::getDefault();
    }

    /**
     * Resolves the calendar an IntlDateFormatter built from $locale and $opts will use,
     * expressed as a TC39 calendar identifier.
     *
     * Mirrors ECMA-402's `dateTimeFormat.[[Calendar]]`: the explicit `calendar` option wins,
     * otherwise ICU resolves the calendar from the locale — either from its `-u-ca-` /
     * `@calendar=` keyword or from the region's default (e.g. `th-TH` → `buddhist`).
     *
     * @param array<string, mixed> $opts
     * @throws RangeError if the `calendar` option is not a recognized calendar identifier.
     */
    public static function resolveCalendar(string $locale, array $opts): string
    {
        /** @var mixed $calendarOpt */
        $calendarOpt = $opts['calendar'] ?? null;
        if (is_string($calendarOpt) && $calendarOpt !== '') {
            return CalendarFactory::canonicalize($calendarOpt);
        }

        $calendar = IntlCalendarFactory::forLocale(timeZone: null, locale: $locale);
        // Every locale resolves to some calendar; ICU falls back to gregorian when the locale is unknown.
        $icuType = $calendar->getType();

        return IntlCalendarFactory::calendarId($icuType);
    }

    /**
     * Enforces ECMA-402's calendar-compatibility rule for toLocaleString().
     *
     * A Temporal value may only be formatted by a formatter whose resolved calendar
     * matches the value's own calendar; formatting a Hebrew-calendar date with a
     * Gregorian formatter would silently reinterpret its fields, so the spec throws
     * instead.
     *
     * `PlainDate`, `PlainDateTime` and `ZonedDateTime` additionally accept the ISO 8601
     * calendar against any formatter, because an ISO date is unambiguous and can be
     * projected into the formatter's calendar. `PlainYearMonth` and `PlainMonthDay` grant
     * no such exemption — recognized here by their $defaultComponents mode: a bare
     * year-month or month-day has no meaning outside the calendar it was expressed in.
     *
     * A null $calendarId means the type carries no calendar (PlainTime), which any
     * formatter can render.
     *
     * @param array<string, mixed> $opts
     * @param LocaleComponentMode $defaultComponents The value's component mode, as passed to buildIntlFormatter().
     * @throws RangeError if the value's calendar is incompatible with the formatter's.
     */
    public static function validateCalendar(
        ?string $calendarId,
        string $locale,
        array $opts,
        LocaleComponentMode $defaultComponents,
    ): void {
        if ($calendarId === null) {
            return;
        }

        if (!$defaultComponents->isPartialDate() && $calendarId === 'iso8601') {
            return;
        }

        $resolved = self::resolveCalendar($locale, $opts);
        if ($calendarId === $resolved) {
            return;
        }

        throw new RangeError(sprintf(
            'toLocaleString(): cannot format a value in the "%s" calendar with a formatter resolved to the "%s" calendar.',
            $calendarId,
            $resolved,
        ));
    }

    /**
     * Applies ECMA-402 GetOption's value check to every `toLocaleString()` option that
     * has a fixed value set, in the order CreateDateTimeFormat reads them.
     *
     * A value outside the set is a mistake, and ECMA-402 says so: `{weekday: 'wide'}`
     * is a RangeError, not a request for the short weekday. Callers run this before
     * the TypeErrors they raise for style conflicts and inapplicable styles, which
     * CreateDateTimeFormat only reaches after reading every component option.
     *
     * Only options with a fixed value set are checked here. `calendar` and `timeZone`
     * are identifiers, resolved by their own lookups; `hour12` is a boolean; and
     * `fractionalSecondDigits` is a number, which ECMA-402 range-checks to 1-3 and
     * this layer does not.
     *
     * @param array<string, mixed> $opts
     * @throws RangeError if any option carries a value outside its set.
     */
    public static function validateOptionValues(array $opts): void
    {
        self::checkedKeyword($opts, 'hourCycle', self::HOUR_CYCLES);
        self::checkedKeyword($opts, 'weekday', self::TEXT_WIDTHS);
        self::checkedKeyword($opts, 'era', self::TEXT_WIDTHS);
        self::checkedKeyword($opts, 'year', self::NUMBER_WIDTHS);
        self::checkedKeyword($opts, 'month', self::MONTH_WIDTHS);
        self::checkedKeyword($opts, 'day', self::NUMBER_WIDTHS);
        self::checkedKeyword($opts, 'dayPeriod', self::TEXT_WIDTHS);
        self::checkedKeyword($opts, 'hour', self::NUMBER_WIDTHS);
        self::checkedKeyword($opts, 'minute', self::NUMBER_WIDTHS);
        self::checkedKeyword($opts, 'second', self::NUMBER_WIDTHS);
        self::checkedKeyword($opts, 'timeZoneName', self::TIME_ZONE_NAME_STYLES);
        self::checkedKeyword($opts, 'dateStyle', self::FORMAT_STYLES);
        self::checkedKeyword($opts, 'timeStyle', self::FORMAT_STYLES);
    }

    /**
     * Reads one keyword-valued option exactly as ECMA-402's
     * GetOption(options, name, string, values, undefined) does.
     *
     * Returns the matching element of $allowed rather than the coerced string, so the
     * return type is the value set itself. That is what lets every consumer below
     * `match` over the set with no fallback arm to hide a value the set does not
     * contain — {@see self::validateOptionValues()} has already rejected those.
     *
     * @template TValue of string
     * @param array<string, mixed> $opts
     * @param non-empty-list<TValue> $allowed
     * @return TValue|null null when the option was not supplied; TC39's `undefined`
     *                     reaches PHP as `null`, which every option bag here reads
     *                     as "field not supplied".
     * @throws RangeError if the value does not coerce to a string listed in $allowed.
     */
    private static function checkedKeyword(array $opts, string $name, array $allowed): ?string
    {
        /** @var mixed $raw */
        $raw = $opts[$name] ?? null;
        if ($raw === null) {
            return null;
        }

        $value = Options::coerceEnumOption($raw, $name);
        foreach ($allowed as $candidate) {
            if ($value === $candidate) {
                return $candidate;
            }
        }

        throw new RangeError(sprintf(
            'toLocaleString(): invalid %s value "%s"; must be one of %s.',
            $name,
            $value,
            implode(', ', $allowed),
        ));
    }

    /**
     * Validates that dateStyle/timeStyle are not combined with individual component options.
     *
     * Per ECMA-402, mixing dateStyle or timeStyle with any individual date/time component
     * option (weekday, era, year, month, day, hour, minute, second, dayPeriod,
     * fractionalSecondDigits, timeZoneName) throws a TypeError.
     *
     * @param array<string, mixed> $opts
     * @throws TypeError if style and component options are mixed.
     */
    public static function validateStyleConflicts(array $opts): void
    {
        $hasDateStyle = ($opts['dateStyle'] ?? null) !== null;
        $hasTimeStyle = ($opts['timeStyle'] ?? null) !== null;

        if (!$hasDateStyle && !$hasTimeStyle) {
            return;
        }

        foreach (self::COMPONENT_OPTIONS as $opt) {
            if (($opts[$opt] ?? null) === null) {
                continue;
            }

            $style = $hasDateStyle ? 'dateStyle' : 'timeStyle';
            throw new TypeError(sprintf('toLocaleString(): %s and %s cannot be used together.', $style, $opt));
        }
    }

    /**
     * Formats an exact (epochSec, subNs) instant through $formatter.
     *
     * ICU renders sub-second digits from the calendar's millisecond field, which a
     * whole-second timestamp leaves at zero, so `fractionalSecondDigits` would print
     * `000`. Setting the field explicitly also keeps the millisecond exact across the
     * whole representable range: a float timestamp — in seconds or in milliseconds —
     * has an ulp wider than a millisecond near the ±273790-year limits.
     */
    public static function formatEpoch(\IntlDateFormatter $formatter, int $epochSec, int $subNs): string|false
    {
        $calendar = $formatter->getCalendarObject();
        if (!$calendar instanceof \IntlCalendar) {
            return $formatter->format($epochSec);
        }
        $calendar->setTime((float) $epochSec * 1_000.0);
        $calendar->set(\IntlCalendar::FIELD_MILLISECOND, intdiv(num1: $subNs, num2: 1_000_000));
        return $formatter->format($calendar);
    }

    /**
     * Whether $opts requests any individual date/time component or a dateStyle/timeStyle —
     * i.e. whether the caller has said anything at all about what the output should contain.
     *
     * @param array<string, mixed> $opts
     */
    public static function requestsAnyComponent(array $opts): bool
    {
        if (($opts['dateStyle'] ?? null) !== null || ($opts['timeStyle'] ?? null) !== null) {
            return true;
        }
        foreach (self::COMPONENT_OPTIONS as $opt) {
            if (($opts[$opt] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Builds a configured IntlDateFormatter from a resolved locale, timezone, and options array.
     *
     * Reads `dateStyle` and `timeStyle` from $opts (each: "full"|"long"|"medium"|"short") and maps
     * them to IntlDateFormatter constants. When neither style is provided, uses a pattern built
     * from individual component options, or defaults based on the $defaultComponents parameter.
     * Appends a `@calendar=…` extension to $locale if $opts['calendar'] is set.
     * Supports `hour12` and `hourCycle` options for hour format control.
     *
     * @param array<string, mixed> $opts
     * @param LocaleComponentMode $defaultComponents Which components to include when the caller names none.
     */
    public static function buildIntlFormatter(
        string $locale,
        string $timeZone,
        array $opts,
        LocaleComponentMode $defaultComponents = LocaleComponentMode::DateTime,
    ): \IntlDateFormatter {
        /** @var mixed $calendarOpt */
        $calendarOpt = $opts['calendar'] ?? null;
        if (is_string($calendarOpt)) {
            $calendarId = CalendarFactory::canonicalize($calendarOpt);
            $locale = sprintf('%s@calendar=%s', $locale, IntlCalendarFactory::icuType($calendarId));
        }

        $timeZone = self::icuTimeZoneId($timeZone);

        $hourCycle = self::checkedKeyword($opts, 'hourCycle', self::HOUR_CYCLES);
        if ($hourCycle !== null) {
            $locale = self::applyHourCycle($locale, $hourCycle);
        } elseif (($opts['hour12'] ?? null) !== null) {
            // hour12=false -> h23, hour12=true -> h12
            /** @var mixed $hour12Raw */
            $hour12Raw = $opts['hour12'];
            $isTrue =
                $hour12Raw !== false
                && $hour12Raw !== 0
                && $hour12Raw !== 0.0
                && $hour12Raw !== ''
                && $hour12Raw !== '0';
            $hc = $isTrue ? 'h12' : 'h23';
            $locale = self::applyHourCycle($locale, $hc);
        }

        // IntlDateFormatter only respects the calendar an explicit IntlCalendar instance
        // carries, so resolve the locale's calendar and always pass it. The calendar may
        // come from a keyword (en-u-ca-islamic-tbla, en@calendar=islamic-tbla — including
        // the one appended above for the `calendar` option) or from the locale's own
        // default, as with th-TH → buddhist, which carries no keyword at all.
        $calendarObj = IntlCalendarFactory::forFormatting($timeZone, $locale);

        $dateStyle = self::checkedKeyword($opts, 'dateStyle', self::FORMAT_STYLES);
        $timeStyle = self::checkedKeyword($opts, 'timeStyle', self::FORMAT_STYLES);

        if ($dateStyle !== null || $timeStyle !== null) {
            self::validateStyleConflicts($opts);

            $dateType = $dateStyle !== null ? self::FORMAT_STYLE_CONSTANTS[$dateStyle] : \IntlDateFormatter::NONE;
            $timeType = $timeStyle !== null ? self::FORMAT_STYLE_CONSTANTS[$timeStyle] : \IntlDateFormatter::NONE;

            // PlainYearMonth and PlainMonthDay have no locale style of their own, so they
            // take the date style's pattern and strip the field they do not carry.
            $absentComponent = $defaultComponents->absentDateField();
            if ($dateStyle !== null && $absentComponent !== null) {
                $tmpFormatter = new \IntlDateFormatter(
                    $locale,
                    $dateType,
                    \IntlDateFormatter::NONE,
                    $timeZone,
                    $calendarObj,
                );
                $tmpFormatter->setCalendar($calendarObj);
                $pattern = $tmpFormatter->getPattern();
                if ($pattern === false) {
                    $pattern = '';
                }
                $pattern = self::stripPatternComponents($pattern, $absentComponent, $locale);
                $formatter = new \IntlDateFormatter(
                    $locale,
                    \IntlDateFormatter::NONE,
                    \IntlDateFormatter::NONE,
                    $timeZone,
                    $calendarObj,
                    $pattern,
                );
                $formatter->setCalendar($calendarObj);
                return $formatter;
            }

            $formatter = new \IntlDateFormatter($locale, $dateType, $timeType, $timeZone, $calendarObj);
            $formatter->setCalendar($calendarObj);
            return $formatter;
        }

        // Check for individual component options that require a custom pattern
        $hasComponents = false;
        foreach (self::COMPONENT_OPTIONS as $opt) {
            if (($opts[$opt] ?? null) === null) {
                continue;
            }

            $hasComponents = true;
            break;
        }

        if ($hasComponents) {
            $pattern = self::buildPatternFromComponents($opts, $defaultComponents, $locale);
            $formatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                $timeZone,
                $calendarObj,
                $pattern,
            );
            $formatter->setCalendar($calendarObj);
            return $formatter;
        }

        // Default: use skeleton-based patterns to match JS Intl.DateTimeFormat defaults
        $generator = new \IntlDatePatternGenerator($locale);
        $pattern = $generator->getBestPattern($defaultComponents->defaultSkeleton());
        if ($pattern === false) {
            $pattern = null;
        }

        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            $timeZone,
            $calendarObj,
            $pattern,
        );
        $formatter->setCalendar($calendarObj);
        return $formatter;
    }

    /**
     * Strips year or day components from an ICU date pattern.
     *
     * The surviving field runs form a skeleton from which ICU generates a new
     * locale-appropriate pattern. Quoted literals are skipped while reading the
     * source pattern and ICU restores any connectors and punctuation required by
     * the remaining fields.
     *
     * @param 'year'|'day' $which
     */
    public static function stripPatternComponents(string $pattern, string $which, string $locale): string
    {
        $removedFields = $which === 'year' ? 'yYuUrG' : 'dD';
        $matches = null;
        preg_match_all("/'(?:[^']|'')*'|([A-Za-z])\\1*/", $pattern, $matches, PREG_SET_ORDER);

        $skeleton = '';
        foreach ($matches as $match) {
            if (!array_key_exists(1, $match) || str_contains($removedFields, $match[1])) {
                continue;
            }

            $skeleton .= $match[0];
        }

        $generator = new \IntlDatePatternGenerator($locale);
        $result = $generator->getBestPattern($skeleton);

        return $result !== false ? $result : $skeleton;
    }

    /**
     * Renders a time zone identifier in the dialect ICU parses.
     *
     * A fixed offset reaches us as ISO 8601 (`+01:00`), which ICU does not recognize as
     * a zone at all; its custom-zone form is `GMT±HH:MM`, and a zero offset is plain
     * `GMT`. IANA identifiers pass through untouched.
     */
    private static function icuTimeZoneId(string $timeZone): string
    {
        // Compare against the original subject string rather than the captured digit
        // groups: PHPStan's regex inference narrows \d{2} groups to a type that excludes
        // leading-zero values like '00', which would make `$m[2] === '00'` look
        // always-false.
        $m = null;
        if (preg_match('/^([+\-])(\d{2}):(\d{2})$/', $timeZone, $m) !== 1) {
            return $timeZone;
        }
        if ($timeZone === '+00:00' || $timeZone === '-00:00') {
            return 'GMT';
        }
        return sprintf('GMT%s%s:%s', $m[1], $m[2], $m[3]);
    }

    /**
     * Spells the hour cycle as a locale keyword, in the dialect $locale already uses.
     *
     * ICU reads a locale's keywords in one dialect at a time, so a BCP 47 `-u-`
     * extension bolted onto a locale that carries a legacy `@keyword` section is
     * dropped without complaint — `en-US-u-hc-h23@calendar=hebrew` formats in h12.
     * The `@` case therefore has to extend the legacy keyword list instead, and it
     * is reached whenever the `calendar` option is set, because that option is
     * itself appended as `@calendar=`.
     */
    private static function applyHourCycle(string $locale, string $hourCycle): string
    {
        if (str_contains($locale, '@')) {
            return sprintf('%s;hours=%s', $locale, $hourCycle);
        }
        if (str_contains($locale, '-u-')) {
            return sprintf('%s-hc-%s', $locale, $hourCycle);
        }
        return sprintf('%s-u-hc-%s', $locale, $hourCycle);
    }

    /**
     * Builds an ICU skeleton pattern from individual component options.
     *
     * @param array<string, mixed> $opts
     */
    private static function buildPatternFromComponents(
        array $opts,
        LocaleComponentMode $defaultComponents,
        string $locale = 'en',
    ): string {
        $parts = [];

        // ECMA-402 CreateDateTimeFormat with required = "time" (PlainTime) only honors
        // the time-related option set; the date-component options (weekday, era, year,
        // month, day) are not applicable to a time-only type and are dropped.
        $allowsDateComponents = !$defaultComponents->isTimeOnly();

        // Date components
        if ($allowsDateComponents) {
            $weekday = self::checkedKeyword($opts, 'weekday', self::TEXT_WIDTHS);
            if ($weekday !== null) {
                $parts[] = match ($weekday) {
                    'narrow' => 'EEEEE',
                    'short' => 'EEE',
                    'long' => 'EEEE',
                };
            }
            $era = self::checkedKeyword($opts, 'era', self::TEXT_WIDTHS);
            if ($era !== null) {
                $parts[] = match ($era) {
                    'narrow' => 'GGGGG',
                    'short' => 'GGG',
                    'long' => 'GGGG',
                };
            }
            $year = self::checkedKeyword($opts, 'year', self::NUMBER_WIDTHS);
            if ($year !== null) {
                $parts[] = $year === '2-digit' ? 'yy' : 'y';
            }
            $month = self::checkedKeyword($opts, 'month', self::MONTH_WIDTHS);
            if ($month !== null) {
                $parts[] = match ($month) {
                    'numeric' => 'M',
                    '2-digit' => 'MM',
                    'narrow' => 'MMMMM',
                    'short' => 'MMM',
                    'long' => 'MMMM',
                };
            }
            $day = self::checkedKeyword($opts, 'day', self::NUMBER_WIDTHS);
            if ($day !== null) {
                $parts[] = $day === '2-digit' ? 'dd' : 'd';
            }
        }

        // Time components
        $hour = self::checkedKeyword($opts, 'hour', self::NUMBER_WIDTHS);
        if ($hour !== null) {
            // Use 'j' skeleton symbol which picks locale-appropriate hour cycle
            $parts[] = $hour === '2-digit' ? 'jj' : 'j';
        }
        $minute = self::checkedKeyword($opts, 'minute', self::NUMBER_WIDTHS);
        if ($minute !== null) {
            $parts[] = $minute === '2-digit' ? 'mm' : 'm';
        }
        $second = self::checkedKeyword($opts, 'second', self::NUMBER_WIDTHS);
        if ($second !== null) {
            $parts[] = $second === '2-digit' ? 'ss' : 's';
        }
        if (($opts['fractionalSecondDigits'] ?? null) !== null) {
            /** @var mixed $fsd */
            $fsd = $opts['fractionalSecondDigits'];
            $digits = is_int($fsd) ? $fsd : (int) (is_string($fsd) ? $fsd : 0);
            $parts[] = str_repeat('S', times: max(0, $digits));
        }
        $dayPeriod = self::checkedKeyword($opts, 'dayPeriod', self::TEXT_WIDTHS);
        if ($dayPeriod !== null) {
            $parts[] = match ($dayPeriod) {
                'narrow' => 'BBBBB',
                'short' => 'B',
                'long' => 'BBBB',
            };
        }
        $timeZoneName = self::checkedKeyword($opts, 'timeZoneName', self::TIME_ZONE_NAME_STYLES);
        if ($timeZoneName !== null) {
            $parts[] = match ($timeZoneName) {
                'short' => 'z',
                'long' => 'zzzz',
                'shortOffset' => 'O',
                'longOffset' => 'OOOO',
                'shortGeneric' => 'v',
                'longGeneric' => 'vvvv',
            };
        }

        // If no primary date/time components but auxiliary options were set,
        // add default components based on the default mode.
        $hasDatePart =
            $allowsDateComponents
            && (
                ($opts['weekday'] ?? null) !== null
                || ($opts['year'] ?? null) !== null
                || ($opts['month'] ?? null) !== null
                || ($opts['day'] ?? null) !== null
            );
        // Per ECMA-402 ToDateTimeOptions, dayPeriod and fractionalSecondDigits count
        // as time components for the needDefaults check (era and timeZoneName do not
        // appear in either list, so alone they still get defaults added).
        $hasTimePart =
            ($opts['hour'] ?? null) !== null
            || ($opts['minute'] ?? null) !== null
            || ($opts['second'] ?? null) !== null
            || ($opts['dayPeriod'] ?? null) !== null
            || ($opts['fractionalSecondDigits'] ?? null) !== null;
        if (!$hasDatePart && !$hasTimePart) {
            $parts = [
                ...$defaultComponents->defaultDateFields(),
                ...$parts,
                ...$defaultComponents->defaultTimeFields(),
            ];
        }

        $skeleton = implode('', $parts);

        // Use ICU's DateTimePatternGenerator to get a best-fit pattern
        $generator = new \IntlDatePatternGenerator($locale);
        $result = $generator->getBestPattern($skeleton);
        $pattern = $result !== false ? $result : $skeleton;

        if ($hour === '2-digit') {
            return self::setHourFieldWidth($pattern, 2);
        }
        if ($hour === 'numeric') {
            $resolvedHourWidth = self::resolvedNumericHourWidth($opts, $locale, $pattern, $minute, $second);
            if ($resolvedHourWidth !== null) {
                return self::setHourFieldWidth($pattern, $resolvedHourWidth);
            }
        }

        return $pattern;
    }

    /**
     * Resolves the hour width chosen by ECMA-402's best-fit match.
     *
     * ICU exposes this through UDATPG_MATCH_HOUR_FIELD_LENGTH, but PHP's
     * IntlDatePatternGenerator binding does not expose that option. The option only changes
     * the selected width when another requested field makes ICU choose a format record with a
     * different hour width. The combinations below mirror those observable transitions; an
     * hour without a minute takes its width from the locale's standalone-hour record.
     *
     * @param array<string, mixed> $opts
     * @return 1|2|null Null preserves the width selected by the local ICU data.
     */
    private static function resolvedNumericHourWidth(
        array $opts,
        string $locale,
        string $pattern,
        ?string $minute,
        ?string $second,
    ): ?int {
        $localeId = self::canonicalLocaleId($locale);
        $localeDataId = self::localeLanguageRegionId($localeId);
        $language = \Locale::getPrimaryLanguage($localeId);
        if ($localeDataId === 'af-NA' && ($opts['dayPeriod'] ?? null) !== null) {
            return $minute === null ? 1 : 2;
        }
        if (($opts['timeZoneName'] ?? null) !== null) {
            if ($minute === '2-digit' && $second !== null) {
                return 1;
            }
            if (
                $minute === null
                && (
                    in_array($language, self::TWO_DIGIT_ZONE_HOUR_CLDR_LOCALES, strict: true)
                    || in_array($localeDataId, self::TWO_DIGIT_ZONE_HOUR_CLDR_LOCALES, strict: true)
                )
            ) {
                return 2;
            }
            if (
                $minute === '2-digit'
                && (
                    in_array($language, self::NUMERIC_ZONE_MINUTE_CLDR_LOCALES, strict: true)
                    || in_array($localeDataId, self::NUMERIC_ZONE_MINUTE_CLDR_LOCALES, strict: true)
                )
            ) {
                return 1;
            }
            if ($minute !== null && in_array($language, self::NUMERIC_ZONE_ANY_MINUTE_CLDR_LOCALES, strict: true)) {
                return 1;
            }

            return null;
        }

        if ($minute === null) {
            return self::standaloneHourWidth($locale, $pattern);
        }
        if ($minute !== '2-digit') {
            return null;
        }

        if (\Locale::getPrimaryLanguage($localeId) === 'yo') {
            return 2;
        }

        if ($second === 'numeric' || ($opts['fractionalSecondDigits'] ?? null) !== null) {
            return $localeDataId === 'sv-FI' ? 1 : null;
        }
        return 1;
    }

    /**
     * Reads the locale's own standalone-hour format rather than ICU's generic root fallback.
     *
     * @return 1|2
     */
    private static function standaloneHourWidth(string $locale, string $pattern): int
    {
        $match = null;
        if (preg_match("/'(?:[^']|'')*'(*SKIP)(*F)|([hHKk])\\1*/", $pattern, $match) !== 1) {
            return 1;
        }

        $hourSymbol = $match[1];
        $language = \Locale::getPrimaryLanguage($locale);
        $localeId = self::canonicalLocaleId($locale);
        $localeDataId = self::localeLanguageRegionId($localeId);
        if (in_array($localeDataId, self::TWO_DIGIT_HOUR_CLDR_LOCALES, strict: true)) {
            return 2;
        }
        if (
            in_array($language, self::NUMERIC_HOUR_CLDR_LOCALES, strict: true)
            || in_array($localeDataId, self::NUMERIC_HOUR_CLDR_LOCALES, strict: true)
        ) {
            return 1;
        }

        $candidates = [$localeId];
        if (is_string($language)) {
            $candidates[] = $language;
        }
        $calendarType = IntlCalendarFactory::forLocale(timeZone: null, locale: $locale)->getType();
        foreach (array_unique($candidates) as $candidate) {
            $baseLocale = explode('@', $candidate, limit: 2)[0];
            $bundle = \ResourceBundle::create($baseLocale, bundle: null, fallback: false);
            if (!$bundle instanceof \ResourceBundle) {
                continue;
            }

            $calendar = $bundle->get('calendar');
            $calendarData = $calendar instanceof \ResourceBundle ? $calendar->get($calendarType) : null;
            $formats = $calendarData instanceof \ResourceBundle ? $calendarData->get('availableFormats') : null;
            $hourPattern = $formats instanceof \ResourceBundle ? $formats->get($hourSymbol) : null;
            if (!is_string($hourPattern)) {
                continue;
            }

            return preg_match("/'(?:[^']|'')*'(*SKIP)(*F)|{$hourSymbol}{2,}/", $hourPattern) === 1 ? 2 : 1;
        }

        return strlen($match[0]) >= 2 ? 2 : 1;
    }

    private static function canonicalLocaleId(string $locale): string
    {
        $canonical = \Locale::canonicalize($locale);
        $localeId = is_string($canonical) ? $canonical : $locale;
        $localeId = explode('-u-', $localeId, limit: 2)[0];

        return str_replace(search: '_', replace: '-', subject: explode('@', $localeId, limit: 2)[0]);
    }

    private static function localeLanguageRegionId(string $locale): string
    {
        $language = \Locale::getPrimaryLanguage($locale);
        $region = \Locale::getRegion($locale);
        if (!is_string($language) || $language === '' || !is_string($region) || $region === '') {
            return $locale;
        }

        return sprintf('%s-%s', $language, $region);
    }

    /**
     * Sets the hour field of an ICU pattern to the requested width.
     *
     * ECMA-402 has `hour: '2-digit'` pad a single-digit hour, but
     * {@see \IntlDatePatternGenerator::getBestPattern()} is free to answer with the locale's
     * own preferred hour width instead of the requested one: en-US returns `h a` for the
     * skeletons `j`, `jj` and even the explicit `hh`. ICU honors the requested count when
     * passed `UDATPG_MATCH_HOUR_FIELD_LENGTH`, for which PHP's binding takes no argument, so
     * the width is reapplied to the returned pattern instead.
     *
     * The alternation matches a quoted literal first so its contents are copied through
     * untouched: de-DE's `HH 'Uhr'` must not have the `h` of `Uhr` read as an hour field.
     */
    /** @param 1|2 $width */
    private static function setHourFieldWidth(string $pattern, int $width): string
    {
        $adjusted = preg_replace_callback(
            "/'[^']*'|([hHKk])\\1*/",
            /** @param array<array-key, string> $match */
            static fn(array $match): string => array_key_exists(1, $match)
                ? str_repeat($match[1], times: $width)
                : $match[0],
            subject: $pattern,
        );

        return $adjusted ?? $pattern;
    }
}
