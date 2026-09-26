#!/usr/bin/env bash
#
# Sync test262 Temporal test files from the upstream tc39/test262 repository.
#
# Usage:
#   ./tools/sync-test262.sh            # sync all implemented classes
#   ./tools/sync-test262.sh Duration   # sync only Duration
#
# This does a sparse checkout of just the Temporal directories we need, plus the
# Temporal-tagged fixtures under the ECMA-402 formatters, then rsyncs them into
# tests/Test262/data/.

set -euo pipefail

REPO_URL="https://github.com/tc39/test262.git"
CLONE_DIR="$(mktemp -d)"
DATA_DIR="$(cd "$(dirname "$0")/../tests/Test262/data" && pwd)"

# ECMA-402 formatter entry points that accept Temporal values directly. Their
# Temporal-tagged fixtures live outside test/intl402/Temporal/ but exercise the
# same formatting paths, so they are always synced regardless of the class
# filter.
INTL_FORMATTERS=(
    DateTimeFormat
    DurationFormat
)

# All Temporal classes we track
ALL_CLASSES=(
    Duration
    Instant
    Now
    PlainDate
    PlainDateTime
    PlainMonthDay
    PlainTime
    PlainYearMonth
    ZonedDateTime
)

# Upstream areas that are not organized per class. They are synced only on a
# full sync, so `sync-test262.sh PlainDate` keeps meaning "just that class".
SYNC_NON_CLASS_AREAS=false

