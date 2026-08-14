# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Until 1.0.0 the public API may change between minor versions.

## [Unreleased]

### Added

- **`fromDateTime` / `toDateTime` on porcelain value types.** Native `\DateTimeImmutable` interop for `Temporal\Instant` (both directions, optional `\DateTimeZone` arg, defaults to UTC), `Temporal\ZonedDateTime` (both directions, preserves the zone id), and `fromDateTime` on `Temporal\PlainDateTime`, `Temporal\PlainDate`, `Temporal\PlainTime`. Sub-microsecond Temporal bits are zero on conversion (PHP's `\DateTimeImmutable` is microsecond-precision). Unblocks "wrap your own clock" testing without a library-blessed clock-injection seam — see issue #19 for rationale.
- **`Temporal\Exception\` hierarchy.** Introduces a `TemporalException` marker interface and concrete classes (`InvalidArgument`, `RangeError`) that extend the corresponding SPL parents (`\InvalidArgumentException`, `\RangeException`). Porcelain throw sites in `Calendar::fromId()` and `RoundingMode::{to,from}PhpRoundingMode()` now throw `Temporal\Exception\InvalidArgument`; existing `catch (\InvalidArgumentException)` / `catch (\LogicException)` clauses keep working because the SPL parents are preserved. The marker interface lets consumers catch every Temporal-origin throwable through a single base. Spec-layer throw sites still emit bare SPL exceptions and will be retrofitted onto this hierarchy in subsequent minors.

### Fixed

- **`ZonedDateTime` raised `\TypeError` instead of `Temporal\Exception\RangeError` for epoch magnitudes past int64 seconds.** The `@internal` true-epoch-parts seams (`fromInstantParts()` / `createFromEpochParts()`) declared `int` parameters, so a seconds count too large for int64 — 2¹²⁸ nanoseconds, for instance — failed on the parameter type before the range check could run. `Instant::fromEpochParts()` already narrowed such values to a `RangeError`; both classes now share that narrowing via `EpochValue::narrowParts()`. Surfaced by test262's `ZonedDateTime/limits.js`, which the transpiler previously could not express.
- **`ZonedDateTime::getTimeZoneTransition('next')` could return a transition in the past.** When a zone had no transition left in the 200-year search window, the `next` branch fell through into the `previous` search instead of returning `null`, so `Asia/Riyadh` at 2024 reported its 1947 LMT-to-standard transition as its *next* one. Zones with DST were unaffected — the fall-through only happened once the forward scan found nothing.
- **`dayOfWeek` was wrong for every date at ISO year ≤ 0.** The ISO weekday used Sakamoto's algorithm with PHP's truncating `intdiv()` and `%`, both of which round toward zero rather than flooring, so negative years got a wrong leap-day count and a negative remainder — `dayOfWeek` could return values outside its documented 1–7 range. It is now derived from the Julian Day Number, which the codebase already computes with floor division. `weekOfYear` and `yearOfWeek` are fixed with it, as is `dayOfWeek` on `PlainDateTime`/`ZonedDateTime` and on the porcelain types that delegate to them. Surfaced by test262's `intl402/PlainDate/from/hebrew-keviah.js`, which walks Hebrew years 3700–5800 (ISO −61 onward) and now passes for all 2101 of them.
- **`toLocaleString()` ignored the value's calendar.** A Hebrew-calendar `PlainDate` formatted with an `en-US` formatter rendered as a Gregorian date, silently reinterpreting its fields. Per ECMA-402 it now throws `Temporal\Exception\RangeError` unless the value's calendar matches the formatter's resolved calendar. `PlainDate`, `PlainDateTime` and `ZonedDateTime` still accept the ISO 8601 calendar against any formatter; `PlainYearMonth` and `PlainMonthDay` do not, matching the spec — a bare year-month or month-day has no meaning outside its own calendar, so `(new Spec\PlainYearMonth(2000, 5))->toLocaleString()` now throws where it previously returned a Gregorian rendering.
- **`toLocaleString()` ignored a locale's default calendar.** Locales that select a non-Gregorian calendar without a `-u-ca-` keyword — `th-TH` → `buddhist` is the common case — formatted in the Gregorian calendar. `th-TH` now renders 2000-05-02 as `2/5/2543`, matching `Intl.DateTimeFormat`.
- Spec-layer `toLocaleString()` now throws `Temporal\Exception\TypeError` for inapplicable style options instead of a bare `\TypeError`. The SPL parent is unchanged, so existing `catch (\TypeError)` clauses keep working.

### Changed

- **Spec layer (`Temporal\Spec\`) reframed as a public API layer, not internal.** The 0.1.0 release notes described it as "considered internal"; that was a misframing. The layer is PSR-4 public and will be covered by the Backwards Compatibility Promise on the same terms as the porcelain layer starting at 1.0.0.
- **test262 transpiler carries BigInt constants that exceed int64.** A `const` bound to an out-of-int64 BigInt used to abort the whole script; its exact value is now tracked at transpile time and folded into each use — epoch constructions lower to the `(epochSec, subNs)` parts factories, template interpolations to the decimal digits, and a `for…of` over such a table is unrolled. Uses that cannot fold still bail, so nothing references an undefined PHP variable. This unlocks the 17 fixtures that pin down the ±8.64e21 ns epoch boundary, which had no conformance coverage at all: 329 scripts remain incomplete, down from 346.
- The Docker image now places `acorn` in `/node_modules` rather than the global `node_modules`. Node's ESM resolver ignores the global directory, so `composer test262:build` failed to start; `/node_modules` is the nearest directory the image can populate that the `/app` bind mount does not shadow.
- README now has a **Versioning and backwards compatibility** section that spells out the SemVer contract for each layer, the `toSpec()`/`fromSpec()` round-trip guarantee, and the `Temporal\Spec\Internal\` exclusion — resolving the "formal BC policy for the seam" note from 0.1.0.

## [0.1.0] - 2026-04-19

Initial public release.

### Added

- **Porcelain API** (`Temporal\` namespace) — PHP-native facade over the TC39 Temporal spec with strict types, readonly fields, backed enums, and named arguments:
  - `PlainDate`, `PlainTime`, `PlainDateTime`, `PlainYearMonth`, `PlainMonthDay`
  - `Instant`, `ZonedDateTime`
  - `Duration`
  - `Now` (factory for current-time values)
  - Enums: `Calendar`, `Disambiguation`, `OffsetOption`, `Overflow`, `RoundingMode`, `Unit`, plus display-side enums (`CalendarDisplay`, `OffsetDisplay`, `TimeZoneDisplay`, `TransitionDirection`).
- **Spec layer** (`Temporal\Spec\` namespace) — TC39-faithful implementation, exercised by the test262 conformance suite. Considered internal; prefer the porcelain API.
- **ECMA-402 calendar support** — 16 non-ISO calendars (Hebrew, Islamic family, Japanese, Buddhist, Chinese/Dangi, Coptic, Ethiopic family, Persian, ROC, Indian) via `IntlCalendarBridge`, with pure-PHP implementations for Hebrew and Indian.
- **Factory surface per porcelain class** — each class exposes a typed constructor (named arguments supported), a `parse(string)` for ISO 8601 strings, and for the five calendar-aware classes (`PlainDate`, `PlainDateTime`, `PlainYearMonth`, `PlainMonthDay`, `ZonedDateTime`) a `fromFields(...)` named-argument factory covering calendar-specific fields (`monthCode`, `era`, `eraYear`).
- **Interop** — `toSpec()` / `fromSpec()` on every porcelain class for bridging to the spec layer.
- **Test262 conformance** — 6615 passing test262 scripts, 0 failures (~494k assertions).

### Deliberate deviations from TC39

The porcelain layer adapts TC39 semantics to PHP-native conventions rather than mirroring the JavaScript API shape 1:1.

- **No polymorphic `from()` method.** TC39's `Temporal.X.from(string|object)` is not provided at the porcelain level. Use `parse()` for strings and `fromFields()` for calendar fields. The spec layer (`Temporal\Spec\*`) retains TC39-faithful `from()` for anyone needing exact spec semantics.
- **Named arguments, not property bags.** `PlainDate::fromFields(year: 2024, month: 3, day: 15, calendar: Calendar::Gregory)` rather than `PlainDate.from({year: 2024, ...})`.
- **Backed enums, not option strings.** `Overflow::Reject` rather than `{overflow: 'reject'}`.

### Known limitations

- `Duration::round()` and `Duration::total()` throw `NotYetImplementedException` when balancing across calendar units that require a reference point.
- The `Temporal\Spec\` namespace is public PSR-4 but documented as internal; a formal BC policy for the seam will land before 1.0.0.
- Mutation testing currently reports ~72% MSI; see [issue #2](https://github.com/MidnightDesign/temporal-php/issues/2).

[Unreleased]: https://github.com/MidnightDesign/temporal-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/MidnightDesign/temporal-php/releases/tag/v0.1.0
