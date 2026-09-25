# Infrastructure deletion audit

Baseline: `7cc75831286c3e1d2031d46eaadd904bcb923a58`, `build/coverage/tc39-audit.xml`.
Line numbers below refer to that baseline. Scope: `src/Spec/Internal/Calendar/`,
`EpochValue`, `HasPlainLocaleString`, `IntlFormatter`, `PhpDateTimeInterop`, and
`TimeZoneHelper`. The scope contains **94 uncovered executable statements**.

The prior audit [#54](https://github.com/MidnightDesign/calendrics/issues/54)
explicitly retained several guards. This audit preserves those decisions; lack of
coverage alone is not evidence that a failure check or supported option is obsolete.

## Deletions

| Candidate | Evidence | Decision |
| --- | --- | --- |
| `CalendarFactory::looksLikeIsoDateOrTime`, lines 201–203 | The early empty-string return duplicates the result of every following regex: every alternative requires one or more characters. The final expression also returns false for an empty string. Removing the guard preserves the result for every string, including an empty prefix before a bracket annotation. | Delete the three-line guard, including one uncovered statement. |
| `IntlCalendarBridge::setCalendarFields`, line 1256, `gregory` alternative | This private method has callers only in `calendarToIso` and `dateAdd`. `calendarToIso` returns from its earlier Gregorian branch. For `gregory`, `dateAdd` always takes its fast path because `$cutoverSafe` is unconditionally true. The readonly calendar ID cannot change between the check and the call. | Delete the `gregory` match alternative. Keep the Japanese alternative: dates before the cutover still use this method. This removes no whole uncovered statement because the alternatives shared one line. |

No public signature, test fixture, suppression, or source coverage configuration changes.

## Remaining uncovered inventory

Paths in the table are relative to `src/Spec/Internal/`.

| File and baseline lines | Statements | Verdict and evidence |
| --- | ---: | --- |
| `EpochValue.php`: 71, 75–78, 80, 82 | 7 | Retain. The two `fromEpochParts` factories accept `int\|float`; narrowing and rejecting nonfinite/out-of-range parts belongs to that PHP boundary. Explicitly retained by #54. |
| `HasPlainLocaleString.php`: 48 | 1 | Retain. This enforces the trait/interface pairing before `PlainLocaleFormat::from` consumes it. All five current users implement the interface; it is an internal contract guard, not unused functionality. |
| `IntlFormatter.php`: 288–293 | 6 | Retain. `checkedKeyword` rejects unsupported option values; removing it would accept invalid inputs or fail later with the wrong error. The allowlists and public `toLocaleString` options provide the requirement. |
| `IntlFormatter.php`: 338, 438, 487 | 3 | Retain. These handle external ICU calendar/pattern failures. The pattern fallbacks were explicitly retained by #54. |
| `IntlFormatter.php`: 569, 572 | 2 | Retain. Hour-cycle options must append correctly to legacy locale keywords and existing Unicode extensions. They have distinct construction rules; these are supported input combinations. |
| `IntlFormatter.php`: 599, 609, 620–622, 655, 657, 665–668 | 11 | Retain. These implement supported weekday/era/month widths, day-period widths, and time-zone display styles. They are explicit members of the validated option sets. Removing match arms would break accepted inputs. |
| `IntlFormatter.php`: 724–725, 727–731, 733 | 8 | Retain. `widenHourField` implements the accepted `hour: '2-digit'` option when ICU returns a single-width pattern; the call is conditional on that exact option. The quoted-literal branch preserves literal text. |
| `PhpDateTimeInterop.php`: 47, 56–61, 64, 81–86, 89–94, 96 | 21 | Retain. Porcelain `Instant::fromDateTime/toDateTime` and `ZonedDateTime::fromDateTime/toDateTime` directly call these methods. TC39 cannot express PHP DateTime interop. Moving the class would change coverage accounting, not delete functionality. |
| `TimeZoneHelper.php`: 178 | 1 | Retain. External PHP `getTransitions` can return false; this normalizes its result. Explicitly retained by #54. |
| `Calendar/CalendarFactory.php`: 175–177 | 3 | Retain. Rejects bracket annotations following a non-date/time prefix. Removing it accepts malformed calendar input. |
| `Calendar/IntlCalendarBridge.php`: 205, 332, 352, 376, 384, 407, 432, 674 | 8 | Retain. Cache-cap eviction bounds memory for long-running applications over many dates. A finite test corpus not reaching the cap is not a deletion proof. |
| `Calendar/IntlCalendarBridge.php`: 458 | 1 | Retain. Gregorian calendar conversion must constrain a month above 12 to 12 when overflow is `constrain`; rejection occurs in the preceding branch. |
| `Calendar/IntlCalendarBridge.php`: 524–526, 528 | 4 | Retain. For corrected Chinese leap years, an input leap-month code different from the corrected month must constrain or reject. The preceding correction lookup and comparison make these distinct supported cases. |
| `Calendar/IntlCalendarBridge.php`: 554–555 | 2 | Unresolved. This second leap-code fallback may duplicate the earlier Chinese/Dangi validation. Deleting it needs a proof covering every exception from `setCalendarFieldsFromMonthCode`, including corrected leap years and the non-Chinese calendar routes. No deletion on coverage evidence alone. |
| `Calendar/IntlCalendarBridge.php`: 996, 1022, 1026, 1338, 1362 | 5 | Retain. Month-code shape/range checks at calendar conversion boundaries. The earlier audit explicitly retained the shape checks; this audit has not established a stronger reason to reverse that decision. |
| `Calendar/IntlCalendarBridge.php`: 1257–1258 | 2 | Retain the Buddhist and ROC year-offset conversions. `dateAdd` still enters the fallback when Gregorian-based dates cross or precede 1583. Only the proven-unreachable `gregory` alternative on line 1256 is removed. |
| `Calendar/IntlCalendarBridge.php`: 1530 | 1 | Retain. The second half of the Chinese leap-month correction maps ICU's buggy leap month back to the regular month. The paired remapping is required for consistency with the existing correction table. |
| `Calendar/IntlCalendarFactory.php`: 94 | 1 | Retain. Normalizes failed external ICU calendar creation into a controlled exception. |
| `Calendar/IsoCalendar.php`: 230 | 1 | Retain. Implements the required `CalendarProtocol::resolveEra` method. ISO has no era mapping, so null is its implementation; deleting the method would violate the interface. |
| `Calendar/PureHebrewCalendar.php`: 157 | 1 | Retain. Ordinal range invariant guard explicitly retained by #54. |
| `Calendar/PureIndianCalendar.php`: 215–216, 218, 225 | 4 | Retain. Indian calendar conversion constrains or rejects an oversized month/day. Those branches implement the overflow option exposed through the calendar protocol. |

Accounting: 1 uncovered statement deleted, 91 retained, 2 unresolved. The additional
unreachable `gregory` alternative shares an executable statement with retained code.

## Validation

The coordinator runs the combined TC39 suite and static-analysis gates after all
investigators finish. Acceptance requires the baseline 11,375 cases, 1,031,053
assertions, and 298 incomplete cases, with no new failures, errors, or incomplete
cases. No hand-written Spec tests are introduced.
