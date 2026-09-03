#!/usr/bin/env bash
#
# Collect the per-commit repository metrics that need nothing but git.
#
# Usage:
#   ./tools/Stats/collect-git.sh              # collect origin/master
#   ./tools/Stats/collect-git.sh master       # collect another ref
#   ./tools/Stats/collect-git.sh --force      # recollect even if up to date
#
# Writes tools/Stats/data/git.json. The whole history takes a few seconds, so
# this is safe to rerun; it exits early when the ref holds no new commits.
#
# Git runs on the host because the container cannot resolve the worktree's
# gitdir (an absolute host path), so the parsing half runs in the php service.

set -euo pipefail

FORCE=0
REF=""
for arg in "$@"; do
    case "$arg" in
        --force) FORCE=1 ;;
        *) REF="$arg" ;;
    esac
done
REF="${REF:-origin/master}"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RAW="$ROOT/build/stats/raw"
OUT="$ROOT/tools/Stats/data/git.json"

cd "$ROOT"

if ! git rev-parse --verify --quiet "$REF" >/dev/null; then
    echo "error: no such ref: $REF" >&2
    exit 1
fi

mapfile -t COMMITS < <(git rev-list --first-parent --reverse "$REF")
TOTAL=${#COMMITS[@]}
HEAD_SHA="${COMMITS[-1]}"

echo "==> $REF: $TOTAL commits, tip ${HEAD_SHA:0:9}"

if [[ $FORCE -eq 0 && -f "$OUT" ]] && grep -q "\"$HEAD_SHA\"" "$OUT"; then
    have=$(grep -c '"sha":' "$OUT" || true)
    if [[ "$have" -eq "$TOTAL" ]]; then
        echo "    already up to date ($have commits) — pass --force to recollect"
        exit 0
    fi
fi

# The php service runs as root, so anything it writes into the bind mount is
# root-owned. Create the scratch dirs there and hand them back to the caller.
docker compose exec -T php sh -c \
    "mkdir -p /app/build/stats/raw && chown -R $(id -u):$(id -g) /app/build/stats"

# Release tags annotate the charts. Annotated tags point at a tag object, so the
# dereferenced target is the one that names a commit.
git for-each-ref --format='%(refname:short)%09%(objectname)%09%(*objectname)' refs/tags \
    >"$RAW/tags.tsv"

echo "==> Pass 1/2: churn"
git log "$REF" --first-parent --reverse --no-renames --numstat \
    --format='C%x1fH%H%x1fT%ct%x1fA%an%x1fS%s' >"$RAW/numstat.txt"
echo "    $(wc -l <"$RAW/numstat.txt") lines"

echo "==> Pass 2/2: trees"
# Streams every commit's file list into the parser. The trees are large (the
# test262 corpus alone is ~18k files per commit) so this is piped, never stored.
{
    i=0
    for sha in "${COMMITS[@]}"; do
        i=$((i + 1))
        printf 'C\x1f%s\n' "$sha"
        git ls-tree -r -l "$sha"
        printf '\r    [%4d/%d] %s' "$i" "$TOTAL" "${sha:0:9}" >&2
    done
    printf '\r    [%4d/%d] done%*s\n' "$TOTAL" "$TOTAL" 20 '' >&2
} | docker compose exec -T php php /app/tools/Stats/bin/build-git-json.php
