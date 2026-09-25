# Spec deletion audit: parsing and validation

Tracking issue: https://github.com/MidnightDesign/calendrics/issues/173

Baseline: `7cc75831286c3e1d2031d46eaadd904bcb923a58`, `build/coverage/tc39-audit.xml`.
This ownership slice contains 132 uncovered executable lines. All line numbers below
refer to that baseline, before deletions. Coverage absence alone is not a deletion proof.

## Implemented deletions

| Candidate | Decision and proof |
| --- | --- |
| `Instant.php:106–108`, special return for an all-zero decimal string | Delete. After trimming zeros, the empty string already enters the `strlen($digits) <= 9` branch and casts to integer zero. Positive input returns `[0, 0]`; negative input follows the zero subsecond branch and returns `[0, 0]`. The removed return adds no behavior, including for signed zero. Baseline missed statement: 107. |
| `PlainTime.php:558–570`, explicit decimal precision table | Replace duplicate enumeration with `10 ** (9 - $digits)`. `Options::fractionalSecondDigits()` restricts precision to 0–9; the smallest-unit override selects only -1, 0, 3, 6, or 9, and the initial auto sentinel is -2. Preserve both sentinels separately. For every remaining value the expression is exactly the original table's integer value, from 1,000,000,000 to 1. Baseline missed statements removed: 562, 565, 566. |

These changes remove 11 net source lines and four originally uncovered executable
statements. They do not remove support for an input or change an exception contract.

## Complete uncovered-line disposition

“Retain” means the block has an identified responsibility or reachable input; it does
not claim that every current implementation detail is correct. “Unresolved” means this
audit does not establish a safe deletion proof. Grouped ranges include only the
explicitly listed missed statements.