# If args given, sync only those classes; otherwise sync all
if [[ $# -gt 0 ]]; then
    CLASSES=("$@")
else
    CLASSES=("${ALL_CLASSES[@]}")
    SYNC_NON_CLASS_AREAS=true
fi

cleanup() {
    rm -rf "$CLONE_DIR"
}
trap cleanup EXIT

echo "==> Cloning test262 (sparse, depth=1)..."
git clone --depth 1 --filter=blob:none --sparse "$REPO_URL" "$CLONE_DIR" 2>&1 | tail -1

cd "$CLONE_DIR"

# Set up sparse checkout for just the Temporal dirs we need
SPARSE_PATHS=()
for class in "${CLASSES[@]}"; do
    SPARSE_PATHS+=("test/built-ins/Temporal/$class")
    SPARSE_PATHS+=("test/intl402/Temporal/$class")
done

for formatter in "${INTL_FORMATTERS[@]}"; do
    SPARSE_PATHS+=("test/intl402/$formatter")
done

if [[ $SYNC_NON_CLASS_AREAS == true ]]; then
    SPARSE_PATHS+=("test/staging/Temporal")
fi

git sparse-checkout set "${SPARSE_PATHS[@]}" 2>/dev/null

echo "==> Syncing test files..."

total_added=0
total_removed=0
total_changed=0

sync_tree() {
    local src=$1
    local dst=$2
    local label=$3
    local missing=${4:-warn}

    if [[ ! -d "$src" ]]; then
        if [[ $missing == warn ]]; then
            echo "    WARN: $label not found in upstream repo, skipping"
        fi
        return
    fi

    local src_count
    src_count=$(find "$src" -name '*.js' | wc -l)
    if [[ $src_count -eq 0 ]]; then
        return
    fi

    local before=0
    if [[ -d "$dst" ]]; then
        before=$(find "$dst" -name '*.js' | wc -l)
    fi

    mkdir -p "$dst"

    local changes
    changes=$(rsync -rl --no-group --no-owner --delete --itemize-changes --out-format='%i %n%L' \
        --include='*/' --include='*.js' --exclude='*' "$src/" "$dst/")

    local after
    after=$(find "$dst" -name '*.js' | wc -l)

    if [[ -z "$changes" ]]; then
        echo "    $label: $after (up to date)"
        return
    fi

    local added
    local removed
    local updated
    added=$(awk '$1 == ">f+++++++++" { count++ } END { print count + 0 }' <<< "$changes")
    removed=$(awk '$1 == "*deleting" { count++ } END { print count + 0 }' <<< "$changes")
    updated=$(awk '$1 ~ /^>f/ && $1 != ">f+++++++++" { count++ } END { print count + 0 }' <<< "$changes")

    echo "    $label: $before -> $after (+$added, -$removed, ~$updated updated)"
    total_added=$((total_added + added))
    total_removed=$((total_removed + removed))
    total_changed=$((total_changed + 1))
}

for class in "${CLASSES[@]}"; do
    sync_tree "$CLONE_DIR/test/built-ins/Temporal/$class" "$DATA_DIR/$class" "$class"
done

# Sync intl402 tests into a separate directory
INTL402_DIR="$DATA_DIR/intl402"

echo ""
echo "==> Syncing intl402 test files..."

for class in "${CLASSES[@]}"; do
    sync_tree "$CLONE_DIR/test/intl402/Temporal/$class" "$INTL402_DIR/$class" "intl402/$class" skip
done

# ---------------------------------------------------------------------------
# Upstream areas that are not organized per class
# ---------------------------------------------------------------------------
#
# Synced:
#
#   test/staging/Temporal/ -> data/staging/
#     A June-2024 API-removals guard plus V8's ported calendar-day-of-week
#     suite. The redundant "Temporal" path segment is dropped, the same way the
#     intl402 tree above drops it.
#
#   test/intl402/{DateTimeFormat,DurationFormat}/ ->
#       data/intl402/{DateTimeFormat,DurationFormat}/
#     Only fixtures tagged with the Temporal feature are synced. The remaining
#     files exercise Intl surface that this project does not implement.
#
# Deliberately NOT synced:
#
#   test/built-ins/Temporal/*.js (top level: getOwnPropertyNames.js, keys.js,
#     prop-desc.js)
#     All three assert JS object-model facts about the `Temporal` namespace
#     object: which own property names it has, that it exposes no enumerable
#     properties, and the writable/enumerable/configurable attributes of the
#     global `Temporal` property. A PHP namespace has no property table, so
#     none of the three can ever run — they transpile to Assert::incomplete().

if [[ $SYNC_NON_CLASS_AREAS == true ]]; then
    echo ""
    echo "==> Syncing staging test files..."

    sync_tree "$CLONE_DIR/test/staging/Temporal" "$DATA_DIR/staging" staging
fi

# Sync the Temporal-tagged subset of the ECMA-402 formatter tests. Build a
# filtered source tree first so sync_tree can retain its update detection and
# deletion behavior.
echo ""
echo "==> Syncing Temporal-tagged Intl formatter test files..."

for formatter in "${INTL_FORMATTERS[@]}"; do
    src="$CLONE_DIR/test/intl402/$formatter"
    filtered="$CLONE_DIR/.temporal-tagged/$formatter"
    tagged="$CLONE_DIR/.temporal-tagged-$formatter.txt"

    if [[ ! -d "$src" ]]; then
        echo "    WARN: $formatter not found in upstream repo, skipping"
        continue
    fi

    # Upstream tags these with `features: [Temporal]` in the YAML frontmatter.
    (cd "$src" && grep -rl --include='*.js' -E '^features:.*\bTemporal\b' . | sed 's|^\./||' | sort) > "$tagged"

    mkdir -p "$filtered"
    rsync -rl --no-group --no-owner --files-from="$tagged" "$src/" "$filtered/"
    sync_tree "$filtered" "$INTL402_DIR/$formatter" "intl402/$formatter"
done

echo ""
echo "==> Done. Added: $total_added, Removed: $total_removed"

if [[ $total_changed -gt 0 ]]; then
    echo ""
    echo "Next steps:"
    echo "  1. Run: composer test262:build"
    echo "  2. Run: composer test262:run"
    echo "  3. Review and commit the changes"
fi
