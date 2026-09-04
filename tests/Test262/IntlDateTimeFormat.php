<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

use Calendrics\Exception\TypeError;
use Calendrics\Spec\Instant;
use Calendrics\Spec\Internal\AnchorMath;
use Calendrics\Spec\Internal\IntlFormatter;
use Calendrics\Spec\Internal\PlainLocaleFormattable;
use Calendrics\Spec\PlainDate;
use Calendrics\Spec\PlainDateTime;
use Calendrics\Spec\PlainMonthDay;
use Calendrics\Spec\PlainTime;
use Calendrics\Spec\PlainYearMonth;
use Calendrics\Spec\ZonedDateTime;

/**
 * Test-harness stand-in for the ECMA-402 `Intl.DateTimeFormat` object, scoped to
 * what the intl402 Temporal fixtures actually exercise: `format()`,
 * `formatToParts()`, `formatRange()`, `formatRangeToParts()` and
 * `resolvedOptions()` over Temporal spec values and the legacy {@see JsDate} shim.
 *
 * This is a second entry point into the same formatting code, not a wrapper around
 * `Temporal.X.prototype.toLocaleString`, and ECMA-402 keeps the two apart. A
 * formatter resolves one component set in its constructor, for every value it will
 * later be handed, so `{ dateStyle, timeStyle }` is legal here and narrows to the
 * date half when a `PlainDate` arrives; the same options passed to
 * `PlainDate.prototype.toLocaleString` throw, because that entry point resolves
 * against the receiver's own data model. Formatting therefore runs in two steps:
 * {@see withDefaultComponents()} resolves the constructor's option set once, and
 * {@see optionsFor()} narrows it to each value's data model, throwing a TypeError
 * when nothing survives.
 *
 * `formatToParts()` has no ext-intl equivalent, so it is reconstructed from the
 * resolved ICU pattern: field runs in the pattern are formatted one at a time and
 * emitted as typed parts, literal runs verbatim. This reproduces the part types
 * and values ECMA-402 PartitionDateTimePattern yields for the locales the
 * fixtures use.
 *
 * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
 */
final class IntlDateTimeFormat
{
    /**
     * Joins the two endpoints of a range. ECMA-402 takes the separator from the
     * locale's interval patterns; every fixture here formats an `en`-style locale,
     * where it is an en dash.
     */
    private const string RANGE_SEPARATOR = ' – ';

    /** @var string|array<array-key, mixed>|null */
    private readonly string|array|null $locales;

    /** @var array<string, mixed> The options bag with the constructor's defaults resolved in. */
    private readonly array $options;

    /** Maps an ICU pattern field character to its ECMA-402 part type. */
    private const PATTERN_CHAR_TYPES = [
        'G' => 'era',
        'y' => 'year',
        'Y' => 'year',
        'u' => 'year',
        'U' => 'yearName',
        'r' => 'relatedYear',
        'M' => 'month',
        'L' => 'month',
        'd' => 'day',
        'D' => 'day',
        'F' => 'day',
        'g' => 'day',
        'E' => 'weekday',
        'e' => 'weekday',
        'c' => 'weekday',
        'a' => 'dayPeriod',
        'b' => 'dayPeriod',
        'B' => 'dayPeriod',
        'h' => 'hour',
        'H' => 'hour',
        'k' => 'hour',
        'K' => 'hour',
        'm' => 'minute',
        's' => 'second',
        'S' => 'fractionalSecond',
        'z' => 'timeZoneName',
        'Z' => 'timeZoneName',
        'O' => 'timeZoneName',
        'v' => 'timeZoneName',
        'V' => 'timeZoneName',
        'X' => 'timeZoneName',
        'x' => 'timeZoneName',
    ];

    /**
     * The component options ECMA-402 ToDateTimeOptions inspects under `required: "any"`.
     * `era` and `timeZoneName` are deliberately absent: asking for either alone still
     * leaves the formatter needing defaults.
     *
     * @var list<string>
     */
    private const array ANY_COMPONENTS = [
        'weekday',
        'year',
        'month',
        'day',
        'dayPeriod',
        'hour',
        'minute',
        'second',
        'fractionalSecondDigits',
    ];

