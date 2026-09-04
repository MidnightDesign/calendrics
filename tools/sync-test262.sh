#!/usr/bin/env bash
#
# Sync test262 Temporal test files from the upstream tc39/test262 repository.
#
# Usage:
#   ./tools/sync-test262.sh            # sync all implemented classes
#   ./tools/sync-test262.sh Duration   # sync only Duration
#
# This does a sparse checkout of just the Temporal directories we need,
# then rsyncs them into tests/Test262/data/.

set -euo pipefail

REPO_URL="https://github.com/tc39/test262.git"
CLONE_DIR="$(mktemp -d)"
DATA_DIR="$(cd "$(dirname "$0")/../tests/Test262/data" && pwd)"

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

if [[ $SYNC_NON_CLASS_AREAS == true ]]; then
    SPARSE_PATHS+=("test/staging/Temporal")
fi

git sparse-checkout set "${SPARSE_PATHS[@]}" 2>/dev/null

echo "==> Syncing test files..."

total_added=0
total_removed=0

for class in "${CLASSES[@]}"; do
    src="$CLONE_DIR/test/built-ins/Temporal/$class"
    dst="$DATA_DIR/$class"

    if [[ ! -d "$src" ]]; then
        echo "    WARN: $class not found in upstream repo, skipping"
        continue
    fi

    # Count files before
    before=$(find "$dst" -name '*.js' 2>/dev/null | wc -l)

    # Sync: add new files, update changed files, remove files deleted upstream
    mkdir -p "$dst"
    rsync -rl --no-group --no-owner --delete --include='*/' --include='*.js' --exclude='*' "$src/" "$dst/"

    # Count files after
    after=$(find "$dst" -name '*.js' | wc -l)

    added=$((after - before))
    if [[ $added -gt 0 ]]; then
        echo "    $class: $before -> $after (+$added)"
        total_added=$((total_added + added))
    elif [[ $added -lt 0 ]]; then
        removed=$((-added))
        echo "    $class: $before -> $after (-$removed removed)"
        total_removed=$((total_removed + removed))
    else
        echo "    $class: $after (up to date)"
    fi
done

# Sync intl402 tests into a separate directory
INTL402_DIR="$DATA_DIR/intl402"

echo ""
echo "==> Syncing intl402 test files..."

intl402_added=0
intl402_removed=0

for class in "${CLASSES[@]}"; do
    src="$CLONE_DIR/test/intl402/Temporal/$class"
    dst="$INTL402_DIR/$class"

    if [[ ! -d "$src" ]]; then
        # intl402 tests don't exist for all classes
        continue
    fi

    # Skip if no .js files in source
    src_count=$(find "$src" -name '*.js' 2>/dev/null | wc -l)
    if [[ $src_count -eq 0 ]]; then
        continue
    fi

    # Count files before
    if [[ -d "$dst" ]]; then
        before=$(find "$dst" -name '*.js' | wc -l)
    else
        before=0
    fi

    mkdir -p "$dst"
    rsync -rl --no-group --no-owner --delete --include='*/' --include='*.js' --exclude='*' "$src/" "$dst/"

    after=$(find "$dst" -name '*.js' | wc -l)

    added=$((after - before))
    if [[ $added -gt 0 ]]; then
        echo "    intl402/$class: $before -> $after (+$added)"
        intl402_added=$((intl402_added + added))
    elif [[ $added -lt 0 ]]; then
        removed=$((-added))
        echo "    intl402/$class: $before -> $after (-$removed removed)"
        intl402_removed=$((intl402_removed + removed))
    else
        echo "    intl402/$class: $after (up to date)"
    fi
done

total_added=$((total_added + intl402_added))
total_removed=$((total_removed + intl402_removed))

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
# Deliberately NOT synced:
#
#   test/built-ins/Temporal/*.js (top level: getOwnPropertyNames.js, keys.js,
#     prop-desc.js)
#     All three assert JS object-model facts about the `Temporal` namespace
#     object: which own property names it has, that it exposes no enumerable
#     properties, and the writable/enumerable/configurable attributes of the
#     global `Temporal` property. A PHP namespace has no property table, so
#     none of the three can ever run — they transpile to Assert::incomplete().
#
#   test/intl402/DurationFormat/
#     Intl.DurationFormat is a separate API this library does not implement:
#     ext-intl exposes no equivalent, and Duration::toLocaleString() is a
#     documented toString() passthrough. All 110 fixtures build a
#     `new Intl.DurationFormat(...)`, which the transpiler cannot lower, so they
#     would land as 110 permanent incompletes.
#
#   test/intl402/DateTimeFormat/
#     Not yet enabled here. To turn it on, add "test/intl402/DateTimeFormat" to
#     SPARSE_PATHS above and rsync it to "$DATA_DIR/intl402/DateTimeFormat".

if [[ $SYNC_NON_CLASS_AREAS == true ]]; then
    echo ""
    echo "==> Syncing staging test files..."

    src="$CLONE_DIR/test/staging/Temporal"
    dst="$DATA_DIR/staging"

    if [[ ! -d "$src" ]]; then
        echo "    WARN: test/staging/Temporal not found in upstream repo, skipping"
    else
        if [[ -d "$dst" ]]; then
            before=$(find "$dst" -name '*.js' | wc -l)
        else
            before=0
        fi

        mkdir -p "$dst"
        rsync -rl --no-group --no-owner --delete --include='*/' --include='*.js' --exclude='*' "$src/" "$dst/"

        after=$(find "$dst" -name '*.js' | wc -l)

        added=$((after - before))
        if [[ $added -gt 0 ]]; then
            echo "    staging: $before -> $after (+$added)"
            total_added=$((total_added + added))
        elif [[ $added -lt 0 ]]; then
            removed=$((-added))
            echo "    staging: $before -> $after (-$removed removed)"
            total_removed=$((total_removed + removed))
        else
            echo "    staging: $after (up to date)"
        fi
    fi
fi

echo ""
echo "==> Done. Added: $total_added, Removed: $total_removed"

if [[ $total_added -gt 0 || $total_removed -gt 0 ]]; then
    echo ""
    echo "Next steps:"
    echo "  1. Run: composer test262:build"
    echo "  2. Run: composer test262:run"
    echo "  3. Review and commit the changes"
fi
