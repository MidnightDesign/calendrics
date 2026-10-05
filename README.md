# Calendrics

Dates, times, durations, and time zones for PHP, based on the [Temporal API](https://tc39.es/proposal-temporal/).

Calendrics separates a calendar date from a timestamp and makes time zones explicit. Its immutable values, typed options, and named arguments let application code express whether it means “tomorrow at the same local time” or “24 hours later.”

[Quickstart](#quickstart) · [Choose a type](#choose-a-type) · [Usage guide](docs/usage.md) · [Compatibility](docs/compatibility.md) · [Contributing](CONTRIBUTING.md)

## Install

You need **PHP 8.4 or newer**, Composer, and the **`intl` extension**. The project tests on 64-bit PHP 8.4 and 8.5; 32-bit PHP is not covered by CI.

```sh
composer require midnight/calendrics
```

The package is currently pre-1.0. Public APIs may change between minor versions; see the [compatibility policy](docs/compatibility.md#public-api-policy) before upgrading.

## Quickstart

Save this as `example.php` in a project with the package installed, then run `php example.php`:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Calendrics\Duration;
use Calendrics\PlainDate;
use Calendrics\PlainTime;

$invoiceDate = PlainDate::parse('2026-03-20');
$dueDate = $invoiceDate->add(new Duration(days: 14));

// A calendar date needs a time and zone to identify an instant.
$deadline = $dueDate->toZonedDateTime('Europe/Vienna', new PlainTime(17));

echo $invoiceDate, PHP_EOL;           // 2026-03-20 (unchanged)
echo $dueDate, PHP_EOL;               // 2026-04-03
echo $deadline, PHP_EOL;              // 2026-04-03T17:00:00+02:00[Europe/Vienna]
echo $deadline->toInstant(), PHP_EOL; // 2026-04-03T15:00:00Z
```

## Choose a type

| You have… | Use | Example |
|---|---|---|
| A date without a time zone | `PlainDate` | A birthday or invoice date |
| A point on the global timeline | `Instant` | An event timestamp |
| A date and time in a named zone | `ZonedDateTime` | A meeting in `Europe/Vienna` |
| A local date and time, with no zone yet | `PlainDateTime` | A form's local date/time input |
| A time of day | `PlainTime` | A shop's opening time |
| A year and month | `PlainYearMonth` | A billing period |
| A recurring month and day | `PlainMonthDay` | An anniversary |
| An amount of calendar or elapsed time | `Duration` | Two months, or 90 minutes |

`Now` supplies the current instant and current local values. A plain value never acquires a time zone implicitly: choose one when converting it to a zoned value.

## Calendar days and elapsed hours

A calendar day is not always 24 hours. Across a daylight-saving transition, choose the operation that matches your intent:

```php
use Calendrics\Duration;
use Calendrics\ZonedDateTime;

$start = ZonedDateTime::parse('2026-03-28T12:00:00+01:00[Europe/Vienna]');

echo $start->add(new Duration(days: 1));  // 2026-03-29T12:00:00+02:00[Europe/Vienna]
echo $start->add(new Duration(hours: 24)); // 2026-03-29T13:00:00+02:00[Europe/Vienna]
```

Local times can also be skipped or repeated when clocks change. Pass `Disambiguation::Reject` when constructing a zoned value if your application should ask the user to resolve that ambiguity. The [usage guide](docs/usage.md#resolve-local-times-explicitly) shows the options.

## Working with values

Use `parse()` for strings, constructors for explicit values, and `fromFields()` when you need calendar-specific fields. Operations such as `add()`, `with()`, and `round()` return new values.

Options use enums such as `Overflow::Reject`, `Unit::Month`, and `RoundingMode::HalfEven`. Use `compare()` or `equals()` to compare values; PHP's native object comparison operators do not implement Temporal ordering.

[The usage guide](docs/usage.md) covers differences and rounding, calendars, localized formatting, JSON, PHP `DateTimeInterface` conversion, and the public Spec API.

## Precision and portability

- Times can represent nine fractional digits. PHP `DateTimeInterface` has microsecond precision; epoch conversions truncate toward zero to microsecond precision.
- `Instant` and `ZonedDateTime` preserve timestamps beyond the 64-bit nanosecond epoch range when parsing, converting from Spec values, and converting to native PHP date-time objects. Integer nanosecond properties and native date-time input still have range limits; see [timestamp range limits](docs/compatibility.md#timestamp-range-limits).
- Duration results can follow JavaScript's floating-point Number semantics. Instant differences retain exact integer fields in some cases where JavaScript rounds them. See [PHP and Temporal differences](docs/compatibility.md#php-and-temporal-differences).
- Localized strings and non-ISO calendar behavior depend on ICU data from `ext-intl`. Use the ISO-style `toString()`/`parse()` pair for storage and interchange; do not parse `toLocaleString()` output.

Calendrics has two public layers: the PHP-oriented `Calendrics\` API shown here and the Temporal-shaped `Calendrics\Spec\` API. Most applications should start with the first. Neither layer's internal implementation namespace is a public API.

## Project status and quality

The project runs PHPUnit, PHPStan, Psalm, and Mago. Its mutation gate covers ten top-level PHP-oriented classes. Temporal conformance is checked with a translated subset of upstream test262; unsupported JavaScript or harness cases are reported as incomplete. [Testing scope and development commands](CONTRIBUTING.md) explain the checks.

See the [changelog](CHANGELOG.md) for released changes and [GitHub issues](https://github.com/MidnightDesign/calendrics/issues) for defects and planned work.

## License

[MIT](LICENSE)