    /** @var list<string> Every component option a value's data model is matched against. */
    private const array COMPONENT_OPTIONS = [
        'weekday',
        'era',
        'year',
        'month',
        'day',
        'dayPeriod',
        'hour',
        'minute',
        'second',
        'fractionalSecondDigits',
        'timeZoneName',
    ];

    /**
     * Which component options each kind of formattable value can express, keyed by the
     * component mode {@see IntlFormatter::buildIntlFormatter()} takes. A `PlainYearMonth`
     * has no day to render and a `PlainMonthDay` no year — and so no era or weekday
     * either, both of which a bare month and day leave undetermined. Only an exact
     * instant sits in a real time zone, so only `exact` keeps `timeZoneName`.
     *
     * @var array<string, list<string>>
     */
    private const array KIND_COMPONENTS = [
        'date' => ['weekday', 'era', 'year', 'month', 'day'],
        'yearmonth' => ['era', 'year', 'month'],
        'monthday' => ['month', 'day'],
        'time' => ['dayPeriod', 'hour', 'minute', 'second', 'fractionalSecondDigits'],
        'datetime' => [
            'weekday',
            'era',
            'year',
            'month',
            'day',
            'dayPeriod',
            'hour',
            'minute',
            'second',
            'fractionalSecondDigits',
        ],
        'exact' => self::COMPONENT_OPTIONS,
    ];

    /**
     * Which of dateStyle/timeStyle each kind keeps. A style the value cannot express is
     * dropped rather than rejected, so `{ dateStyle, timeStyle }` renders a `PlainDate`
     * as its date half.
     *
     * @var array<string, list<string>>
     */
    private const array KIND_STYLES = [
        'date' => ['dateStyle'],
        'yearmonth' => ['dateStyle'],
        'monthday' => ['dateStyle'],
        'time' => ['timeStyle'],
        'datetime' => ['dateStyle', 'timeStyle'],
        'exact' => ['dateStyle', 'timeStyle'],
    ];

    public function __construct(mixed $locales = null, mixed $options = null)
    {
        $this->locales = is_string($locales) || is_array($locales) ? $locales : null;
        /** @var array<string, mixed> $opts */
        $opts = is_object($options) ? get_object_vars($options) : (is_array($options) ? $options : []);
        $this->options = self::withDefaultComponents($opts);
    }

    /**
     * ECMA-402 DateTimeFormat.prototype.format ( date ).
     *
     * ZonedDateTime is rejected with TypeError per ECMA-402 (it must be converted
     * before formatting). Numbers are epoch milliseconds, matching legacy-Date
     * formatting.
     */
    public function format(mixed $value): string
    {
        [$formatter, $epochSec, $subNs, $timeZone] = $this->resolveFor($value);
        $result = IntlFormatter::formatEpoch($formatter, $epochSec, $subNs, $timeZone, $this->locale());
        if ($result === false) {
            throw new \RuntimeException('Intl.DateTimeFormat.format(): IntlDateFormatter::format() failed.');
        }
        return $result;
    }

    /**
     * ECMA-402 DateTimeFormat.prototype.formatToParts ( date ), reconstructed from
     * the resolved ICU pattern (ext-intl exposes no parts API).
     *
     * @return list<IntlFormatPart>
     */
    public function formatToParts(mixed $value): array
    {
        [$formatter, $epochSec, $subNs, $timeZone] = $this->resolveFor($value);
        $pattern = $formatter->getPattern();
        if ($pattern === false) {
            throw new \RuntimeException('formatToParts(): formatter has no retrievable pattern.');
        }
        return $this->patternToParts($formatter, $pattern, $epochSec, $subNs, $timeZone);
    }

    /**
     * ECMA-402 DateTimeFormat.prototype.formatRange ( startDate, endDate ).
     *
     * PHP's intl extension binds no ICU date-interval formatter, so the endpoints are
     * formatted separately and joined. Where ECMA-402 elides the fields the endpoints
     * share — `11/4 – 11/5/2025` rather than `11/4/2025 – 11/5/2025` — this shim
     * repeats them. The one elision the fixtures assert on, two endpoints that render
     * identically collapsing to that single rendering, is reproduced exactly.
     */
    public function formatRange(mixed $start, mixed $end): string
    {
        self::assertSameTemporalType($start, $end);
        $from = $this->format($start);
        $to = $this->format($end);
        return $from === $to ? $from : $from . self::RANGE_SEPARATOR . $to;
    }

