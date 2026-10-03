# test262 data

All files under this directory are **exact, unmodified copies** from the
[TC39 test262 repository](https://github.com/tc39/test262).

## Where files come from

`tools/sync-test262.sh` mirrors three upstream trees here, dropping the
redundant `Temporal` path segment in the latter two:

| upstream path | mirrored to |
|---|---|
| `test/built-ins/Temporal/<Class>/…` | `<Class>/…` |
| `test/intl402/Temporal/<Class>/…` | `intl402/<Class>/…` |
| `test/staging/Temporal/…` | `staging/…` |

The script's comments record which further upstream areas are deliberately
out of scope, and why.

## Rules

- **No custom test files.** Every `.js` file here must be a verbatim copy of
  the corresponding file in `tc39/test262`. Do not write, modify, simplify, or
  approximate test262 content.
- **No extra files.** If a file does not exist in the upstream repo, it must
  not exist here.
- **Directory structure must match** the upstream layout exactly, under the
  mapping above (`PlainDate/compare/`, `PlainDate/from/`,
  `PlainDate/prototype/…`, etc.).
- **Do not edit file contents** for any reason — not to simplify, not to fix
  assumptions, not to match our implementation. If a test is not passing, fix
  the implementation, not the test.

## How to add files

1. Find the file in `https://github.com/tc39/test262/tree/main/test/`
2. Copy the raw content verbatim (including copyright header and `/*--- ---*/` frontmatter).
3. Place it at the relative path the mapping above gives.
4. Run `composer test262:build` to regenerate the PHP scripts.
