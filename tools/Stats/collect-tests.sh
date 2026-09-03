#!/usr/bin/env bash
#
# Replay the test suite across history and cache one record per commit.
#
# Usage:
#   ./tools/Stats/collect-tests.sh                 # every commit on origin/master
#   ./tools/Stats/collect-tests.sh --jobs 3        # parallel replay workers
#   ./tools/Stats/collect-tests.sh --limit 5       # first 5 outstanding commits
#   ./tools/Stats/collect-tests.sh --force         # recollect commits already cached
#
# Each commit costs one PHPUnit run (~40-60s), so results are cached per commit
# in tools/Stats/data/runs/<sha>.json and reruns only touch what is missing.
# Progress goes to stdout and to build/stats/progress.log.
#
# php: >=8.4 and phpunit: ^11.0 have never moved on master, so one vendor tree
# replays the whole history; only the autoloader is redumped per commit, because
# the root namespace was renamed from Temporal\ to Calendrics\ at 0ad2c31b.

set -euo pipefail

JOBS=3
LIMIT=0
FORCE=0
REF="origin/master"
TIMEOUT=900

while [[ $# -gt 0 ]]; do
    case "$1" in
        --jobs) JOBS="$2"; shift 2 ;;
        --limit) LIMIT="$2"; shift 2 ;;
        --timeout) TIMEOUT="$2"; shift 2 ;;
        --force) FORCE=1; shift ;;
        --ref) REF="$2"; shift 2 ;;
        *) echo "unknown option: $1" >&2; exit 1 ;;
    esac
done

# build/coverage was tracked until 58f5ed3c, so git has to write those paths when
# checking out the early commits. Running the php service as the host user keeps
# root-owned artifacts out of the replay worktrees, which is what blocked it.
DOCKER_USER="$(id -u):$(id -g)"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
STATS="$ROOT/build/stats"
RUNS="$ROOT/tools/Stats/data/runs"
LOG="$STATS/progress.log"

cd "$ROOT"

mkdir -p "$RUNS"
docker compose exec -T php sh -c \
    "mkdir -p /app/build/stats/work && chown -R $(id -u):$(id -g) /app/build/stats"
: >"$LOG"

mapfile -t COMMITS < <(git rev-list --first-parent --reverse "$REF")
TOTAL=${#COMMITS[@]}

TODO=()
for sha in "${COMMITS[@]}"; do
    if [[ $FORCE -eq 0 && -f "$RUNS/$sha.json" ]]; then
        continue
    fi
    TODO+=("$sha")
done

CACHED=$((TOTAL - ${#TODO[@]}))

if [[ $LIMIT -gt 0 && ${#TODO[@]} -gt $LIMIT ]]; then
    TODO=("${TODO[@]:0:$LIMIT}")
fi

OUTSTANDING=${#TODO[@]}

echo "==> $REF: $TOTAL commits, $CACHED already cached, $OUTSTANDING to replay"
if [[ $OUTSTANDING -eq 0 ]]; then
    echo "    nothing to do"
    exit 0
fi

if [[ $JOBS -gt $OUTSTANDING ]]; then
    JOBS=$OUTSTANDING
fi

# Strided assignment: the suite grows over history, so contiguous chunks would
# leave the early workers idle while the last one ground through the slow modern
# commits. A stride of $JOBS keeps each worker's checkouts a few commits apart,
# which stays cheap, while giving every worker the same mix of fast and slow ones.
echo "==> $JOBS worker(s), stride $JOBS over $OUTSTANDING commits"

for ((w = 0; w < JOBS; w++)); do
    WT="$STATS/work/w$w"
    if [[ ! -d "$WT" ]]; then
        echo "==> preparing worker $w"
        git worktree add --detach --quiet "$WT" "${COMMITS[0]}"
        docker compose exec -T --user "$DOCKER_USER" php \
            cp -a /app/vendor "/app/build/stats/work/w$w/vendor"
    fi
done

replay_one() {
    local worker="$1" sha="$2" index="$3"
    local wt="$STATS/work/w$worker"
    local rel="build/stats/work/w$worker"

    # build/ only became gitignored partway through history, so it has to be
    # excluded explicitly or git clean trips over the container's root-owned
    # coverage artifacts at the early commits.
    (cd "$wt" && git checkout --detach --force --quiet "$sha" && git clean -qfd -e build -e vendor)

    # A crashed run must not leave the previous commit's artifacts behind for
    # the parser to read as if they belonged to this commit.
    docker compose exec -T --user "$DOCKER_USER" php rm -rf "/app/$rel/build/coverage" || true

    local start end seconds status=ok exit_code=0
    start=$(date +%s)

    docker compose exec -T --user "$DOCKER_USER" -e COMPOSER_HOME=/tmp/composer php sh -c "
        cd /app/$rel &&
        composer dump-autoload --no-scripts --quiet 2>/dev/null;
        timeout $TIMEOUT php -d memory_limit=1G vendor/bin/phpunit tests/ \
            --coverage-xml build/coverage/coverage-xml \
            --log-junit build/coverage/junit.xml" >/dev/null 2>&1 || exit_code=$?

    end=$(date +%s)
    seconds=$((end - start))

    case "$exit_code" in
        0) status=ok ;;
        1 | 2) status=tests-failed ;;
        124) status=timeout ;;
        *) status="crashed-$exit_code" ;;
    esac

    local summary
    summary=$(docker compose exec -T --user "$DOCKER_USER" php \
        php -d memory_limit=1G /app/tools/Stats/bin/parse-run.php \
        "/app/$rel/build/coverage/coverage-xml/index.xml" \
        "/app/$rel/build/coverage/junit.xml" \
        "$sha" "$seconds" "$status" \
        "/app/tools/Stats/data/runs/$sha.json" 2>&1) || summary="parse-failed"

    local done_count
    done_count=$(find "$RUNS" -name '*.json' | wc -l)
    printf '[w%s] %4d/%d  %s  %s  %s  %ss\n' \
        "$worker" "$done_count" "$TOTAL" "${sha:0:9}" \
        "$(git log -1 --format=%cd --date=short "$sha")" "$summary" "$seconds" |
        tee -a "$LOG"
}

START_ALL=$(date +%s)

for ((w = 0; w < JOBS; w++)); do
    (
        for ((i = w; i < OUTSTANDING; i += JOBS)); do
            sha="${TODO[$i]}"
            if ! replay_one "$w" "$sha" "$i"; then
                printf '[w%s] %s  REPLAY FAILED\n' "$w" "${sha:0:9}" | tee -a "$LOG"
            fi
        done
    ) &
done

wait

ELAPSED=$(($(date +%s) - START_ALL))
FINAL=$(find "$RUNS" -name '*.json' | wc -l)
printf '==> done: %d/%d commits cached in %dm%02ds\n' \
    "$FINAL" "$TOTAL" $((ELAPSED / 60)) $((ELAPSED % 60)) | tee -a "$LOG"