    /**
     * ECMA-402 DateTimeFormat.prototype.formatRangeToParts ( startDate, endDate ), on
     * the same terms as {@see formatRange()}.
     *
     * @return list<IntlFormatPart>
     */
    public function formatRangeToParts(mixed $start, mixed $end): array
    {
        self::assertSameTemporalType($start, $end);
        $from = $this->formatToParts($start);
        $to = $this->formatToParts($end);
        if (self::partsToString($from) === self::partsToString($to)) {
            return self::withSource($from, 'shared');
        }
        return [
            ...self::withSource($from, 'startRange'),
            new IntlFormatPart('literal', self::RANGE_SEPARATOR, 'shared'),
            ...self::withSource($to, 'endRange'),
        ];
    }

    /**
     * ECMA-402 DateTimeFormat.prototype.resolvedOptions (), scoped to the fields
     * fixtures read. `calendarId` mirrors `calendar` because the transpiler renames
     * `.calendar` property reads to `.calendarId` (the Temporal property name).
     */
    public function resolvedOptions(): object
    {
        $locale = $this->locale();
        $calendar = IntlFormatter::resolveCalendar($locale, $this->options);
        return (object) [
            'locale' => $locale,
            'calendar' => $calendar,
            'calendarId' => $calendar,
            'timeZone' => $this->timeZone(),
            'numberingSystem' => 'latn',
        ];
    }

    /**
     * ECMA-402 ToDateTimeOptions ( options, "any", "all" ) — the pair
     * `Intl.DateTimeFormat`'s own constructor uses, and the point where this entry
     * point parts company with `Temporal.X.prototype.toLocaleString`. Asking for
     * nothing means a full date and time; so does `{ era: "narrow" }`, since era is
     * in neither the required list nor the defaults.
     *
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    private static function withDefaultComponents(array $opts): array
    {
        if (($opts['dateStyle'] ?? null) !== null || ($opts['timeStyle'] ?? null) !== null) {
            return $opts;
        }
        foreach (self::ANY_COMPONENTS as $opt) {
            if (($opts[$opt] ?? null) !== null) {
                return $opts;
            }
        }
        foreach (['year', 'month', 'day', 'hour', 'minute', 'second'] as $opt) {
            $opts[$opt] = 'numeric';
        }
        return $opts;
    }

    /**
     * ECMA-402 HandleDateTimeValue: narrows this formatter's options to what $value's
     * data model can express, then builds the formatter and the epoch instant to render.
     *
     * The epoch instant stays split into whole seconds and sub-second nanoseconds so
     * that values at the ±271821-year limits keep their milliseconds — see
     * {@see IntlFormatter::formatEpoch()}.
     *
     * @return array{\IntlDateFormatter, int, int, string}
     * @throws TypeError if $value cannot be formatted at all, or if the formatter asks
     *                   for nothing $value can express.
     */
    private function resolveFor(mixed $value): array
    {
        $locale = $this->locale();
        $kind = self::kindOf($value);
        $opts = $this->optionsFor($kind);

        if ($value instanceof PlainLocaleFormattable) {
            IntlFormatter::validateCalendar(self::calendarIdOf($value), $locale, $opts, $kind);
            [$epochSec, $subNs] = self::epochPartsOf($value);
            // Plain types always format in UTC (see HasPlainLocaleString::toLocaleString).
            return [IntlFormatter::buildIntlFormatter($locale, 'UTC', $opts, $kind), $epochSec, $subNs, 'UTC'];
        }

        $timeZone = $this->timeZone();
        $formatter = IntlFormatter::buildIntlFormatter($locale, $timeZone, $opts);

        if ($value instanceof Instant) {
            [$epochSec, $subNs] = $value->epochParts();
            return [$formatter, $epochSec, $subNs, $timeZone];
        }

        $epochMs = match (true) {
            $value instanceof JsDate => $value->epochMilliseconds,
            is_int($value), is_float($value) => $value,
            default => throw new TypeError('Intl.DateTimeFormat: unsupported value.'),
        };
        $epochSec = (int) floor((float) $epochMs / 1_000.0);
        $subNs = (int) round(((float) $epochMs - ((float) $epochSec * 1_000.0)) * 1_000_000.0);
        return [$formatter, $epochSec, $subNs, $timeZone];
    }

