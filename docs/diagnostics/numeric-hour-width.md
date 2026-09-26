# Numeric hours with combined formatting options

## Diagnosis

PHP 8.4.25 and the native diagnostic both used ICU 76.1. Comparing these two
paths removes the ICU-version difference from issue #188.

Two interactions caused the reported results:

1. PHP's `IntlDatePatternGenerator::getBestPattern()` calls ICU's `getSkeleton()`
   before matching. Cleaning `jmB` removes `B`, because the unresolved `j` is not
   yet a 12-hour field. Resolving `j` to `h` first preserves the flexible day period.
   The old `af-NA` width override concealed this difference and incorrectly padded
   the hour when a time-zone name was also requested.
2. ICU loads standard time records using the locale's base name. An alternate
   12/24-hour cycle selects available-format records instead. ICU's
   `UDATPG_MATCH_HOUR_FIELD_LENGTH` preserves the pattern width when the selected
   record's hour skeleton already has the requested width. It does not simply
   force every requested numeric hour to one digit.

Width-specific records still matter: Korean has an `HHmmss` skeleton whose
pattern is `HH:mm:ss`. A numeric hour with two-digit minutes and seconds selects
that record and the matching option changes the hour to one digit. The formatter
checks for that record shape without adding a locale exception.

## Same-version examples

These are native pattern-generation results, not expectations inferred from
another ICU release. `matched` uses `UDATPG_MATCH_HOUR_FIELD_LENGTH`.

| Locale | Skeleton | Default ICU pattern | Matched pattern |
| --- | --- | --- | --- |
| af-NA-u-hc-h23 | jmmsz | HH:mm:ss z | HH:mm:ss z |
| af-NA | jmB | hh:mm B | hh:mm B |
| af-NA | jmBz | h:mm B z | h:mm B z |
| en-GB | jmmsz | HH:mm:ss z | H:mm:ss z |
| ko-u-hc-h23 | jmmss | HH:mm:ss | H:mm:ss |

The table omits narrow no-break spaces around day-period fields for readability.

The public call from #188 now returns `09:04:05 UTC`. Its day-period/time-zone
variant returns a one-digit hour: `9:04 die oggend UTC`. Without the zone, the
same locale's day-period record still correctly produces `09:04 die oggend`.
Explicit `hourCycle`, the locale `hc` extension, `hour12: false`, and explicit
Gregorian and Hebrew calendar options were also checked for the first example.

## Diagnostic scope

A 36,540-input grid covered 29 locales, the default cycle and all four explicit
cycles, absent/numeric/two-digit minutes and seconds, all day-period widths, and
all time-zone-name options. Against native ICU 76.1 hour tokens, the change
removed 860 disagreements and introduced none.

A second grid covered all 869 locales exposed by PHP's ICU, five cycle settings,
numeric/two-digit minutes, absent/numeric/two-digit seconds, absent/short day
periods, and absent/short time-zone names: 104,280 inputs. It removed 5,041
hour-token disagreements and introduced none.

These are bounded diagnostics, not counts of confirmed defects or proof of full
ICU parity. The formatter still approximates a matching option PHP does not
expose, and retains existing CLDR-version corrections outside these interactions.
Exact resource-key lookup is not a replacement for ICU's full best-fit matcher.

## Upstream coverage

Test262 was refreshed and regenerated without changing the tracked fixtures.
A fresh upstream checkout at `7ab7fafa0003f73fc85c1b95d88094d33f7eb8bd` was searched
under `test/intl402/DateTimeFormat` and `test/intl402/Temporal`.

`prototype/resolvedOptions/hourCycle.js` checks cycle selection, not these
combined-option widths. The Temporal `lone-options-accepted.js` fixtures compare
against the legacy formatter and cannot detect a shared formatter defect.
The English `prototype/formatToParts/dayPeriod-*-en.js` fixtures cover flexible
day-period text but do not cover these locale/width/zone interactions.

The pre-change full suite passed with 12,362 tests and 370 existing incomplete
cases. Missing upstream coverage remains tracked by
[issue #172](https://github.com/MidnightDesign/calendrics/issues/172).
No handwritten spec tests or fixture edits are included.

## Sources

- [PHP 8.4 pattern-generator binding](https://github.com/php/php-src/blob/PHP-8.4/ext/intl/dateformat/datepatterngenerator_methods.cpp): `getSkeleton()` before `getBestPattern()`.
- [ICU 76.1 pattern generator](https://github.com/unicode-org/icu/blob/release-76-1/icu4c/source/i18n/dtptngen.cpp): `addICUPatterns()`, `mapSkeletonMetacharacters()`, and `adjustFieldTypes()`.
- [ECMA-402 BestFitFormatMatcher](https://tc39.es/ecma402/#sec-bestfitformatmatcher): locale format matching is implementation-defined.
- [ECMA-402 FormatDateTimePattern](https://tc39.es/ecma402/#sec-formatdatetimepattern): rendering follows the resolved format record's widths.