| File | Baseline missed lines | Decision and evidence |
| --- | --- | --- |
| `Instant.php` | 107 | Delete: redundant zero result above. |
| `Instant.php` | 111, 112, 123 | Retain: short decimal strings and negative whole-second decimal strings need exact decomposition; public constructor accepts decimal strings. |
| `Instant.php` | 315, 396, 465 | Retain: regex accepts seconds 61–99 before the range check; integral float milliseconds require conversion; comparison arguments require type rejection. |
| `Instant.php` | 742, 743, 938 | Retain: compact `+0530` is a supported offset spelling; these paths parse or normalize it. Existing coverage issue #65 records these triggers. |
| `Instant.php` | 793, 794 | Unresolved: silent UTC fallback. The sole caller normalizes the zone before lookup, but normalization also accepts offset-shaped strings without numeric bounds checks. A proof restricted to valid IANA identifiers is insufficient. Existing #60/#65 discuss silent UTC behavior; do not assume their old caller graph still applies. |
| `PlainMonthDay.php` | 317, 326, 332, 337, 340, 341, 342, 343, 347, 348, 349, 350, 351, 352, 353, 356, 357, 359, 363, 364, 365 | Retain: non-ISO `with()` month/day/year preparation, conflict checks, calendar conversion, and reference-year resolution. Existing #150 explicitly tracks the missing `with(year)` fixture; #66 records concrete non-ISO triggers. |
| `PlainMonthDay.php` | 401, 548, 549, 554 | Retain: nonpositive month rejection and era/year resolution for `toPlainDate()`. Missing or unresolvable year must not silently produce a date. |
| `PlainMonthDay.php` | 613, 614, 615, 617, 618, 619, 620, 621, 622, 624, 625, 626, 627, 628, 629, 636, 638, 639, 640, 641, 642, 643, 644, 645, 646, 647, 648 | Unresolved deletion candidate: yearless month-day strings with time suffixes. See the grammar finding below; retain pending separate behavior-change verification. |
| `PlainMonthDay.php` | 659, 663, 664, 665, 711, 716, 721, 722, 723 | Retain: reject `12-00`, `02-30`, and out-of-range time components on full-date strings. Regex digit groups alone do not enforce these bounds. |
| `PlainMonthDay.php` | 927, 930, 967, 970 | Retain: non-ISO property-bag validation. In particular, the earlier `hasYearLike` check includes era pairs even on calendars whose era resolver returns null; that prevents a simple proof that the later missing-year/monthCode check is redundant. |
| `PlainMonthDay.php` | 1057, 1087, 1289 | Unresolved: failed reference-year search and calendar round-trip safeguards. No exhaustive supported-calendar invariant was established that would make these deletable. |
| `PlainTime.php` | 562, 565, 566 | Delete redundant precision enumeration as described above; preserve behavior algebraically. |
| `PlainTime.php` | 800, 845 | Retain: compact leap-second spelling and empty/annotation-only ambiguity input. Existing #65 gives compact `235960` and annotation-only input. |
| `PlainTime.php` | 1231 | Unresolved redundant validation: the private rounding helper's caller validates modes, but simply removing the default leaves a non-exhaustive string switch. A future consolidation can encode the invariant without adding an unchecked fallback. |
| `PlainYearMonth.php` | 490, 581, 586, 587, 588, 593, 594, 595, 633, 634, 635, 687, 715 | Retain: nonpositive day, string time/date range checks, and explicit null property-bag fields. For example `2024-02-30` and `2024-01-01T12:60` pass digit-only syntax and need semantic rejection. |
| `PlainYearMonth.php` | 997, 999, 1000, 1001, 1002, 1004 | Retain: increment-one branch is reachable when the rounding mode is not `trunc`; the caller's fast path checks both increment and mode, not increment alone. |
| `PlainYearMonth.php` | 1020, 1071, 1093 | Retain: next-boundary range validation and year-rounding sign selection when whole-year difference is zero. No valid input invariant eliminates them. |
| `Internal/DateFields.php` | 87, 117, 131 | Retain: explicit null year/month/day fields. `array_key_exists()` accepts present null, so the earlier presence checks do not subsume these checks. |
| `Internal/DateParse.php` | 102 | Retain: digit syntax admits seconds 61–99; leap-second 60 remains valid. |
| `Internal/DateTimeFields.php` | 104, 132, 146 | Retain: explicit null fields, for the same presence-versus-value distinction as DateFields. |
| `Internal/DateTimeParse.php` | 135 | Retain: rejects seconds above the leap-second-normalized range. |
| `Internal/Options.php` | 252, 471 | Retain: unsupported numeric option types and Stringable conversion, including a symbol sentinel that throws on conversion. Public internal helper signatures currently accept mixed values. |
| `Internal/RelativeTo.php` | 189, 244, 285, 286, 382, 385, 660, 661, 802 | Retain: plain-date and instant range boundaries, fixed-offset anchor conversion, subminute offset rejection, invalid timezone probing, and malformed offset rejection. These responsibilities are independent of corpus coverage. |
| `Internal/ZonedFields.php` | 213 | Retain: local date-time range validation before applying a timezone. An extreme property-bag year can reach it. |
| `Internal/ZonedParse.php` | 259, 260, 261, 354 | Retain: historical subminute offset mismatch and calendar-only annotation lacking a timezone. Existing #65 gives `1970-01-01T00:00:00-00:30[Africa/Monrovia]` and `2024-01-01T00:00[u-ca=iso8601]`. |

## Unsupported yearless-time grammar candidate

The [current TC39 grammar](https://tc39.es/proposal-temporal/#sec-temporal-iso8601grammar)
allows a month-day string through `AnnotatedMonthDay` or `AnnotatedDateTime`.
The first permits annotations after the month and day, but no time suffix. The second
requires a full date. Thus accepting `--12-25T12:30` appears to be extra behavior,
and removing the regex's time alternative would also remove its 27 uncovered
validation statements. The full-date time path must remain.

Existing issue #65 calls that input valid; this conflicts with the current grammar.
The local fixture search found no matching yearless-time rejection. This audit did
not complete the fresh-upstream search required by `CLAUDE.md` for an uncovered
behavior fix, so no missing-upstream-test claim or new issue was made, and this
behavior change is intentionally excluded from the current patch.

## Validation

- Whole-project PHPStan: passed after both edits.
- Whole-project Psalm: passed after both edits.
- Mago formatting check: both edited files passed.
- Commands used `docker compose run --rm php`; no host PHP or Node execution.
- Coordinator's combined TC39-only validation passed: all 11,375 case outcomes,
  assertion counts, and fixture hashes match the frozen baseline exactly.
- A programmatic ledger check matched all 132 missed statements in all 12 owned
  files, without duplicates or omitted lines.
- No fixtures, tests, suppressions, or GitHub state were changed by this investigator.
