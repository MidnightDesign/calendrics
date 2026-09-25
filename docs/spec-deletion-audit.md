# Spec deletion audit

Tracking issue: [#173](https://github.com/MidnightDesign/calendrics/issues/173).

This audit starts at `7cc75831286c3e1d2031d46eaadd904bcb923a58` and follows the earlier work in [#54](https://github.com/MidnightDesign/calendrics/issues/54). The old deletion children #67, #68, and #69 were already closed. Their proofs were not assumed to apply to the current code.

## Results

Five source simplifications remove 25 net lines of PHP:

| Change | Reason deletion preserves behavior |
| --- | --- |
| Share Stringable and string numeric validation in `CalendarMath` | Both branches performed the same validation and conversion. Stringable conversion still runs exactly once and exceptions still propagate. |
| Remove `Instant`'s decimal-zero early return | The existing short-digit path casts the empty trimmed string to zero and returns the same epoch pair for either sign. |
| Replace `PlainTime`'s precision table with `10 ** (9 - $digits)` | Validated digits are 0–9; all powers are exact integers within int64. The auto/minute sentinels retain separate branches. |
| Remove `CalendarFactory`'s empty-string early return | Every following pattern rejects the empty string and the final result is false. |
| Remove the private `IntlCalendarBridge` Gregorian match alternative | Both caller paths return earlier for `gregory`; Japanese and offset-calendar fallbacks remain. |

An independent investigator reviewed these proofs. No fixture, test harness, suppression, source filter, or public signature changed.

## Verification

Environment: PHP 8.4.25, ICU 76.1, PCOV 1.0.12, PHPUnit 11.5.55. Commands use a one-off Compose `php` service because the existing long-running container mounts another checkout.

| TC39-only measurement | Before | After |
| --- | ---: | ---: |
| Cases | 11,375 | 11,375 |
| Assertions | 1,031,053 | 1,031,053 |
| Incomplete cases | 298 | 298 |
| Failures / errors | 0 / 0 | 0 / 0 |
| Spec covered / executable lines | 9,716 / 10,092 | 9,709 / 10,074 |
| Spec missed lines | 376 | 365 |

The comparison verifies each case name, assertion count, and outcome, plus hashes of both fixture trees. This is stronger than matching totals: new incomplete cases cannot offset previously incomplete cases. PHPUnit excludes coverage from incomplete cases, so missed lines are not automatically unexecuted lines.

PHPStan and Psalm passed. Mago lint and analysis exited successfully with warnings and advisory messages; formatting and `git diff --check` passed. Only TC39 tests were run for this audit; the full porcelain/mutation gate is not claimed here.

## Reproduce

Run from the repository root. Capture the baseline before applying deletions:

```sh
docker compose run --rm php vendor/bin/phpunit --testsuite test262 --no-progress --coverage-clover build/coverage/tc39-audit.xml --log-junit build/coverage/tc39-audit-junit.xml
python3 tools/audit-spec-coverage.py build/coverage/tc39-audit.xml build/coverage/tc39-audit-junit.xml --save build/coverage/tc39-audit-baseline.json
```

Then capture and compare the changed code:

```sh
docker compose run --rm php vendor/bin/phpunit --testsuite test262 --no-progress --coverage-clover build/coverage/tc39-audit-after.xml --log-junit build/coverage/tc39-audit-after-junit.xml
python3 tools/audit-spec-coverage.py build/coverage/tc39-audit-after.xml build/coverage/tc39-audit-after-junit.xml --baseline build/coverage/tc39-audit-baseline.json
```

The tool selects `src/Spec/` from Clover explicitly. PHPUnit's `--coverage-filter` adds to the XML source configuration and does not replace it. Reports and snapshots remain in ignored `build/coverage`; the baseline can be regenerated at the recorded commit.

## Candidate ledger

- [Parsing and validation](spec-deletion-audit-parsing.md)
- [Arithmetic](spec-deletion-audit-arithmetic.md)
- [Infrastructure](spec-deletion-audit-infrastructure.md)

These ledgers classify the baseline gaps and state where proof is incomplete. Remaining supported option combinations, PHP interop, bounded caches, and previously reviewed guards were retained. Retained code is not thereby proven correct; suspected defects and uncertain deletion candidates remain explicit follow-up work.
