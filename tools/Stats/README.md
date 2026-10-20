# Repository statistics

Collects per-commit metrics for every commit on `origin/master` and renders them
as a self-contained dashboard.

## Running it

```bash
./tools/Stats/collect-git.sh      # LOC, churn, files, bytes — a few seconds
./tools/Stats/collect-tests.sh    # coverage, tests, assertions, runtime — one PHPUnit run per commit
docker compose exec php php /app/tools/Stats/bin/render.php
```

The dashboard lands at `build/stats/dashboard.html`. Open it directly in a
browser; it has no external dependencies beyond the two webfonts.

Both collectors are incremental. `collect-git.sh` exits immediately when
`data/git.json` already covers the current tip. `collect-tests.sh` replays only
the commits missing from `data/runs/`, so the usual cost of a rerun is one
PHPUnit run per new commit. Pass `--force` to either one to recollect anyway.

`collect-tests.sh --jobs N` replays in N parallel git worktrees under
`build/stats/work/`. These are scratch and safe to delete
(`git worktree remove --force build/stats/work/w0`, and so on), but keeping them
saves copying `vendor/` on the next run. Progress goes to stdout and to
`build/stats/progress.log`.

## Why git runs on the host

`collect-git.sh` shells out to `git` on the host rather than inside the `php`
service. The container cannot resolve a worktree's gitdir pointer, so `git`
commands fail there with "not a repository". Everything else — PHP, PHPUnit,
composer — goes through the container as usual.

## Layout

| Path | Role |
|---|---|
| `collect-git.sh` | git-only pass; writes `data/git.json` |
| `collect-tests.sh` | replay driver; writes `data/runs/<sha>.json` |
| `GitHistoryBuilder.php` | turns raw `git log`/`ls-tree` output into `git.json` |
| `RunParser.php` | reads one commit's coverage XML + JUnit log into a cache record |
| `DashboardRenderer.php` | merges both datasets into the template |
| `bin/` | entry points for the three classes above |
| `dashboard-template.html` | the page, with a `/*__STATS_DATA__*/` marker |
| `data/` | the committed cache — this is what makes reruns cheap |

## What the numbers mean

Line counts accumulate Git text additions minus deletions along the first-parent
history. Merge diffs explicitly compare against the first parent. Binary files
do not contribute lines; text/binary transitions can make cumulative LOC an
estimate. File and byte counts come from each actual Git tree.

Line coverage is the honest coverage series. PHPUnit's class and method coverage
count all-or-nothing per unit, so they read far lower than the line figure and
move in steps; they are shown but should not be read as a quality trend.

## Comparing measurements

New replay records use schema version 2. They record collection time, source and
collector commits, PHP/ICU/PHPUnit versions, installed dependency fingerprint,
historical lock/config hashes, fixture and generated-script Git tree IDs, and the
command and scope. The upstream test262 revision is unknown because the sync
script does not record it; the content tree ID identifies the actual corpus.
Existing records are preserved and display unknown provenance, never invented
historical runtime versions or zeroes for missing measurements.

Tests count reported JUnit testcases, including generated variants. Passed means
no failure, error, or skipped element was reported. Skipped and incomplete share
one counter because PHPUnit writes identical JUnit elements for them. Counts are
split by test class into porcelain/test262/other. Coverage uses executed lines
over executable lines; missing artifacts stay unknown and failed/timeout attempts
remain visible. A successful exit alone does not prove complete artifact coverage.

Compare counts with their denominators and provenance. A changed corpus, config,
dependency set or runtime can change the result without indicating a regression.
No relative improvement percentage is inferred. Wall time measures replay cost
with coverage, not a library performance benchmark. Replays use a copied current
vendor tree rather than installing each historical lock file.

Mutation trends are intentionally absent until completed reports with source,
scope and completeness can be imported. Confirmed kills, escapes, uncovered
mutants, errors and timeouts must remain separate; a partial audit is not full MSI.
Collecting repository statistics never launches a mutation audit.

For a worktree mounted beneath `/app`, set `STATS_CONTAINER_ROOT` to its container
path (for example `/app/build/repo-stats`). On Windows run shell collectors with
Git Bash and `MSYS_NO_PATHCONV=1`. Set `COMPOSE_PROJECT_NAME` to the already-running
project (for example `calendrics`) when a nested worktree has a different name.
PHP entry points resolve paths from their own
checkout. The replay defaults to one worker; use `--limit` for bounded collection.

Run tooling regression checks with:

```bash
docker compose exec php php /app/tools/Stats/bin/check.php
```