    /**
     * Classifies a value into the component mode its data model corresponds to.
     *
     * @throws TypeError for values ECMA-402 refuses to format: a ZonedDateTime, which
     *                   must be converted first, and anything that is neither a
     *                   Temporal value nor an epoch-millisecond number.
     */
    private static function kindOf(mixed $value): string
    {
        return match (true) {
            $value instanceof ZonedDateTime => throw new TypeError(
                'Intl.DateTimeFormat cannot format a Temporal.ZonedDateTime; convert it first.',
            ),
            $value instanceof PlainDate => 'date',
            $value instanceof PlainDateTime => 'datetime',
            $value instanceof PlainYearMonth => 'yearmonth',
            $value instanceof PlainMonthDay => 'monthday',
            $value instanceof PlainTime => 'time',
            $value instanceof Instant, $value instanceof JsDate, is_int($value), is_float($value) => 'exact',
            default => throw new TypeError('Intl.DateTimeFormat: unsupported value.'),
        };
    }

    /**
     * Narrows the formatter's options to the fields $kind's data model can express.
     *
     * @return array<string, mixed>
     * @throws TypeError if the formatter asks for nothing this kind can express — the
     *                   "no overlap" case, as with a time-only formatter and a PlainDate.
     */
    private function optionsFor(string $kind): array
    {
        $opts = $this->options;
        $kept = false;

        foreach ([...self::COMPONENT_OPTIONS, 'dateStyle', 'timeStyle'] as $opt) {
            $expressible =
                in_array($opt, self::KIND_COMPONENTS[$kind], strict: true)
                || in_array($opt, self::KIND_STYLES[$kind], strict: true);
            if (!$expressible) {
                unset($opts[$opt]);
                continue;
            }
            $kept = $kept || ($opts[$opt] ?? null) !== null;
        }

        if (!$kept) {
            throw new TypeError(sprintf(
                'Intl.DateTimeFormat: no overlap between the requested options and a %s value.',
                $kind,
            ));
        }
        return $opts;
    }

    /**
     * ECMA-402 PartitionDateTimeRangePattern step 5: once either endpoint is a Temporal
     * value, both must be the same Temporal type. A legacy Date and a bare number are
     * not Temporal values, so pairing either with any Temporal value fails too.
     *
     * @throws TypeError if the endpoints are of different kinds.
     */
    private static function assertSameTemporalType(mixed $start, mixed $end): void
    {
        $startType = self::temporalTypeOf($start);
        $endType = self::temporalTypeOf($end);
        if ($startType === $endType) {
            return;
        }
        throw new TypeError('Intl.DateTimeFormat: range endpoints must be the same Temporal type.');
    }

    /** The Temporal class $value belongs to, or null if it is not a Temporal value. */
    private static function temporalTypeOf(mixed $value): ?string
    {
        return $value instanceof Instant || $value instanceof ZonedDateTime || $value instanceof PlainLocaleFormattable
            ? $value::class
            : null;
    }

    /**
     * The epoch instant a plain value renders at, reading its ISO fields as UTC.
     *
     * @return array{int, int} Epoch seconds, then nanoseconds within that second.
     */
    private static function epochPartsOf(PlainLocaleFormattable $value): array
    {
        [$isoYear, $isoMonth, $isoDay] = match (true) {
            $value instanceof PlainDate, $value instanceof PlainDateTime => [
                $value->isoYear,
                $value->isoMonth,
                $value->isoDay,
            ],
            $value instanceof PlainYearMonth => [$value->isoYear, $value->isoMonth, $value->referenceISODay],
            $value instanceof PlainMonthDay => [$value->referenceISOYear, $value->isoMonth, $value->isoDay],
            // PlainTime carries no date, and no time pattern renders the day it sits on.
            default => [1970, 1, 1],
        };
        $epochSec = AnchorMath::isoDateToEpochDays($isoYear, $isoMonth, $isoDay) * 86_400;

        if (!$value instanceof PlainDateTime && !$value instanceof PlainTime) {
            return [$epochSec, 0];
        }
        return [
            $epochSec + ($value->hour * 3_600) + ($value->minute * 60) + $value->second,
            ($value->millisecond * 1_000_000) + ($value->microsecond * 1_000) + $value->nanosecond,
        ];
    }

