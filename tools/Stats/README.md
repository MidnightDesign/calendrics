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

Line counts come from accumulating `git log --numstat` churn per path bucket
rather than reading every tree, which is exact on a linear history and far
cheaper. Validated against `wc -l` at the tip.

Line coverage is the honest coverage series. PHPUnit's class and method coverage
count all-or-nothing per unit, so they read far lower than the line figure and
move in steps; they are shown but should not be read as a quality trend.
