<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

use Calendrics\Exception\TypeError;
use Calendrics\Spec\Instant;
use Calendrics\Spec\Internal\IntlFormatter;
use Calendrics\Spec\PlainDate;
use Calendrics\Spec\PlainDateTime;
use Calendrics\Spec\PlainMonthDay;
use Calendrics\Spec\PlainTime;
use Calendrics\Spec\PlainYearMonth;
use Calendrics\Spec\ZonedDateTime;

/**
 * Test-harness stand-in for the ECMA-402 `Intl.DateTimeFormat` object, scoped to
 * what the intl402 Temporal fixtures actually exercise: `format()`,
 * `formatToParts()`, and `resolvedOptions()` over Temporal spec values and the
 * legacy {@see JsDate} shim.
 *
 * `format()` delegates to the receiver type's own `toLocaleString()` — the exact
 * relationship ECMA-402 defines (`Temporal.X.prototype.toLocaleString` is
 * specified as `new Intl.DateTimeFormat(locales, options).format(this)`), so
 * fixture equalities compare the same code path a JS engine would share, while
 * cross-checks against {@see JsDate} and the parts-based assertions exercise the
 * option handling independently.
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

    /** @var array<string, mixed> */
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
        $this->options = $opts;
    }

    /**
     * ECMA-402 DateTimeFormat.prototype.format ( date ).
     *
     * Temporal values format exactly as their `toLocaleString(locales, options)`
     * would — the identity the fixtures assert. ZonedDateTime is rejected with
     * TypeError per ECMA-402 (it must be converted before formatting). Numbers
     * are epoch milliseconds, matching legacy-Date formatting.
     */
    public function format(mixed $value): string
    {
        if ($value instanceof ZonedDateTime) {
            throw new TypeError('Intl.DateTimeFormat cannot format a Temporal.ZonedDateTime; convert it first.');
        }
        if (
            $value instanceof Instant
            || $value instanceof PlainDate
            || $value instanceof PlainDateTime
            || $value instanceof PlainTime
            || $value instanceof PlainYearMonth
            || $value instanceof PlainMonthDay
        ) {
            return $value->toLocaleString($this->locales, $this->options);
        }
        if ($value instanceof JsDate) {
            return $value->toLocaleString($this->locales, $this->options);
        }
        if (is_int($value) || is_float($value)) {
            return new JsDate($value)->toLocaleString($this->locales, $this->options);
        }
        throw new TypeError('Intl.DateTimeFormat.format(): unsupported value.');
    }

    /**
     * ECMA-402 DateTimeFormat.prototype.formatToParts ( date ), reconstructed from
     * the resolved ICU pattern (ext-intl exposes no parts API).
     *
     * @return list<IntlFormatPart>
     */
    public function formatToParts(mixed $value): array
    {
        [$formatter, $timestamp] = $this->formatterFor($value);
        $pattern = $formatter->getPattern();
        if ($pattern === false) {
            throw new \RuntimeException('formatToParts(): formatter has no retrievable pattern.');
        }
        return self::patternToParts($formatter, $pattern, $timestamp);
    }

    /**
     * ECMA-402 DateTimeFormat.prototype.resolvedOptions (), scoped to the fields
     * fixtures read. `calendarId` mirrors `calendar` because the transpiler renames
     * `.calendar` property reads to `.calendarId` (the Temporal property name).
     */
    public function resolvedOptions(): object
    {
        $locale = IntlFormatter::resolveLocale($this->locales);
        $calendar = IntlFormatter::resolveCalendar($locale, $this->options);
        /** @var mixed $tzOpt */
        $tzOpt = $this->options['timeZone'] ?? null;
        return (object) [
            'locale' => $locale,
            'calendar' => $calendar,
            'calendarId' => $calendar,
            'timeZone' => is_string($tzOpt) ? $tzOpt : 'UTC',
            'numberingSystem' => 'latn',
        ];
    }

    /**
     * Builds the IntlDateFormatter and timestamp for a value, mirroring exactly
     * what that value's toLocaleString() builds: same default components, same
     * forced-UTC rule for Plain types, same timestamp derivation (the protected
     * trait hooks are read via reflection to guarantee the mirror can't drift).
     *
     * @return array{\IntlDateFormatter, float}
     */
    private function formatterFor(mixed $value): array
    {
        $locale = IntlFormatter::resolveLocale($this->locales);
        $opts = $this->options;
        $opts['_locale'] = $locale;

        if (
            $value instanceof PlainDate
            || $value instanceof PlainDateTime
            || $value instanceof PlainTime
            || $value instanceof PlainYearMonth
            || $value instanceof PlainMonthDay
        ) {
            $components = new \ReflectionMethod($value, 'localeDefaultComponents')->invoke($value);
            \assert(is_string($components));
            $timestamp = new \ReflectionMethod($value, 'toLocaleTimestamp')->invoke($value);
            \assert(is_int($timestamp) || is_float($timestamp));
            // Plain types always format in UTC (see TemporalSerde::toLocaleString).
            return [IntlFormatter::buildIntlFormatter($locale, 'UTC', $opts, $components), (float) $timestamp];
        }

        $epochMs = match (true) {
            $value instanceof Instant => $value->epochMilliseconds,
            $value instanceof JsDate => $value->epochMilliseconds,
            is_int($value), is_float($value) => $value,
            default => throw new TypeError('Intl.DateTimeFormat.formatToParts(): unsupported value.'),
        };
        /** @var mixed $tzOpt */
        $tzOpt = $opts['timeZone'] ?? null;
        $timeZone = is_string($tzOpt) ? $tzOpt : 'UTC';
        return [IntlFormatter::buildIntlFormatter($locale, $timeZone, $opts), (float) $epochMs / 1000.0];
    }

    /**
     * Splits an ICU pattern into field runs and literals, formatting each field
     * run individually to produce its part value.
     *
     * @return list<IntlFormatPart>
     */
    private static function patternToParts(\IntlDateFormatter $formatter, string $pattern, float $timestamp): array
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
                $value = $sub->format($timestamp);
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
