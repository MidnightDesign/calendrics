<?php

declare(strict_types=1);

namespace Temporal\Tests\Test262\Helper;

/**
 * ISO string-array data providers ported from TC39's TemporalHelpers harness.
 *
 * PHP ports of the `TemporalHelpers.ISO.*` methods (plus `ISOMonths` and
 * `NotYetSupportedCalendars`) defined in the upstream test262 harness
 * (harness/temporalHelpers.js). The arrays are copied verbatim from upstream
 * and must not be modified.
 *
 * Composed into {@see \Temporal\Tests\Test262\TemporalHelpers}; the public
 * surface is `TemporalHelpers::iso*()` / `isoMonths()` / `notYetSupportedCalendars()`.
 */
trait IsoStringData
{
    /**
     * Returns PlainYearMonth-like strings that are valid and parse to November 1976.
     *
     * PHP translation of JS TemporalHelpers.ISO.plainYearMonthStringsValid().
     *
     * @return list<string>
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function isoPlainYearMonthStringsValid(): array
    {
        return [
            '1976-11',
            '1976-11-10',
            '1976-11-01T09:00:00+00:00',
            '1976-11-01T00:00:00+05:00',
            '197611',
            '+00197611',
            '1976-11-18T15:23:30.1-02:00',
            '1976-11-18T152330.1+00:00',
            '19761118T15:23:30.1+00:00',
            '1976-11-18T15:23:30.1+0000',
            '1976-11-18T152330.1+0000',
            '19761118T15:23:30.1+0000',
            '19761118T152330.1+00:00',
            '19761118T152330.1+0000',
            '+001976-11-18T152330.1+00:00',
            '+0019761118T15:23:30.1+00:00',
            '+001976-11-18T15:23:30.1+0000',
            '+001976-11-18T152330.1+0000',
            '+0019761118T15:23:30.1+0000',
            '+0019761118T152330.1+00:00',
            '+0019761118T152330.1+0000',
            '1976-11-18T15:23',
            '1976-11-18T15',
            '1976-11-18',
        ];
    }

    /**
     * Returns PlainYearMonth-like strings that are not valid.
     *
     * PHP translation of JS TemporalHelpers.ISO.plainYearMonthStringsInvalid().
     *
     * @return list<string>
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function isoPlainYearMonthStringsInvalid(): array
    {
        return [
            '2020-13',
            '1976-11[u-ca=gregory]',
            '1976-11[u-ca=hebrew]',
            '1976-11[U-CA=iso8601]',
            '1976-11[u-CA=iso8601]',
            '1976-11[FOO=bar]',
            '+999999-01',
            '-999999-01',
        ];
    }

    /**
     * Returns PlainYearMonth-like strings that are valid and parse to November of ISO year -9999.
     *
     * PHP translation of JS TemporalHelpers.ISO.plainYearMonthStringsValidNegativeYear().
     *
     * @return list<string>
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function isoPlainYearMonthStringsValidNegativeYear(): array
    {
        return [
            '-009999-11',
        ];
    }

    /**
     * Returns PlainMonthDay-like strings that are valid and parse to October 1st.
     *
     * PHP translation of JS TemporalHelpers.ISO.plainMonthDayStringsValid().
     *
     * @return list<string>
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function isoPlainMonthDayStringsValid(): array
    {
        return [
            '10-01',
            '1001',
            '1965-10-01',
            '1976-10-01T152330.1+00:00',
            '19761001T15:23:30.1+00:00',
            '1976-10-01T15:23:30.1+0000',
            '1976-10-01T152330.1+0000',
            '19761001T15:23:30.1+0000',
            '19761001T152330.1+00:00',
            '19761001T152330.1+0000',
            '+001976-10-01T152330.1+00:00',
            '+0019761001T15:23:30.1+00:00',
            '+001976-10-01T15:23:30.1+0000',
            '+001976-10-01T152330.1+0000',
            '+0019761001T15:23:30.1+0000',
            '+0019761001T152330.1+00:00',
            '+0019761001T152330.1+0000',
            '1976-10-01T15:23:00',
            '1976-10-01T15:23',
            '1976-10-01T15',
            '1976-10-01',
            '--10-01',
            '--1001',
            '-999999-10-01',
            '-999999-10-01[u-ca=iso8601]',
            '+999999-10-01',
            '+999999-10-01[u-ca=iso8601]',
        ];
    }

    /**
     * Returns PlainMonthDay-like strings that are not valid.
     *
     * PHP translation of JS TemporalHelpers.ISO.plainMonthDayStringsInvalid().
     *
     * @return list<string>
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function isoPlainMonthDayStringsInvalid(): array
    {
        return [
            '11-18junk',
            '11-18[u-ca=gregory]',
            '11-18[u-ca=hebrew]',
            '11-18[U-CA=iso8601]',
            '11-18[u-CA=iso8601]',
            '11-18[FOO=bar]',
            '-999999-01-01[u-ca=gregory]',
            '-999999-01-01[u-ca=chinese]',
            '+999999-01-01[u-ca=gregory]',
            '+999999-01-01[u-ca=chinese]',
        ];
    }

    /**
     * Returns PlainTime strings that are ambiguous with PlainMonthDay or PlainYearMonth format.
     * These require a "T" time-designator prefix when passed to PlainTime parsers.
     *
     * PHP translation of JS TemporalHelpers.ISO.plainTimeStringsAmbiguous().
     * The returned array is the union of the 9 base strings and their [u-ca=iso8601]-annotated
     * variants (18 strings total), matching the JS implementation exactly.
     *
     * @return list<string>
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function isoPlainTimeStringsAmbiguous(): array
    {
        $base = [
            '2021-12',
            '2021-12[-12:00]',
            '1214',
            '0229',
            '1130',
            '12-14',
            '12-14[-14:00]',
            '202112',
            '202112[UTC]',
        ];
        $withCalendar = array_map(static fn(string $s): string => sprintf('%s[u-ca=iso8601]', $s), $base);

        return [...$base, ...$withCalendar];
    }

    /**
     * Returns PlainTime strings that are unambiguous (valid time components that
     * cannot be mistaken for month or day values).
     *
     * PHP translation of JS TemporalHelpers.ISO.plainTimeStringsUnambiguous().
     *
     * @return list<string>
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function isoPlainTimeStringsUnambiguous(): array
    {
        return [
            '2021-13',
            '202113',
            '2021-13[-13:00]',
            '202113[-13:00]',
            '0000-00',
            '000000',
            '0000-00[UTC]',
            '000000[UTC]',
            '1314',
            '13-14',
            '1232',
            '0230',
            '0631',
            '0000',
            '00-00',
        ];
    }

    /**
     * Returns the 12 ISO calendar months with their month number, month code, and
     * maximum days-in-month (using a leap year for February = 29).
     *
     * PHP translation of JS TemporalHelpers.ISOMonths.
     *
     * @return list<array{month: int, monthCode: string, daysInMonth: int}>
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function isoMonths(): array
    {
        return [
            ['month' => 1, 'monthCode' => 'M01', 'daysInMonth' => 31],
            ['month' => 2, 'monthCode' => 'M02', 'daysInMonth' => 29],
            ['month' => 3, 'monthCode' => 'M03', 'daysInMonth' => 31],
            ['month' => 4, 'monthCode' => 'M04', 'daysInMonth' => 30],
            ['month' => 5, 'monthCode' => 'M05', 'daysInMonth' => 31],
            ['month' => 6, 'monthCode' => 'M06', 'daysInMonth' => 30],
            ['month' => 7, 'monthCode' => 'M07', 'daysInMonth' => 31],
            ['month' => 8, 'monthCode' => 'M08', 'daysInMonth' => 31],
            ['month' => 9, 'monthCode' => 'M09', 'daysInMonth' => 30],
            ['month' => 10, 'monthCode' => 'M10', 'daysInMonth' => 31],
            ['month' => 11, 'monthCode' => 'M11', 'daysInMonth' => 30],
            ['month' => 12, 'monthCode' => 'M12', 'daysInMonth' => 31],
        ];
    }

    /**
     * Returns calendar IDs that are not yet supported by any browser Temporal
     * implementation (defined in the Intl.Era-monthcode proposal).
     *
     * PHP translation of JS TemporalHelpers.NotYetSupportedCalendars.
     *
     * @return list<string>
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function notYetSupportedCalendars(): array
    {
        return [
            'bangla',
            'gujarati',
            'kannada',
            'marathi',
            'odia',
            'tamil',
            'telugu',
            'vikram',
        ];
    }
}