    /**
     * The calendar identifier a plain value carries, or null for PlainTime, which
     * carries none and is therefore renderable by any formatter.
     */
    private static function calendarIdOf(PlainLocaleFormattable $value): ?string
    {
        return match (true) {
            $value instanceof PlainDate,
            $value instanceof PlainDateTime,
            $value instanceof PlainYearMonth,
            $value instanceof PlainMonthDay,
                => $value->calendarId,
            default => null,
        };
    }

    /**
     * Stamps every part with the endpoint of the range it came from — ECMA-402's
     * `[[Source]]`, which fixtures read to tell the two endpoints apart.
     *
     * @param list<IntlFormatPart> $parts
     * @return list<IntlFormatPart>
     */
    private static function withSource(array $parts, string $source): array
    {
        return array_map(
            static fn(IntlFormatPart $part): IntlFormatPart => new IntlFormatPart($part->type, $part->value, $source),
            $parts,
        );
    }

    /** @param list<IntlFormatPart> $parts */
    private static function partsToString(array $parts): string
    {
        return implode('', array_map(static fn(IntlFormatPart $part): string => $part->value, $parts));
    }

    private function locale(): string
    {
        return IntlFormatter::resolveLocale($this->locales);
    }

    private function timeZone(): string
    {
        /** @var mixed $tzOpt */
        $tzOpt = $this->options['timeZone'] ?? null;
        return is_string($tzOpt) ? $tzOpt : 'UTC';
    }

    /**
     * Splits an ICU pattern into field runs and literals, formatting each field
     * run individually to produce its part value.
     *
     * @return list<IntlFormatPart>
     */
    private function patternToParts(
        \IntlDateFormatter $formatter,
        string $pattern,
        int $epochSec,
        int $subNs,
        string $timeZone,
    ): array {
        $parts = [];
        $literal = '';

        $len = strlen($pattern);
        for ($i = 0; $i < $len;) {
            $ch = $pattern[$i];
            if ($ch === "'") {
                // Quoted literal; '' inside (or standalone) is an escaped apostrophe.
                if (($i + 1) < $len && $pattern[$i + 1] === "'") {
                    $literal .= "'";
                    $i += 2;
                    continue;
                }
                $end = strpos($pattern, needle: "'", offset: $i + 1);
                if ($end === false) {
                    $literal .= substr($pattern, $i + 1);
                    $i = $len;
                    continue;
                }
                $quoted = substr($pattern, $i + 1, $end - $i - 1);
                $literal .= $quoted === '' ? "'" : str_replace(search: "''", replace: "'", subject: $quoted);
                $i = $end + 1;
                continue;
            }
            if (ctype_alpha($ch)) {
                $run = $ch;
                $j = $i + 1;
                while ($j < $len && $pattern[$j] === $ch) {
                    $run .= $ch;
                    $j++;
                }
                $i = $j;
                $type = self::PATTERN_CHAR_TYPES[$ch] ?? null;
                if ($type === null) {
                    // Unknown field letter — treat its output as literal text.
                    $literal .= $run;
                    continue;
                }
                $sub = clone $formatter;
                $sub->setPattern($run);
                $value = IntlFormatter::formatEpoch($sub, $epochSec, $subNs, $timeZone, $this->locale());
                if ($value === false || $value === '') {
                    continue;
                }
                if ($literal !== '') {
                    $parts[] = new IntlFormatPart('literal', $literal);
                    $literal = '';
                }
                $parts[] = new IntlFormatPart($type, $value);
                continue;
            }
            $literal .= $ch;
            $i++;
        }
        if ($literal !== '') {
            $parts[] = new IntlFormatPart('literal', $literal);
        }
        return $parts;
    }
}
