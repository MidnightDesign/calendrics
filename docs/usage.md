# Usage guide

[Back to README](../README.md)

These examples use the PHP-oriented `Calendrics\` API. Each PHP block includes its imports; run it after loading `vendor/autoload.php` in your application.

## Dates, differences, and overflow

```php
use Calendrics\Duration;
use Calendrics\Overflow;
use Calendrics\PlainDate;
use Calendrics\Unit;

$start = PlainDate::parse('2026-01-31');
$end = $start->add(new Duration(months: 1));
echo $end; // 2026-02-28: the default constrains the day to the target month.

echo $start->until($end, largestUnit: Unit::Day); // P28D

// Reject a requested date that does not exist instead of constraining it.
try {
    $start->add(new Duration(months: 1), overflow: Overflow::Reject);
} catch (\Calendrics\Exception\RangeError $error) {
    // Ask for a valid target date.
}
```

`compare($one, $two)` returns -1, 0, or 1. `equals()` checks equality, including calendar or zone identity where the type requires it. Do not use PHP's `<` or `>` operators as a substitute.

## Resolve local times explicitly

```php
use Calendrics\Disambiguation;
use Calendrics\ZonedDateTime;

$meeting = ZonedDateTime::fromFields(
    timeZone: 'Europe/Vienna',
    year: 2026,
    month: 10,
    day: 25,
    hour: 2,
    minute: 30,
    disambiguation: Disambiguation::Later,
);

echo $meeting; // 2026-10-25T02:30:00+01:00[Europe/Vienna]
echo $meeting->toInstant(); // 2026-10-25T01:30:00Z
```

`Earlier` and `Later` choose a side of a repeated or skipped local time. `Reject` throws for either ambiguity. The default, `Compatible`, chooses the earlier instant during a repeated time and the later one during a skipped time.

`withTimeZone()` preserves the instant while changing its local representation. `toPlainDateTime()` removes the zone and instant association, retaining the local fields.

## Times and rounding

```php
use Calendrics\PlainTime;
use Calendrics\RoundingMode;
use Calendrics\Unit;

$time = PlainTime::parse('09:30:00.123456789');
echo $time->millisecond; // 123
echo $time->microsecond; // 456
echo $time->nanosecond;  // 789

echo $time->round(Unit::Second, roundingMode: RoundingMode::Ceil); // 09:30:01
```

`PlainTime` arithmetic wraps at midnight. Use `PlainDateTime` when the resulting date matters, or `ZonedDateTime` when elapsed time must account for a zone's clock changes.

## Duration totals need context

```php
use Calendrics\Duration;
use Calendrics\PlainDate;
use Calendrics\Unit;

$duration = new Duration(months: 1);
echo $duration->total(Unit::Day, relativeTo: PlainDate::parse('2026-02-01')); // 28
```

A month has no fixed length. Supply a `PlainDate` as `relativeTo` for calendar arithmetic, or a `ZonedDateTime` when zone transitions matter. A `Duration` stores its units; it does not automatically treat a month as a fixed number of seconds.

## Calendars

Constructors with ISO date parameters interpret those parameters as ISO fields. Use `fromFields()` for fields in another calendar, including leap-month codes and eras.

```php
use Calendrics\Calendar;
use Calendrics\PlainDate;

$date = PlainDate::fromFields(
    year: 5784,
    monthCode: 'M05L',
    day: 15,
    calendar: Calendar::Hebrew,
);

echo $date->year;      // 5784
echo $date->monthCode; // M05L

$iso = $date->withCalendar(Calendar::Iso8601);
```

`withCalendar()` changes the calendar used to describe the same date. Available identifiers are listed in the [`Calendar` enum](../src/Calendar.php). ICU supplies much of the calendar data, so results can depend on the installed ICU version.

## Formatting and JSON

```php
use Calendrics\FormatStyle;
use Calendrics\PlainDate;

$date = PlainDate::parse('2026-04-03');
$display = $date->toLocaleString('en-GB', dateStyle: FormatStyle::Long);
$json = json_encode($date, JSON_THROW_ON_ERROR); // "2026-04-03" as a JSON string
$restored = PlainDate::parse(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
```

Localized output is for display and varies with locale and ICU data. Default string and JSON serialization is for interchange, subject to the [compatibility policy](compatibility.md#public-api-policy).

Use typed formatting enums such as `FormatStyle`, `MonthWidth`, `NumberWidth`, and `HourCycle`. Do not combine a date/time style with individual component options. Types expose only relevant options: `PlainDate` has no `timeStyle`, for example. Invalid named arguments are PHP call errors, not compile-time validation.

`PlainYearMonth` and `PlainMonthDay` formatting requires a compatible calendar; ISO defaults are not interchangeable with the locale's Gregorian calendar. `Duration` has no localized formatter because PHP's `ext-intl` does not expose `Intl.DurationFormat`.

## PHP DateTime interoperability

```php
use Calendrics\Instant;

$native = new \DateTimeImmutable('2026-04-03T15:00:00.123456+00:00');
$instant = Instant::fromDateTime($native);
$copy = $instant->toDateTime(new \DateTimeZone('Europe/Vienna'));

echo $copy->format('Y-m-d H:i:s.u P'); // 2026-04-03 17:00:00.123456 +02:00
```

PHP date-time objects preserve microseconds, not nanoseconds. Converting a value with finer precision drops its final three fractional digits. The `Instant` and `ZonedDateTime` native conversions also depend on integer nanosecond epochs; see [timestamp range limits](compatibility.md#timestamp-range-limits).

## Current time

```php
use Calendrics\Now;

$timestamp = Now::instant();
$today = Now::plainDate('Europe/Vienna');
$local = Now::zonedDateTime('Europe/Vienna');
```

Pass the zone explicitly when your application's date depends on a particular location.

## The public Spec API

The `Calendrics\Spec\` namespace provides Temporal-shaped factories and option bags. It is a supported public layer under the same [compatibility policy](compatibility.md), with PHP-specific differences.

```php
use Calendrics\PlainDate;

$date = PlainDate::parse('2026-04-03');
$spec = $date->toSpec();
$changed = $spec->with(['day' => 4]);
$copy = PlainDate::fromSpec($changed);
echo $copy; // 2026-04-04
```

The PHP-oriented layer uses constructors, `parse()`, `fromFields()`, enums, and named arguments instead of the Spec layer's polymorphic `from()` and option bags. Internal namespaces are not supported application dependencies.

## API reference

Public method signatures and field documentation live with each class:

- [PlainDate](../src/PlainDate.php), [PlainTime](../src/PlainTime.php), [PlainDateTime](../src/PlainDateTime.php)
- [Instant](../src/Instant.php), [ZonedDateTime](../src/ZonedDateTime.php)
- [PlainYearMonth](../src/PlainYearMonth.php), [PlainMonthDay](../src/PlainMonthDay.php)
- [Duration](../src/Duration.php), [Now](../src/Now.php)