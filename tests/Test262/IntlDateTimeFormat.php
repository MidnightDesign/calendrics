<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

use Calendrics\Exception\TypeError;
use Calendrics\Spec\Instant;
use Calendrics\Spec\Internal\IntlFormatter;
use Calendrics\Spec\Internal\PlainLocaleFormat;
use Calendrics\Spec\Internal\PlainLocaleFormattable;
use Calendrics\Spec\ZonedDateTime;

/**
 * Test-harness stand-in for the ECMA-402 `Intl.DateTimeFormat` object, scoped to
 * what the intl402 Temporal fixtures actually exercise: `format()`,
 * `formatToParts()`, and `resolvedOptions()` over Temporal spec values and the
 * legacy {@see JsDate} shim.
 *
 * This is a second entry point into the same formatting code, not a wrapper around
 * `Temporal.X.prototype.toLocaleString`, and ECMA-402 keeps the two apart. A
 * formatter resolves one component set in its constructor, for every value it will
 * later be handed, so `{ dateStyle, timeStyle }` is legal here and narrows to the
 * date half when a `PlainDate` arrives; the same options passed to
 * `PlainDate.prototype.toLocaleString` throw, because that entry point resolves
 * against the receiver's own data model. {@see IntlDateTimeFormatOptions} resolves
 * the constructor's option set once and narrows it to each value's data model,
 * throwing a TypeError when nothing survives.
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

    public function __construct(mixed $locales = null, mixed $options = null)
    {
        $this->locales = is_string($locales) || is_array($locales) ? $locales : null;
        /** @var array<string, mixed> $opts */
        $opts = is_object($options) ? get_object_vars($options) : (is_array($options) ? $options : []);
        $this->options = IntlDateTimeFormatOptions::withConstructorDefaults($opts);
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
        [$formatter, $epochSec, $subNs] = $this->resolveFor($value);
        $result = IntlFormatter::formatEpoch($formatter, $epochSec, $subNs);
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
        [$formatter, $epochSec, $subNs] = $this->resolveFor($value);
        $pattern = $formatter->getPattern();
        if ($pattern === false) {
            throw new \RuntimeException('formatToParts(): formatter has no retrievable pattern.');
        }
        return $this->patternToParts($formatter, $pattern, $epochSec, $subNs);
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
     * ECMA-402 HandleDateTimeValue: narrows this formatter's options to what $value's
     * data model can express, then builds the formatter and the epoch instant to render.
     *
     * The epoch instant stays split into whole seconds and sub-second nanoseconds so
     * that values at the ±271821-year limits keep their milliseconds — see
     * {@see IntlFormatter::formatEpoch()}.
     *
     * @return array{\IntlDateFormatter, int, int}
     * @throws TypeError if $value cannot be formatted at all, or if the formatter asks
     *                   for nothing $value can express.
     */
    private function resolveFor(mixed $value): array
    {
        $locale = $this->locale();

        if ($value instanceof PlainLocaleFormattable) {
            $format = PlainLocaleFormat::from($value);
            $options = IntlDateTimeFormatOptions::forKind($this->options, $format->components);
            IntlFormatter::validateCalendar($format->calendarId, $locale, $options, $format->components);
            return [
                IntlFormatter::buildIntlFormatter($locale, 'UTC', $options, $format->components),
                $format->epochSec,
                $format->subNs,
            ];
        }

        self::assertExactValue($value);
        $options = IntlDateTimeFormatOptions::forKind($this->options, 'exact');
        $timeZone = $this->timeZone();
        $formatter = IntlFormatter::buildIntlFormatter($locale, $timeZone, $options);

        if ($value instanceof Instant) {
            [$epochSec, $subNs] = $value->epochParts();
            return [$formatter, $epochSec, $subNs];
        }

        $epochMs = match (true) {
            $value instanceof JsDate => $value->epochMilliseconds,
            is_int($value), is_float($value) => $value,
            default => throw new \LogicException('Exact value validation and conversion are inconsistent.'),
        };
        $epochSec = (int) floor((float) $epochMs / 1_000.0);
        $subNs = (int) round(((float) $epochMs - ((float) $epochSec * 1_000.0)) * 1_000_000.0);
        return [$formatter, $epochSec, $subNs];
    }

    /**
     * @throws TypeError for values ECMA-402 refuses to format: a ZonedDateTime, which
     *                   must be converted first, and anything that is neither a
     *                   Temporal value nor an epoch-millisecond number.
     */
    private static function assertExactValue(mixed $value): void
    {
        if ($value instanceof ZonedDateTime) {
            throw new TypeError('Intl.DateTimeFormat cannot format a Temporal.ZonedDateTime; convert it first.');
        }
        if ($value instanceof Instant || $value instanceof JsDate || is_int($value) || is_float($value)) {
            return;
        }
        throw new TypeError('Intl.DateTimeFormat: unsupported value.');
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
    private function patternToParts(\IntlDateFormatter $formatter, string $pattern, int $epochSec, int $subNs): array
    {
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
                $value = IntlFormatter::formatEpoch($sub, $epochSec, $subNs);
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
