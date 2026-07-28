# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Until 1.0.0 the public API may change between minor versions.

## [Unreleased]

### Added

- **Spec-layer unit test suites for `Temporal\Spec\Now`, `Temporal\Spec\Instant`, and `Temporal\Spec\PlainTime`** (`tests/Spec/`). First tranche of the mutation-coverage widening plan from [issue #14](https://github.com/MidnightDesign/temporal-php/issues/14): the Infection CI filter now includes these three spec files alongside the ten porcelain files, each at 100% MSI. The suites pin behavior test262 does not reach — boundary values, exception messages, rounding-mode edge cases (as-if-positive semantics, halfEven/halfFloor/halfCeil ties, mode negation for negative diffs), over-int64 sentinel round-trips, and time-zone-string resolution corners.

### Fixed

- **`ZonedDateTime::getTimeZoneTransition('next')` no longer reports a historical transition on newer tzdata.** With recent tzdata/PHP builds, `DateTimeZone::getTransitions($begin, $end)` can return a zone's full history when its only offset change predates the requested range (e.g. Asia/Riyadh's 1947 LMT switch); the "next" scan then fell through into the "previous" scan and surfaced that 1947 transition as if it were upcoming. The "next" scan now requires a candidate strictly after the anchor instant and returns null instead of falling through — both no-ops on runtimes where `getTransitions()` honors its range. This was breaking CI on `master` (test262 `getTimeZoneTransition/no-transitions-named-timezone`).
- **Static-analysis drift on current Mago.** Mago ≥1.45 (CI installs the latest, the repo has no lock file) flags five pre-existing issues; fixed by typing `ZonedDateTime::ICU_FIXUPS`, clamping the `str_repeat()` count in `IntlFormatter`, and mirroring the existing Psalm suppressions on the three `isoDay` assignments with `@mago-expect`. Locally, `vendor/carthage-software/mago` may be installed from source; its bundled PHP parser fixtures then break Mago's own codebase scan — not reproducible with the dist install CI uses.
- **`Temporal\Spec\Now` no longer leaks `DateInvalidTimeZoneException`.** All five `Now` factories document `RangeError` for invalid time zone identifiers, but an unknown IANA name (e.g. `Not/AZone`, or an unknown `[Tz]` bracket annotation in a datetime string) escaped as PHP's raw `DateInvalidTimeZoneException` from the internal `DateTimeZone` construction. Identifiers are now validated where the time zone argument is resolved, so every invalid identifier surfaces as the documented `Temporal\Exception\RangeError`. Surfaced by the mutation-testing pass over `src/Spec/Now.php`; no test262 fixture exercises an invalid IANA name against `Temporal.Now` (a corpus gap in the sense of issue #26).
- **Infection now has an explicit 90s per-mutant `timeout`** (`infection.json5`). Mutants on hot spec-layer lines are covered by large test262 subsets whose nominal runtime exceeds Infection's 10s default; Infection silently skips such mutants, reporting 0% MSI for the affected files. Without this, the spec-layer filter expansion above would have gated CI on an empty result.

- **`fromDateTime` / `toDateTime` on porcelain value types.** Native `\DateTimeImmutable` interop for `Temporal\Instant` (both directions, optional `\DateTimeZone` arg, defaults to UTC), `Temporal\ZonedDateTime` (both directions, preserves the zone id), and `fromDateTime` on `Temporal\PlainDateTime`, `Temporal\PlainDate`, `Temporal\PlainTime`. Sub-microsecond Temporal bits are zero on conversion (PHP's `\DateTimeImmutable` is microsecond-precision). Unblocks "wrap your own clock" testing without a library-blessed clock-injection seam — see issue #19 for rationale.
- **`Temporal\Exception\` hierarchy.** Introduces a `TemporalException` marker interface and concrete classes (`InvalidArgument`, `RangeError`) that extend the corresponding SPL parents (`\InvalidArgumentException`, `\RangeException`). Porcelain throw sites in `Calendar::fromId()` and `RoundingMode::{to,from}PhpRoundingMode()` now throw `Temporal\Exception\InvalidArgument`; existing `catch (\InvalidArgumentException)` / `catch (\LogicException)` clauses keep working because the SPL parents are preserved. The marker interface lets consumers catch every Temporal-origin throwable through a single base. Spec-layer throw sites still emit bare SPL exceptions and will be retrofitted onto this hierarchy in subsequent minors.

### Changed

- **Spec layer (`Temporal\Spec\`) reframed as a public API layer, not internal.** The 0.1.0 release notes described it as "considered internal"; that was a misframing. The layer is PSR-4 public and will be covered by the Backwards Compatibility Promise on the same terms as the porcelain layer starting at 1.0.0.
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
