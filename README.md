# temporal-php

A PHP 8.4 implementation of the [TC39 Temporal API](https://tc39.es/proposal-temporal/).

Temporal is the modern replacement for JavaScript's `Date`, providing a precise, unambiguous date/time API. This library brings those semantics to PHP with full nanosecond precision, strict types, backed enums, and named arguments.

## Requirements

- PHP 8.4+ (64-bit recommended)
- Composer
- `ext-intl` (required for non-ISO calendars, for `Temporal\DateTimeFormat`, and for spec-layer `toLocaleString()`)

> **32-bit platforms.** The library targets 64-bit PHP. It is not a hard requirement, but on 32-bit builds the native date primitives (`gmmktime()` and friends) only cover years ~1901–2038, so calculations near Temporal's extreme year range may misbehave. Running on 32-bit is not recommended.

## Installation

```bash
composer require midnight/temporal-php
```

## Architecture

This library has two API tiers:

| Layer | Namespace | Purpose |
|-------|-----------|---------|
| **Porcelain** | `Temporal\` | PHP-native API with strict types, backed enums, and named arguments |
| **Spec** | `Temporal\Spec\` | TC39-faithful implementation, validated by 6600+ test262 scripts |

Most application code should use the porcelain layer. The spec layer is a fully supported alternative when you need TC39-faithful semantics — for example, producing output that matches JavaScript Temporal byte-for-byte. Both layers are covered by the [Backwards Compatibility Promise](#versioning-and-backwards-compatibility).

### Deliberate deviations from TC39

The porcelain layer adapts TC39 semantics to PHP-native conventions rather than mirroring the JavaScript API shape 1:1. The spec layer (`Temporal\Spec\`) remains TC39-faithful for anyone needing that, with one small exception (see `valueOf()` below).

Notable differences:

- **No polymorphic `from()` method.** PHP has named arguments, backed enums, and tight types — three features that remove the need for a single factory that dispatches on input shape. Use `parse()` for ISO 8601 strings and `fromFields()` for calendar fields.
- **`fromFields()` takes named arguments, not a property bag.** Each parameter has its own type (`int<1, 12>` for `month`, `Calendar` for `calendar`, etc.) so PHPStan/Psalm can validate call sites fully. Only five classes expose `fromFields()` — the ones whose constructors cannot express every field combination (`PlainDate`, `PlainDateTime`, `PlainYearMonth`, `PlainMonthDay`, `ZonedDateTime`). For `PlainTime`, `Instant`, and `Duration`, the constructor already covers every field.
- **Option strings replaced by backed enums.** `Overflow::Reject` instead of `'reject'`, `Calendar::Gregory` instead of `'gregory'`, etc.
- **Time zones and calendars are first-class.** `ZonedDateTime::fromFields()` takes `timeZone` as a required positional parameter; all calendar fields accept the `Calendar` enum rather than an identifier string.
- **No `valueOf()` on spec-layer types.** The TC39 spec defines `valueOf()` to throw `TypeError` so that `<`, `>`, `+`, etc. fail loudly rather than silently coercing. PHP has no equivalent hook — relational operators on objects walk declared properties, arithmetic operators raise `TypeError` from the engine itself, and there is no language path that calls `valueOf()`. A throw-only method that the runtime never invokes is just dead surface, so the spec layer does not expose it. Use `compare()` (or, for `Instant` / `ZonedDateTime`, the underlying `epochNanoseconds`) when you need ordering. Test262 fixtures that target `valueOf()` are emitted as incomplete by the transpiler.
- **`Duration` field values are exact integers, not float64-narrowed.** TC39's spec performs all internal arithmetic in BigInt and then materializes Duration fields into JS `Number` (= float64), which loses precision past 2⁵³. PHP's `int` is 64-bit, so we keep the exact integer representation: a 584-year microsecond delta lands as `microseconds = 18_446_744_073_709_551, nanoseconds = 616` (reconstructible to the original nanosecond span exactly), where JS would store `microseconds = 18_446_744_073_709_552, nanoseconds = 616` — off by 1 µs because `18_446_744_073_709_551` rounds up to the next float64-representable integer. Practical impact: any Duration produced from sub-second arithmetic across a multi-century span is more accurate than its JS counterpart by up to 1 ULP at the largestUnit. Test262 fixtures that pin down the JS-narrowing behavior verbatim (`PlainDateTime/prototype/{since,until}/float64-representable-integer*`) are emitted as incomplete by the transpiler.

## Usage

### `PlainDate`

A calendar date without time or time zone.

```php
use Temporal\PlainDate;
use Temporal\Calendar;
use Temporal\Duration;
use Temporal\Overflow;
use Temporal\Unit;

$date = new PlainDate(2024, 3, 15);
$date = PlainDate::parse('2024-03-15');

// Named-argument factory for calendar-specific fields
// (useful when you need `monthCode`, `era`, or `eraYear`)
$hebrewDate = PlainDate::fromFields(
    year: 5784,
    monthCode: 'M05L',  // leap month, ISO's `month` can't express this
    day: 15,
    calendar: Calendar::Hebrew,
);

// Read-only fields
$date->year;         // 2024
$date->month;        // 3
$date->day;          // 15

// Calendar-derived properties
$date->calendar;     // Calendar::Iso8601
$date->monthCode;    // 'M03'
$date->era;          // null (non-null for Japanese, Buddhist, etc.)
$date->eraYear;      // null
$date->dayOfWeek;    // 1-7 (Monday-Sunday)
$date->dayOfYear;    // 1-366
$date->weekOfYear;   // 1-53
$date->yearOfWeek;   // ISO week-year
$date->daysInMonth;  // 28-31
$date->daysInYear;   // 365 or 366
$date->inLeapYear;   // bool

// Arithmetic
$later   = $date->add(new Duration(days: 30));
$earlier = $date->subtract(new Duration(months: 2));

// Override fields
$copy = $date->with(year: 2025);
$copy = $date->with(month: 2, overflow: Overflow::Reject);

// Comparison
PlainDate::compare($a, $b);  // -1, 0, or 1
$a->equals($b);               // bool

// Difference
$d = $a->until($b, largestUnit: Unit::Month);
$d = $a->since($b, largestUnit: Unit::Year);

// Conversions
$dt  = $date->toPlainDateTime();                        // midnight
$dt  = $date->toPlainDateTime(new PlainTime(9, 30));    // 09:30
$zdt = $date->toZonedDateTime('America/New_York');
$ym  = $date->toPlainYearMonth();
$md  = $date->toPlainMonthDay();

// Calendar projections
$hebrew = $date->withCalendar(Calendar::Hebrew);
$hebrew->year;       // 5784
$hebrew->monthCode;  // 'M06'

// Serialization
echo $date;                // '2024-03-15'
echo json_encode($date);  // '"2024-03-15"'
```

### `PlainTime`

A wall-clock time without date or time zone.

```php
use Temporal\PlainTime;
use Temporal\Duration;
use Temporal\Unit;
use Temporal\RoundingMode;

$time = new PlainTime(9, 30);
$time = PlainTime::parse('09:30:00.123456789');

// Read-only fields
$time->hour;         // 9
$time->minute;       // 30
$time->second;       // 0
$time->millisecond;  // 0
$time->microsecond;  // 0
$time->nanosecond;   // 0

// Arithmetic (wraps at midnight)
$later = $time->add(new Duration(hours: 2, minutes: 15));
$earlier = $time->subtract(new Duration(minutes: 30));

// Rounding
$rounded = $time->round(Unit::Minute);
$rounded = $time->round(Unit::Second, roundingMode: RoundingMode::Ceil);

// Difference
$d = $a->since($b, largestUnit: Unit::Hour);
$d = $a->until($b);

// Override fields
$copy = $time->with(hour: 10, minute: 0);

// Serialization
echo $time;  // '09:30:00'
```

### `PlainDateTime`

A date and time without time zone.

```php
use Temporal\PlainDateTime;
use Temporal\PlainTime;
use Temporal\Duration;
use Temporal\Disambiguation;

$dt = new PlainDateTime(2024, 3, 15, 9, 30);
$dt = PlainDateTime::parse('2024-03-15T09:30:00');

// All PlainDate + PlainTime properties available
$dt->year;        // 2024
$dt->hour;        // 9
$dt->dayOfWeek;   // 5 (Friday)

// Arithmetic
$later = $dt->add(new Duration(days: 30, hours: 2));

// Rounding
$rounded = $dt->round(Unit::Minute);

// Conversions
$date = $dt->toPlainDate();
$time = $dt->toPlainTime();
$dt2  = $dt->withPlainTime(new PlainTime(12, 0));
$zdt  = $dt->toZonedDateTime('Europe/Berlin',
    disambiguation: Disambiguation::Earlier,
);

// Serialization
echo $dt;  // '2024-03-15T09:30:00'
```

### `Instant`

A fixed point in time with nanosecond precision (~1677-2262).

```php
use Temporal\Instant;
use Temporal\Duration;
use Temporal\Unit;

$instant = Instant::parse('2020-01-01T12:00:00Z');
$instant = Instant::fromEpochMilliseconds(1_577_880_000_000);
$instant = Instant::fromEpochNanoseconds(1_577_880_000_000_000_000);

// Properties
$instant->epochNanoseconds;   // int
$instant->epochMilliseconds;  // int

// Arithmetic
$later = $instant->add(new Duration(hours: 1, minutes: 30));
$diff  = $a->since($b, largestUnit: Unit::Hour);

// Rounding
$rounded = $instant->round(Unit::Minute);

// Convert to ZonedDateTime
$zdt = $instant->toZonedDateTime('America/New_York');

// Serialization
echo $instant;  // '2020-01-01T12:00:00Z'
```

### `ZonedDateTime`

A date and time bound to a specific time zone.

```php
use Temporal\ZonedDateTime;
use Temporal\Duration;
use Temporal\Disambiguation;
use Temporal\OffsetOption;
use Temporal\TransitionDirection;

$zdt = new ZonedDateTime(epochNanoseconds: 0, timeZoneId: 'UTC');
$zdt = ZonedDateTime::parse(
    '2024-03-15T09:30:00+01:00[Europe/Berlin]',
    disambiguation: Disambiguation::Compatible,
    offset: OffsetOption::Reject,
);

// All date/time properties + timezone info
$zdt->year;              // 2024
$zdt->hour;              // 9
$zdt->timeZoneId;        // 'Europe/Berlin'
$zdt->offset;            // '+01:00'
$zdt->offsetNanoseconds; // 3600000000000
$zdt->epochNanoseconds;
$zdt->epochMilliseconds;
$zdt->hoursInDay;        // usually 24, varies at DST transitions

// Arithmetic (DST-aware)
$later = $zdt->add(new Duration(hours: 1));

// Override fields
$copy = $zdt->with(hour: 12, disambiguation: Disambiguation::Earlier);

// DST transitions
$next = $zdt->getTimeZoneTransition(TransitionDirection::Next);
$prev = $zdt->getTimeZoneTransition(TransitionDirection::Previous);

// Conversions
$instant = $zdt->toInstant();
$date    = $zdt->toPlainDate();
$time    = $zdt->toPlainTime();
$dt      = $zdt->toPlainDateTime();
$moved   = $zdt->withTimeZone('Asia/Tokyo');

// Serialization
echo $zdt;  // '2024-03-15T09:30:00+01:00[Europe/Berlin]'
```

### `Duration`

An ISO 8601 duration with 10 fields, all strict `int`.

```php
use Temporal\Duration;
use Temporal\Unit;
use Temporal\RoundingMode;

$d = new Duration(years: 1, months: 6, days: 15);
$d = Duration::parse('P1Y6M15DT2H30M');

// Properties
$d->years;   // int (not int|float like the spec layer)
$d->sign;    // -1, 0, or 1
$d->blank;   // true if all fields are zero

// Arithmetic
$sum  = $d->add($other);
$diff = $d->subtract($other);

// Mutation
$neg  = $d->negated();
$abs  = $d->abs();
$copy = $d->with(years: 2);

// Rounding
$rounded = $d->round(smallestUnit: Unit::Minute);
$rounded = $d->round(
    largestUnit: Unit::Hour,
    smallestUnit: Unit::Second,
    roundingMode: RoundingMode::HalfExpand,
);

// Total in a unit
$hours = $d->total(Unit::Hour);        // int|float
$days  = $d->total(Unit::Day,
    relativeTo: new PlainDate(2024, 1, 1),
);

// Comparison
Duration::compare($a, $b);
$a->equals($b);

// Serialization
echo $d;  // 'P1Y6M15DT2H30M'
```

### `PlainYearMonth`

A year and month without a day.

```php
use Temporal\PlainYearMonth;

$ym = new PlainYearMonth(2024, 3);
$ym = PlainYearMonth::parse('2024-03');

$ym->year;         // 2024
$ym->month;        // 3
$ym->daysInMonth;  // 31
$ym->inLeapYear;   // true

$date = $ym->toPlainDate(day: 15);
```

### `PlainMonthDay`

A month and day without a year (e.g., a birthday or anniversary).

```php
use Temporal\PlainMonthDay;

$md = new PlainMonthDay(12, 25);
$md = PlainMonthDay::parse('--12-25');

$date = $md->toPlainDate(year: 2024);
```

### `Now`

Current date and time. Static-only, not instantiable.

```php
use Temporal\Now;

$instant = Now::instant();
$tzId    = Now::timeZoneId();             // e.g. 'Europe/Amsterdam'
$date    = Now::plainDate();              // system timezone
$date    = Now::plainDate('Asia/Tokyo');  // explicit timezone
$time    = Now::plainTime();
$dt      = Now::plainDateTime();
$zdt     = Now::zonedDateTime();
```

### Calendars

Full ECMA-402 multi-calendar support. The `Calendar` enum covers all 16 calendars defined by the spec:

```php
use Temporal\PlainDate;
use Temporal\Calendar;

// Project any date into a non-ISO calendar
$date   = PlainDate::parse('2024-03-15');
$hebrew = $date->withCalendar(Calendar::Hebrew);
$hebrew->year;      // 5784
$hebrew->monthCode; // 'M06'
$hebrew->era;       // 'am'

// Japanese calendar with era
$jp = $date->withCalendar(Calendar::Japanese);
$jp->era;           // 'reiwa'
$jp->eraYear;       // 6

// Construct with a calendar (constructor takes ISO year/month/day)
$buddhist = new PlainDate(2024, 3, 15, Calendar::Buddhist);
$buddhist->era;     // 'be'
$buddhist->eraYear; // 2567

// withCalendar() is available on PlainDate, PlainDateTime, and ZonedDateTime
// Calendar is also accepted by Now::plainDate(), Now::plainDateTime(), Now::zonedDateTime()
```

Available calendars: `Iso8601`, `Buddhist`, `Chinese`, `Coptic`, `Dangi`, `EthiopicAmeteAlem`, `Ethiopic`, `Gregory`, `Hebrew`, `Indian`, `IslamicCivil`, `IslamicTabular`, `IslamicUmalqura`, `Japanese`, `Persian`, `Roc`.

### Locale-aware formatting

`DateTimeFormat` renders any porcelain value in a locale. It is the porcelain counterpart to the spec layer's `toLocaleString()`: same ECMA-402 behavior, but the option bag of magic strings is replaced by backed enums, so call sites are checked statically.

```php
use Temporal\DateTimeFormat;
use Temporal\DateStyle;
use Temporal\TimeStyle;
use Temporal\PlainDate;

$long = DateTimeFormat::styled('de-AT', date: DateStyle::Full);

$long->format(PlainDate::parse('2024-03-15'));  // a full German date
$long->format(Now::zonedDateTime());            // same formatter, any value

// A time preset makes it a date-and-time formatter — no longer applicable to PlainDate
$stamp = DateTimeFormat::styled('de-AT', date: DateStyle::Medium, time: TimeStyle::Short);
$stamp->format(Now::zonedDateTime());
```

Instances are immutable and hold no ICU state, so build one at config time and reuse it.

ECMA-402 forbids combining the `dateStyle`/`timeStyle` presets with individual component options. Rather than accepting both and throwing, the two live on separate constructors — the invalid combination cannot be written:

```php
use Temporal\MonthWidth;
use Temporal\NumericWidth;
use Temporal\TextWidth;
use Temporal\TimeZoneNameStyle;

DateTimeFormat::components(
    'en-US',
    weekday: TextWidth::Long,
    month: MonthWidth::Short,
    day: NumericWidth::Numeric,
    hour: NumericWidth::TwoDigit,
    minute: NumericWidth::TwoDigit,
    timeZoneName: TimeZoneNameStyle::LongOffset,
)->format(Now::zonedDateTime());
```

Which options apply depends on the value, mirroring TC39:

- `PlainDate`, `PlainYearMonth` and `PlainMonthDay` reject a time preset; `PlainTime` rejects a date preset. Both throw `Temporal\Exception\TypeError`.
- `ZonedDateTime` carries its own zone, so passing `timeZone` throws `Temporal\Exception\TypeError`. Use `timeZone` to project an `Instant` into a civil zone; on the `Plain*` types it is ignored, because they denote no instant to project.
- A value in a non-ISO calendar can only be rendered by a formatter resolving to that same calendar — otherwise its fields would be silently reinterpreted. The mismatch throws `Temporal\Exception\RangeError`; pass `calendar:` to make the formatter's calendar explicit.

`Duration` is deliberately not formattable here: locale-aware duration rendering is `Intl.DurationFormat` territory, which `ext-intl` does not expose.

### Enums

All option strings are replaced by backed enums:

| Enum | Cases |
|------|-------|
| `Calendar` | `Iso8601`, `Buddhist`, `Chinese`, `Coptic`, `Dangi`, `Ethiopic`, `Gregory`, `Hebrew`, `Indian`, `Japanese`, `Persian`, `Roc`, ... (16 total) |
| `RoundingMode` | `Ceil`, `Floor`, `Expand`, `Trunc`, `HalfCeil`, `HalfFloor`, `HalfExpand`, `HalfTrunc`, `HalfEven` |
| `Overflow` | `Constrain`, `Reject` |
| `Unit` | `Year`, `Month`, `Week`, `Day`, `Hour`, `Minute`, `Second`, `Millisecond`, `Microsecond`, `Nanosecond` |
| `Disambiguation` | `Compatible`, `Earlier`, `Later`, `Reject` |
| `OffsetOption` | `Use`, `Prefer`, `Ignore`, `Reject` |
| `CalendarDisplay` | `Auto`, `Always`, `Never`, `Critical` |
| `TimeZoneDisplay` | `Auto`, `Never`, `Critical` |
| `OffsetDisplay` | `Auto`, `Never` |
| `TransitionDirection` | `Next`, `Previous` |
| `DateStyle` / `TimeStyle` | `Full`, `Long`, `Medium`, `Short` |
| `TextWidth` | `Narrow`, `Short`, `Long` |
| `NumericWidth` | `Numeric`, `TwoDigit` |
| `MonthWidth` | `Numeric`, `TwoDigit`, `Narrow`, `Short`, `Long` |
| `TimeZoneNameStyle` | `Short`, `Long`, `ShortOffset`, `LongOffset`, `ShortGeneric`, `LongGeneric` |
| `HourCycle` | `H11`, `H12`, `H23`, `H24` |

### Spec-layer interop

Every porcelain class has `toSpec()` and `fromSpec()` for dropping to the TC39-faithful layer when needed:

```php
$specDate = $date->toSpec();            // Temporal\Spec\PlainDate
$date     = PlainDate::fromSpec($spec); // back to porcelain
```

---

## Versioning and backwards compatibility

This project follows [Semantic Versioning](https://semver.org). Until 1.0.0 the public API may change between minor versions.

From 1.0.0 onward, both API layers are supported under the same contract:

- **Porcelain (`Temporal\`)** — public methods, property names and types, enum cases, and constructor parameters are stable within a major version.
- **Spec (`Temporal\Spec\`)** — same contract as porcelain. This layer tracks the TC39 Temporal specification; if an upstream Stage 4 change alters observable semantics, that change ships only in a major version of this library.
- **Seam** — for every porcelain class, `X::fromSpec($x->toSpec())` equals `$x` within a major version. You can move values between layers without lossy conversion.
- **Exceptions (`Temporal\Exception\`)** — every porcelain throw is a `Temporal\Exception\TemporalException` (marker interface) and also extends a stable SPL parent (e.g. `Temporal\Exception\InvalidArgument extends \InvalidArgumentException`). The marker interface and the SPL parent of each concrete exception class are stable within a major version, so both `catch (TemporalException)` and `catch (\InvalidArgumentException)` keep working. The spec layer throws through the same hierarchy; the only remaining bare SPL throws are internal invariant guards in `Temporal\Spec\Internal\`, which are not reachable through the public API.
- **Internal (`Temporal\Spec\Internal\`)** — genuine implementation detail (calendar bridges, serde, arithmetic helpers). May change at any time without a major version bump. Do not import from it.

Bug fixes that correct incorrect output are not breaking changes, even when an observed value changes. Deprecations are announced in the changelog at least one minor version before removal and marked with `@deprecated`.

---

## Development

This project runs in Docker. Start the environment:

```bash
docker compose up -d
```

Then run commands via the `php` service:

```bash
docker compose exec php vendor/bin/phpunit --testsuite porcelain
docker compose exec php composer phpstan
docker compose exec php composer psalm
docker compose exec php composer mago
```

Or run the full check suite (static analysis + tests + mutation testing):

```bash
docker compose exec php composer check
```

### Individual scripts

| Script | Command |
|--------|---------|
| All tests | `composer test` |
| Porcelain tests | `phpunit --testsuite porcelain` |
| test262 conformance | `composer test262:run` |
| Transpile test262 | `composer test262:build` |
| Tests + coverage | `composer test-coverage` |
| PHPStan (level 9) | `composer phpstan` |
| Psalm (level 1) | `composer psalm` |
| Mago lint | `composer mago` |
| Mutation testing | `composer infection` |

### test262 conformance

TC39 maintains [test262](https://github.com/tc39/test262), the official JavaScript conformance test suite. This project includes a transpiler (`tools/transpile-test262.mjs`) that converts Temporal test262 JS files to PHP, enabling direct conformance testing against the spec layer.

```bash
docker compose exec php composer test262:build
docker compose exec php composer test262:run
```

Currently **10,952 test262 scripts passing** (0 failures, 418 incomplete — mostly JS-only features like Symbol, Proxy, and property descriptor access, plus a handful of Chinese-calendar fixtures that need ICU ≥ 76).

---

## Transparency

This codebase is written with [Claude Code](https://claude.ai/claude-code). All production code and tests are AI-generated.

Quality is enforced by PHPStan (level 9), Psalm (error level 1), Mago, PHPUnit, and Infection with a 100% mutation kill threshold. None of that is negotiable -- every change must pass the full suite before it counts.

## License

MIT
