<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\LocaleComponentMode;

/** Owns the ECMA-402 constructor defaults and per-value option projection used by the test shim. */
final class IntlDateTimeFormatOptions
{
    /**
     * @var array<string, array<string, true>>
     */
    private const array EXPRESSIBLE_KINDS = [
        'weekday' => ['date' => true, 'datetime' => true, 'exact' => true],
        'era' => ['date' => true, 'yearmonth' => true, 'datetime' => true, 'exact' => true],
        'year' => ['date' => true, 'yearmonth' => true, 'datetime' => true, 'exact' => true],
        'month' => ['date' => true, 'yearmonth' => true, 'monthday' => true, 'datetime' => true, 'exact' => true],
        'day' => ['date' => true, 'monthday' => true, 'datetime' => true, 'exact' => true],
        'dayPeriod' => ['time' => true, 'datetime' => true, 'exact' => true],
        'hour' => ['time' => true, 'datetime' => true, 'exact' => true],
        'minute' => ['time' => true, 'datetime' => true, 'exact' => true],
        'second' => ['time' => true, 'datetime' => true, 'exact' => true],
        'fractionalSecondDigits' => ['time' => true, 'datetime' => true, 'exact' => true],
        'timeZoneName' => ['exact' => true],
        'dateStyle' => [
            'date' => true,
            'yearmonth' => true,
            'monthday' => true,
            'datetime' => true,
            'exact' => true,
        ],
        'timeStyle' => ['time' => true, 'datetime' => true, 'exact' => true],
    ];

    /**
     * ToDateTimeOptions' `required: "any"` fields. `era` and `timeZoneName` do not
     * suppress the constructor's defaults when requested alone.
     *
     * @var list<string>
     */
    private const array DEFAULT_SUPPRESSORS = [
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

    /**
     * ECMA-402 ToDateTimeOptions ( options, "any", "all" ).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function withConstructorDefaults(array $options): array
    {
        if (($options['dateStyle'] ?? null) !== null || ($options['timeStyle'] ?? null) !== null) {
            return $options;
        }
        foreach (self::DEFAULT_SUPPRESSORS as $option) {
            if (($options[$option] ?? null) !== null) {
                return $options;
            }
        }
        foreach (['year', 'month', 'day', 'hour', 'minute', 'second'] as $option) {
            $options[$option] = 'numeric';
        }
        return $options;
    }

    /**
     * @param array<string, mixed> $options
     * @param LocaleComponentMode|'exact' $kind
     * @return array<string, mixed>
     */
    public static function forKind(array $options, LocaleComponentMode|string $kind): array
    {
        $kindName = match ($kind) {
            LocaleComponentMode::Date => 'date',
            LocaleComponentMode::DateTime => 'datetime',
            LocaleComponentMode::MonthDay => 'monthday',
            LocaleComponentMode::Time => 'time',
            LocaleComponentMode::YearMonth => 'yearmonth',
            'exact' => 'exact',
        };
        $kept = false;
        foreach (self::EXPRESSIBLE_KINDS as $option => $kinds) {
            if (!array_key_exists($kindName, $kinds)) {
                unset($options[$option]);
                continue;
            }
            $kept = $kept || ($options[$option] ?? null) !== null;
        }

        if (!$kept) {
            throw new TypeError(sprintf(
                'Intl.DateTimeFormat: no overlap between the requested options and a %s value.',
                $kindName,
            ));
        }
        return $options;
    }
}
