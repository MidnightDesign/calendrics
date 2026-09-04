<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;

/**
 * Owns the IntlDateFormatter construction and locale/pattern helpers used by
 * toLocaleString() across all Temporal spec classes.
 *
 * The public surface is: buildIntlFormatter() (central entry point), resolveLocale(),
 * resolveCalendar(), validateCalendar(), validateStyleConflicts(), and
 * stripPatternComponents(). The private helpers applyHourCycle(),
 * buildPatternFromComponents() and widenHourField() support buildIntlFormatter()
 * internally.
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
     * Map from ICU calendar type (as reported by IntlCalendar::getType()) to the
     * TC39/BCP 47 calendar identifier. Types not listed here are already spelled
     * the same in both vocabularies (hebrew, chinese, japanese, …).
     *
     * @var array<string, string>
     */
    private const ICU_TO_CALENDAR = [
        'gregorian' => 'gregory',
        'ethiopic-amete-alem' => 'ethioaa',
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

        $calendar = self::intlCalendarFor(timeZone: null, locale: $locale);
        // Every locale resolves to some calendar; ICU falls back to gregorian when the
        // locale is unknown, and only fails outright if it cannot allocate one at all.
        $icuType = $calendar?->getType() ?? 'gregorian';

        return self::ICU_TO_CALENDAR[$icuType] ?? $icuType;
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
    public static function formatEpoch(
        \IntlDateFormatter $formatter,
        int $epochSec,
        int $subNs,
        string $timeZone,
        string $locale,
    ): string|false {
        $calendar = self::intlCalendarFor(self::icuTimeZoneId($timeZone), $locale);
        if ($calendar === null) {
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
            $locale = sprintf('%s@calendar=%s', $locale, $calendarOpt);
        }

        $timeZone = self::icuTimeZoneId($timeZone);

        // Apply hourCycle as a Unicode locale extension
        /** @var mixed $hourCycleOpt */
        $hourCycleOpt = $opts['hourCycle'] ?? null;
        if (is_string($hourCycleOpt)) {
            $locale = self::applyHourCycle($locale, $hourCycleOpt);
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

        // IntlDateFormatter only respects a non-gregorian calendar when an explicit
        // IntlCalendar instance is passed, so resolve the locale's calendar first and
        // pass one whenever it is not gregorian. The calendar may come from a keyword
        // (en-u-ca-islamic-tbla, en@calendar=islamic-tbla — including the one appended
        // above for the `calendar` option) or from the locale's own default, as with
        // th-TH → buddhist, which carries no keyword at all.
        $calendarObj = self::intlCalendarFor($timeZone, $locale);
        if ($calendarObj?->getType() === 'gregorian') {
            $calendarObj = null;
        }

        $styleMap = [
            'full' => \IntlDateFormatter::FULL,
            'long' => \IntlDateFormatter::LONG,
            'medium' => \IntlDateFormatter::MEDIUM,
            'short' => \IntlDateFormatter::SHORT,
        ];

        /** @var mixed $dateStyleOpt */
        $dateStyleOpt = $opts['dateStyle'] ?? null;
        /** @var mixed $timeStyleOpt */
        $timeStyleOpt = $opts['timeStyle'] ?? null;
        $dateStyle = is_string($dateStyleOpt) ? $dateStyleOpt : null;
        $timeStyle = is_string($timeStyleOpt) ? $timeStyleOpt : null;

        if ($dateStyle !== null || $timeStyle !== null) {
            self::validateStyleConflicts($opts);

            $dateType = $dateStyle !== null
                ? $styleMap[$dateStyle] ?? \IntlDateFormatter::MEDIUM
                : \IntlDateFormatter::NONE;
            $timeType = $timeStyle !== null
                ? $styleMap[$timeStyle] ?? \IntlDateFormatter::SHORT
                : \IntlDateFormatter::NONE;

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
                if ($calendarObj !== null) {
                    $tmpFormatter->setCalendar($calendarObj);
                }
                $pattern = $tmpFormatter->getPattern();
                if ($pattern === false) {
                    $pattern = '';
                }
                $pattern = self::stripPatternComponents($pattern, $absentComponent);
                $formatter = new \IntlDateFormatter(
                    $locale,
                    \IntlDateFormatter::NONE,
                    \IntlDateFormatter::NONE,
                    $timeZone,
                    $calendarObj,
                    $pattern,
                );
                if ($calendarObj !== null) {
                    $formatter->setCalendar($calendarObj);
                }
                return $formatter;
            }

            $formatter = new \IntlDateFormatter($locale, $dateType, $timeType, $timeZone, $calendarObj);
            if ($calendarObj !== null) {
                $formatter->setCalendar($calendarObj);
            }
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
            if ($calendarObj !== null) {
                $formatter->setCalendar($calendarObj);
            }
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
        if ($calendarObj !== null) {
            $formatter->setCalendar($calendarObj);
        }
        return $formatter;
    }

    /**
     * Strips year or day components from an ICU date pattern.
     *
     * For 'year': removes y, Y, u, U, r, G (era often pairs with year) pattern chars
     * and surrounding separators/whitespace.
     * For 'day': removes d, D pattern chars and surrounding separators.
     *
     * Quoted literals (inside single quotes) are preserved.
     *
     * @param 'year'|'day' $which
     */
    public static function stripPatternComponents(string $pattern, string $which): string
    {
        if ($which === 'year') {
            // Remove year-related fields: y, Y, u, U, r and era G
            $result = (string) preg_replace('/[yYuUrG]+/', replacement: '', subject: $pattern);
        } else {
            // Remove day-related fields: d, D
            $result = (string) preg_replace('/[dD]+/', replacement: '', subject: $pattern);
        }

        // Clean up leftover separators: double separators, leading/trailing punctuation
        $result = (string) preg_replace('/\s*[,\/\-\.]\s*(?=[,\/\-\.\s]|$)/', replacement: '', subject: $result);
        $result = (string) preg_replace('/^[\s,\/\-\.]+/', replacement: '', subject: $result);
        $result = (string) preg_replace('/[\s,\/\-\.]+$/', replacement: '', subject: $result);
        // Collapse multiple spaces
        $result = (string) preg_replace('/\s{2,}/', replacement: ' ', subject: $result);

        return trim($result);
    }

    /**
     * Returns the ICU calendar a locale resolves to, or null if ICU cannot create one.
     *
     * Wraps {@see \IntlCalendar::createInstance()} behind an explicit ?\IntlCalendar
     * return type so callers' null handling type-checks consistently across analyzers
     * (PHPStan's bundled stub types the factory as non-null; the runtime and the PHP
     * manual declare it ?\IntlCalendar — hence the ignore below, which keeps the
     * nullable contract callers rely on).
     *
     * @phpstan-ignore return.unusedType
     */
    private static function intlCalendarFor(?string $timeZone, string $locale): ?\IntlCalendar
    {
        return \IntlCalendar::createInstance($timeZone, $locale);
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
     * Appends a -u-hc-{hourCycle} extension to a BCP 47 locale string.
     */
    private static function applyHourCycle(string $locale, string $hourCycle): string
    {
        // If there's already a -u- extension, append hc keyword
        if (str_contains($locale, '-u-')) {
            return sprintf('%s-hc-%s', $locale, $hourCycle);
        }
        // If there's an @keyword section, insert before it
        $atPos = strpos($locale, needle: '@');
        if ($atPos !== false) {
            return sprintf(
                '%s-u-hc-%s%s',
                substr($locale, offset: 0, length: $atPos),
                $hourCycle,
                substr($locale, $atPos),
            );
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
            if (($opts['weekday'] ?? null) !== null) {
                $parts[] = match ($opts['weekday']) {
                    'narrow' => 'EEEEE',
                    'short' => 'EEE',
                    'long' => 'EEEE',
                    default => 'EEE',
                };
            }
            if (($opts['era'] ?? null) !== null) {
                $parts[] = match ($opts['era']) {
                    'narrow' => 'GGGGG',
                    'short' => 'GGG',
                    'long' => 'GGGG',
                    default => 'GGG',
                };
            }
            if (($opts['year'] ?? null) !== null) {
                $parts[] = $opts['year'] === '2-digit' ? 'yy' : 'y';
            }
            if (($opts['month'] ?? null) !== null) {
                $parts[] = match ($opts['month']) {
                    'numeric' => 'M',
                    '2-digit' => 'MM',
                    'narrow' => 'MMMMM',
                    'short' => 'MMM',
                    'long' => 'MMMM',
                    default => 'M',
                };
            }
            if (($opts['day'] ?? null) !== null) {
                $parts[] = $opts['day'] === '2-digit' ? 'dd' : 'd';
            }
        }

        // Time components
        if (($opts['hour'] ?? null) !== null) {
            // Use 'j' skeleton symbol which picks locale-appropriate hour cycle
            $parts[] = $opts['hour'] === '2-digit' ? 'jj' : 'j';
        }
        if (($opts['minute'] ?? null) !== null) {
            $parts[] = $opts['minute'] === '2-digit' ? 'mm' : 'm';
        }
        if (($opts['second'] ?? null) !== null) {
            $parts[] = $opts['second'] === '2-digit' ? 'ss' : 's';
        }
        if (($opts['fractionalSecondDigits'] ?? null) !== null) {
            /** @var mixed $fsd */
            $fsd = $opts['fractionalSecondDigits'];
            $digits = is_int($fsd) ? $fsd : (int) (is_string($fsd) ? $fsd : 0);
            $parts[] = str_repeat('S', times: max(0, $digits));
        }
        if (($opts['dayPeriod'] ?? null) !== null) {
            $parts[] = match ($opts['dayPeriod']) {
                'narrow' => 'BBBBB',
                'short' => 'B',
                'long' => 'BBBB',
                default => 'B',
            };
        }
        if (($opts['timeZoneName'] ?? null) !== null) {
            $parts[] = match ($opts['timeZoneName']) {
                'short' => 'z',
                'long' => 'zzzz',
                'shortOffset' => 'O',
                'longOffset' => 'OOOO',
                'shortGeneric' => 'v',
                'longGeneric' => 'vvvv',
                default => 'z',
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

        return ($opts['hour'] ?? null) === '2-digit' ? self::widenHourField($pattern) : $pattern;
    }

    /**
     * Widens the hour field of an ICU pattern to two characters.
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
    private static function widenHourField(string $pattern): string
    {
        $widened = preg_replace_callback(
            "/'[^']*'|([hHKk])\\1*/",
            /** @param array<array-key, string> $match */
            static fn(array $match): string => array_key_exists(1, $match)
                ? str_repeat($match[1], times: 2)
                : $match[0],
            subject: $pattern,
        );

        return $widened ?? $pattern;
    }
}
