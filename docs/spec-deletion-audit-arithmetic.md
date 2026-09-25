# Arithmetic deletion audit

Baseline: `build/coverage/tc39-audit.xml`; line references below refer to the source before this audit's edits. Only the TC39 runner supplies coverage. The coordinator records the commit, environment, and exact test totals.

This audit checks necessity, not just coverage. [Issue #54](https://github.com/MidnightDesign/calendrics/issues/54) already classified earlier versions of these gaps and explicitly retained several invariant guards. Its earlier reachability findings are supporting evidence, not a claim that new reproductions were executed here.

## Implemented deletion

| Candidate | Verdict | Evidence |
|---|---|---|
| `Internal/CalendarMath.php:146–159`, duplicated Stringable numeric parsing | Delete duplicate implementation; retain behavior | `toFiniteInt()` previously converted a Stringable once, then repeated exactly the string branch's numeric check, float conversion, finite check, and integer conversion. Move the single string conversion before the existing string branch. Int, float, and bool still return earlier. Stringable exceptions still propagate. Non-Stringable objects still reach the same RangeError. No caller or accepted input changes. |

This reduces source duplication rather than deleting a supported arithmetic operation. The uncovered numeric-string paths remain reachable through the shared implementation.

## Retained blocks

| File and uncovered baseline lines | Verdict and reason |
|---|---|
| `Duration.php:510` | Retain: `with()` rejects other Temporal objects before field-bag conversion. Removing this changes input acceptance. |
| `Duration.php:951–962` | Retain: property-bag Stringable and numeric-string coercion and invalid-type rejection. These differ from integer constructor arguments. |
| `Duration.php:1024–1026` | Retain: low-magnitude float division is not disproved by constructor normalization. `add()` can combine a large float field with an oppositely signed integer/float field, leaving a float within integer range. The private divider must handle that cancellation. |
| `Duration.php:1227–1276` | Retain: `toString()` accepts halfFloor, halfCeil, halfTrunc, and halfEven; rounding a fractional second can reach these branches. Removing `halfEvenRound()` would remove an accepted rounding mode. |
| `ZonedDateTime.php:452` | Retain: constructor float rejection implements a distinct boundary from accepted integer epoch values. |
| `ZonedDateTime.php:815,818–819` | Retain: decimal precisions 1, 4, and 5 require these scale factors. |
| `ZonedDateTime.php:1168–1169` | Retain existing invariant guard: #54 explicitly retains negative remainder normalization behind start-of-day calculations. No independent proof covering every supported zone transition was established here. |
| `ZonedDateTime.php:1309–1318` | Retain: rejecting out-of-range minute/second/subsecond fields is part of overflow=`reject`. Neighboring hour/nanosecond checks do not subsume them. |
| `ZonedDateTime.php:1484,1510` | Retain: transition searches must return null when no offset-changing transition is found. Existing transition gap trackers #152 and #161 supply context. |
| `Internal/AnchorMath.php:408` | Retain: DST-aware calendar-day seconds plus time nanoseconds can exceed the PHP integer range; removing the check exposes float promotion at an integer-return boundary. |
| `Internal/CalendarMath.php:32,102,106–109,138,142,160,443–446` | Retain: missing/null field handling, era coercion, invalid numeric strings, nonfinite numeric strings, unsupported objects, and rounding-increment conversion are distinct input cases. `resolveYearFromEra()` callers check key presence, which does not itself exclude a null value. |
| `Internal/DateDifference.php:332,387–390` | Retain: differences shorter than a year/month still require the sign of their nonzero remainder for directional rounding. |
| `Internal/DateTimeDifference.php:444–447` | Retain: week-largest/day-smallest rounding must split rounded days into weeks and remaining days. |
| `Internal/DateTimeDifference.php:492–505` | Retain: non-ISO month/year differences need recomputation when time rounding carries into another day. ISO date arithmetic is not interchangeable with this path. |
| `Internal/DateTimeDifference.php:558–561` | Retain: directional rounding reverses when the output sign is negative. |
| `Internal/DateTimeDifference.php:662,707` | Unresolved deletion candidate; retained: positive increments prove distinct anchors for ISO arithmetic, but these helpers call `CalendarFactory::get(...)->dateAdd()` and therefore include ICU-backed calendar behavior. #54 explicitly retains them. A complete proof for bridge behavior at extreme dates is still missing. |
| `Internal/DurationRounding.php:171,334–339` | Retain: incompatible largest/smallest units and invalid sub-day increments are independently invalid option combinations. |
| `Internal/DurationRounding.php:211,296,436` | Retain: input field cast limits and post-rounding range limits address different stages. The constructor's checks do not establish the validity of a rounded result. #54 explicitly retains constructor-invariant rechecks. |
| `Internal/DurationRounding.php:412–427` | Retain: after consuming whole zoned days, the fractional remainder must use the new day's actual length. DST makes the original day length insufficient. |
| `Internal/DurationRounding.php:717,817–820` | Retain: half-even tie/parity behavior and the below-half branch implement accepted rounding semantics. |
| `Internal/DurationRounding.php:725` | Retain existing invariant guard: default behind `Options::roundingMode()` is explicitly retained in #54. Replacing it with an implicit match failure is not a useful arithmetic deletion. |
| `Internal/DurationRounding.php:795` | Retain pending stronger invariant proof: fractional progress is assembled by multiple calendar paths; no proof that it always stays below one was established. |
| `Internal/DurationRounding.php:1079–1086,1144–1148` | Retain: calendar-bearing durations with zoned anchors require applying years/months/weeks before measuring remaining time. Existing relativeTo trackers #151 and #163 are relevant. |
| `Internal/DurationRounding.php:1410–1412` | Retain: calendar days multiplied by nanoseconds per day exceed int64 well inside Temporal's date range. Float handling avoids using integer-only modulo on that value. |
| `Internal/DurationTotal.php:205,207–209` | Retain: supported minute and subsecond output units for zoned totals. |
| `Internal/DurationTotal.php:410–418` | Retain: `compute()` explicitly dispatches durations containing years/months/weeks to `calendar()` even when the requested total unit is a time unit. This entire match is reachable in that input combination; its absence from coverage does not make it dead. |
| `Internal/ZonedDifference.php:528` | Unresolved deletion candidate; retained: a recomputed negative remainder with no positive residual day needs analysis across constrained calendar addition and offset transitions. No proof that the combination is impossible was established. |
| `Internal/ZonedDifference.php:713–726,740` | Retain: non-ISO differences need calendar recomputation on time-rounding carry, followed by conversion to month-only output when requested. |

## Validation

The changed `CalendarMath.php` passes `mago format --check` through `docker compose run --rm php`. The coordinator runs the combined TC39 comparison and static checks. No fixtures, test configuration, suppression rules, or incomplete-case handling were changed by this investigator.
