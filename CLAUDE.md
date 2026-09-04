# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A PHP 8.4 implementation of the [TC39 Temporal API](https://tc39.es/proposal-temporal/). See `README.md` for a tour of the public API, deliberate deviations from TC39, and the backwards-compatibility contract.

## Running commands

Everything runs inside the `php` container. The user usually has `docker compose up -d` running already; if not, start it. **Do not invoke `php` / `composer` / `node` on the host** — always go through the service:

```bash
docker compose exec php composer <script>
docker compose exec php vendor/bin/phpunit --testsuite porcelain
docker compose exec php vendor/bin/phpunit --filter testName tests/Porcelain/PlainDateTest.php
```

Composer scripts (defined in `composer.json`):

| Script | Purpose |
|---|---|
| `test` | All PHPUnit suites |
| `test262:build` | Transpile `tests/Test262/data/*.js` → `tests/Test262/scripts/*.php` (runs `node tools/transpile-test262.mjs` then `mago format`) |
| `test262:run` | Run only the transpiled test262 conformance suite |
| `test262:sync` | Refresh `tests/Test262/data/` from upstream tc39/test262 (`tools/sync-test262.sh`) |
| `phpstan` / `psalm` / `mago` | Static analysis (PHPStan level 9, Psalm level 1, Mago lint+analyze) |
| `infection` | Mutation testing (target: 100% MSI) |
| `check` | Full gate: phpstan + psalm + mago + mago-format-check + test-coverage + infection |

PHPUnit suites (`phpunit.xml`): `porcelain` and `test262`. They are exhaustive — there is no catch-all suite, so a test placed in a new top-level directory under `tests/` runs under no suite at all.

## Architecture

Two parallel, fully supported public API tiers plus an internal core:

- **`Calendrics\`** (porcelain) — `src/*.php`. Idiomatic PHP: strict types, backed enums, named arguments, readonly value objects. What application code should normally use.
- **`Calendrics\Spec\`** (spec layer) — `src/Spec/*.php`. TC39-faithful surface, validated by the test262 conformance suite. Public API, not internal. Mirrors the porcelain class set 1:1.
- **`Calendrics\Spec\Internal\`** — genuine implementation detail. Calendar protocol/bridges (ECMA-402 calendars via `ext-intl`, plus pure-PHP Hebrew/Indian implementations), serde, calendar math. Free to break across versions; do not import from outside `Calendrics\Spec\`.

Each porcelain class has a matching spec class and pairs of `toSpec()` / `fromSpec()` for round-tripping. Every porcelain↔spec seam is covered by the BC promise (`X::fromSpec($x->toSpec()) === $x` within a major).

Shared property/getter logic lives in `src/Trait/Has*Properties.php` (porcelain) and `Has*Spec.php` (spec). When adding a field that crosses several classes, look for the relevant trait first.

## Which layer gets which tests

**Only test262 tests the spec layer.** Never hand-write a test against `Calendrics\Spec\` — that includes `Calendrics\Spec\Internal\`. There is no `tests/Spec/` directory, and adding one is wrong.

This rule **overrides the test-first instruction in any skill** (`/implement`, `/tdd`, and friends). When a skill says "write a failing test first" and the code under test is in the spec layer, the rule here wins.

**What makes a test a spec test is the behavior it pins, not the class it names.** Two evasions both count as spec tests and are both wrong:

- Filing the test under `tests/Porcelain/` while it still imports `Calendrics\Spec\` classes. The directory does not change what it tests.
- Reaching the same spec code through its porcelain wrapper. `Calendrics\Duration::round()` delegates to `Calendrics\Spec\Internal\DurationRounding`; a test that pins TC39 rounding semantics through the porcelain class is a spec test with a porcelain import list.

Ask what the test would catch. If a TC39 change would make it fail, it belongs to test262. Porcelain tests cover what porcelain *adds* on top: enums instead of magic strings, named arguments, readonly value objects, the exception hierarchy, and `X::fromSpec($x->toSpec()) === $x` round-tripping. One case per porcelain affordance, not a table of TC39 results.

The reliable tell is the diff: if the source change is under `src/Spec/`, no test in this repository belongs in the same commit.

**A spec-layer fix with no test is better than a spec-layer fix with a hand-written test.** Landing the fix untested is the accepted outcome. Take these two routes first, in order:

1. **Find the upstream fixture.** Look under `tests/Test262/data/<Type>/…` for a fixture that reaches the code you changed. If one exists but does not exercise your case, the corpus may be stale — run `composer test262:sync`. If it exists and *should* cover your case but the PHP side never runs it, that is the real bug: the fixture may be missing from `tests/Test262/scripts/` (regenerate with `composer test262:build`), skipped by `RunnerTest`, or reported incomplete by a transpiler gap. Fix that instead of writing your own test.

2. **File for an upstream test.** Only after you have *actually checked* — read the candidate fixtures, confirmed the case is absent, and confirmed the pre-fix code passes the whole suite — open an issue labeled `missing-upstream-test`. That issue tracks getting a new test contributed to tc39/test262, so it needs the input shape, the expected result, the TC39 algorithm step that mandates it, and a JS reproduction against the `Temporal` namespace. "I could not find one" is not a check; a search you can show is.

## test262 conformance suite

`tests/Test262/data/` — verbatim mirror of upstream tc39/test262 JS files. **Do not edit these.** If a test fails, fix the implementation. `tests/Test262/data/CLAUDE.md` has the rules.

`tests/Test262/scripts/` — generated PHP transpiled from the JS. **Do not hand-edit.** Regenerate via `composer test262:build`. The scripts are loaded by `tests/Test262/RunnerTest.php` against the `Calendrics\Spec\` layer.

## Quality bar

PHPStan level 9, Psalm error level 1, Mago lint+format clean, 100% mutation kill (Infection). The `composer check` script is the gate every PR must pass. Don't suppress warnings — fix the underlying types.

## Releases

Versioning and `CHANGELOG.md` are owned by [release-please](https://github.com/googleapis/release-please) (`.github/workflows/release-please.yml`, `release-please-config.json`, `.release-please-manifest.json`). Consequences when working here:

- **`CHANGELOG.md` is generated. Do not hand-edit it**, and do not add an `Unreleased` section — release-please inserts each new entry between the preamble and the newest existing entry.
- **PR titles are load-bearing.** Squash is the repository's only merge method, so the PR title becomes the commit subject release-please parses. It must be a Conventional Commit (`feat:`, `fix:`, `refactor:`, `test:`, …); `feat!:` or a `BREAKING CHANGE:` footer marks a breaking change. A subject that does not parse is skipped silently — no changelog entry, no bump. See README's *Releases* section for the type → section → bump table. Dependabot gets its prefixes from `.github/dependabot.yml`.
- Release type is `php`, which writes no version number outside `.release-please-manifest.json`: `composer.json` has no `version` key (correct for Packagist — do not add one) and there is no `VERSION` file. The `VERSION` updater is therefore a no-op, but the `composer.json` one still re-serializes the file, so a release PR can carry a one-time reformat of it.
- `bootstrap-sha` in the config pins the first run's starting point at the 0.2.0 handoff commit. It is ignored once a release-please PR has been merged and can be deleted then.

## Git worktrees

Sub-feature branches are sometimes checked out into `.claude/worktrees/<name>/`. When working in a worktree, stay in it — don't reach back into the main repo path.
