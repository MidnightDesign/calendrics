# Compatibility and releases

[Back to README](../README.md)

## Public API policy

This project follows [Semantic Versioning](https://semver.org). Until 1.0.0 the public API may change between minor versions.

From 1.0.0 onward, both API layers are supported under the same contract:

- **Porcelain (`Calendrics\`, excluding `Calendrics\Internal\`)** — public methods, property names and types, enum cases, and constructor parameters are stable within a major version.
- **Spec (`Calendrics\Spec\`)** — same contract as porcelain. This layer tracks the TC39 Temporal specification; if an upstream Stage 4 change alters observable semantics, that change ships only in a major version of this library.
- **Seam** — for every porcelain class, `X::fromSpec($x->toSpec())` equals `$x` within a major version. You can move values between layers without lossy conversion.
- **Porcelain string and JSON serialization** — `(string) $value` and `json_encode($value)` output is stable within a major version, including default precision and calendar/time-zone annotations. Every parseable porcelain value implements `Stringable` and `JsonSerializable`, encoding as a JSON string. Its `parse()` factory continues to accept previously emitted default strings and decoded JSON strings within that major version. Bug fixes that correct incorrect serialization remain subject to the bug-fix exception below. Localized `toLocaleString()` output depends on ICU and locale data and is outside this format guarantee.
- **Exceptions (`Calendrics\Exception\`)** — every porcelain throw is a `Calendrics\Exception\CalendricsException` (marker interface) and also extends a stable SPL parent (e.g. `Calendrics\Exception\InvalidArgument extends \InvalidArgumentException`). The marker interface and the SPL parent of each concrete exception class are stable within a major version, so both `catch (CalendricsException)` and `catch (\InvalidArgumentException)` keep working. The spec layer throws through the same hierarchy; the only remaining bare SPL throws are internal invariant guards in `Calendrics\Spec\Internal\`, which are not reachable through the public API.
- **Internal (`Calendrics\Internal\`, `Calendrics\Spec\Internal\`)** — genuine implementation detail (porcelain adapters, calendar bridges, serde, arithmetic helpers). May change at any time without a major version bump. Do not import from it.

Bug fixes that correct incorrect output are not breaking changes, even when an observed value changes. Deprecations are announced in the changelog at least one minor version before removal and marked with `@deprecated`.

### Releases

Releases are automated with [release-please](https://github.com/googleapis/release-please). Pull requests are squash-merged, so the pull request title becomes the commit subject release-please reads — it has to be a [Conventional Commit](https://www.conventionalcommits.org/):

| Prefix | Changelog section | Bump below 1.0.0 |
|--------|-------------------|------------------|
| `feat:` | Features | minor |
| `fix:` | Bug Fixes | patch |
| `perf:` | Performance Improvements | patch |
| `revert:` | Reverts | patch |
| `chore:` | Miscellaneous Chores | patch |
| `refactor:` | Code Refactoring | patch |
| `docs:` | Documentation | patch |
| `test:` | Tests | patch |
| `build:` | Build System | patch |
| `ci:` | Continuous Integration | patch |
| `style:` | not listed | — |
| `feat!:`, or any type with a `BREAKING CHANGE:` footer | ⚠ BREAKING CHANGES | minor |

Breaking changes bump the minor version while the project is below 1.0.0, matching the policy above; from 1.0.0 they bump the major. The table reflects the configured changelog sections; it does not promise a release for changes hidden from the changelog.

After release-eligible changes are merged to `master`, release-please opens or updates a release pull request that accumulates the pending changelog. Nothing ships until that pull request is merged — then release-please writes `CHANGELOG.md`, tags `vX.Y.Z`, and publishes the GitHub release. Packagist publishes from the tag.

## PHP and Temporal differences

The PHP-oriented API uses `parse()` and typed `fromFields()` factories, named arguments, and backed enums in place of polymorphic input and option bags. The `Calendrics\Spec\` layer remains public, but PHP cannot reproduce every JavaScript coercion or operator hook.

- Spec types do not expose JavaScript's throw-only `valueOf()`. Use explicit `compare()`/`equals()` methods; PHP object operators have different semantics.
- Instant differences retain exact int64 duration fields where JavaScript may narrow to float64. Duration arithmetic/rounding and PlainDateTime differences can instead use float64-representable fields. These are distinct behaviors, not a blanket precision guarantee.
- Public integer epoch arguments and properties are bounded by PHP integers. Wider internal parsed representations do not make `epochNanoseconds` an arbitrary-precision value. Native date-time interoperability also uses integer epochs and microsecond precision.
- Localized output depends on ICU data and is excluded from the stable serialization-format promise. Unsupported JavaScript/harness conformance cases remain explicitly incomplete.

See the [usage guide](usage.md) for the public API shapes and [contributor guide](../CONTRIBUTING.md) for test coverage limits.
### Timestamp range limits

On 64-bit PHP, the PHP-oriented `Instant` and `ZonedDateTime` APIs use an integer nanosecond epoch, covering roughly September 1677 through April 2262. Keep inputs and arithmetic results within that range.

The Spec layer can parse and retain timestamps beyond that range, but its public `epochNanoseconds` property clamps to `PHP_INT_MIN` or `PHP_INT_MAX`. The PHP-oriented `parse()` and `fromSpec()` factories currently rebuild the value from that integer property: an out-of-range timestamp can therefore become a boundary date instead of being rejected. Do not use these factories for wider timestamps. Plain calendar values such as `PlainDate` do not have this nanosecond-epoch restriction.

`Instant::fromDateTime()` and `ZonedDateTime::fromDateTime()` reject native timestamps outside their integer-nanosecond conversion range. Their `toDateTime()` methods also use integer nanoseconds and reduce precision to microseconds. This is a limit of Calendrics' conversion path; PHP's native `DateTimeImmutable` can represent dates outside this range. Plain-type `fromDateTime()` factories read calendar fields directly.
