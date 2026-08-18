# Changelog

All notable changes to this project are documented in this file. It is generated from
[Conventional Commits](https://www.conventionalcommits.org/) by
[release-please](https://github.com/googleapis/release-please), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Until 1.0.0 the public API may change between minor versions.

## [0.3.0](https://github.com/MidnightDesign/temporal-php/compare/v0.2.1...v0.3.0) (2026-08-18)


### ⚠ BREAKING CHANGES

* `Temporal\` is now `Calendrics\`, including `Temporal\Spec\` → `Calendrics\Spec\` and `Temporal\Exception\` → `Calendrics\Exception\`. The marker interface `Temporal\Exception\TemporalException` is now `Calendrics\Exception\CalendricsException`. Require `midnight/calendrics` in place of `midnight/temporal-php`.

### Features

* rename the package and root namespace to calendrics ([#91](https://github.com/MidnightDesign/temporal-php/issues/91)) ([0ad2c31](https://github.com/MidnightDesign/temporal-php/commit/0ad2c31bbd668089c9b8f4107ab16c6a38371085))

## [0.2.1](https://github.com/MidnightDesign/temporal-php/compare/v0.2.0...v0.2.1) (2026-08-17)


### Bug Fixes

* accept basic-format inline offsets in date-time-string time zone ids ([#89](https://github.com/MidnightDesign/temporal-php/issues/89)) ([056f2ba](https://github.com/MidnightDesign/temporal-php/commit/056f2bac80bd731abb6a1b22fbf04dc7dd8acdd4))
* range-check Duration::round()'s relativeTo anchor for every spelling ([#82](https://github.com/MidnightDesign/temporal-php/issues/82)) ([9099d86](https://github.com/MidnightDesign/temporal-php/commit/9099d8649118379ef69f8de686b1b63dbcaa96e1)), closes [#56](https://github.com/MidnightDesign/temporal-php/issues/56)
* resolve era/eraYear relativeTo anchors instead of rejecting them ([#84](https://github.com/MidnightDesign/temporal-php/issues/84)) ([80d60f8](https://github.com/MidnightDesign/temporal-php/commit/80d60f8b8b82c0b154b1ce4dc3bef16402c53da3)), closes [#58](https://github.com/MidnightDesign/temporal-php/issues/58)


### Miscellaneous Chores

* adopt release-please for versioning and changelog ([#85](https://github.com/MidnightDesign/temporal-php/issues/85)) ([07249e2](https://github.com/MidnightDesign/temporal-php/commit/07249e267f0569b375c4752d965314746ab00f88))

## [0.2.0](https://github.com/MidnightDesign/temporal-php/compare/v0.1.0...v0.2.0) (2026-08-17)


### ⚠ BREAKING CHANGES

* The eight spec-layer types no longer expose `valueOf()`. PHP has no language hook equivalent to JS `ToPrimitive`, so a throw-only `valueOf()` could not guard the operators it existed for; it was dead surface, callable only explicitly.
* `toLocaleString()` now throws `Temporal\Exception\RangeError` unless the value's calendar matches the formatter's resolved calendar. `PlainYearMonth` and `PlainMonthDay` no longer format against a mismatched calendar, where they previously returned a Gregorian rendering.

### Features

* add native DateTimeImmutable interop on porcelain value types ([#31](https://github.com/MidnightDesign/temporal-php/issues/31)) ([54d4aeb](https://github.com/MidnightDesign/temporal-php/commit/54d4aebf82dc6c810d8ac2be51760ad5a6bbfa74))
* add Temporal\Exception\* hierarchy ([#30](https://github.com/MidnightDesign/temporal-php/issues/30)) ([0970162](https://github.com/MidnightDesign/temporal-php/commit/097016299d177316274d0e43ac65c5aed65fae7b))
* add typed toLocaleString() to the porcelain layer ([#43](https://github.com/MidnightDesign/temporal-php/issues/43)) ([ee33ebd](https://github.com/MidnightDesign/temporal-php/commit/ee33ebdc792ca493ce3f112d7b3c47732dd9dc4f))


### Bug Fixes

* break halfEven ties on the parity of the whole value, not the sub-second part ([#77](https://github.com/MidnightDesign/temporal-php/issues/77)) ([9e2d542](https://github.com/MidnightDesign/temporal-php/commit/9e2d542d57385fb3aa83f7284a994dd3a4110d04))
* carry true epoch parts for every instant, not only clamped ones ([#83](https://github.com/MidnightDesign/temporal-php/issues/83)) ([a7b1367](https://github.com/MidnightDesign/temporal-php/commit/a7b1367c6382a3b2cfc07d8c7150a3653261d097))
* dayOfWeek before 1 CE, calendar handling in toLocaleString, and getTimeZoneTransition fall-through ([#40](https://github.com/MidnightDesign/temporal-php/issues/40)) ([0b7fbae](https://github.com/MidnightDesign/temporal-php/commit/0b7fbae9b83049224c62f1e4032aec3a0875b3f5))
* raise test262 coverage: unlock 1,428 fixtures, fix the spec bugs exposed ([#33](https://github.com/MidnightDesign/temporal-php/issues/33)) ([7ee1345](https://github.com/MidnightDesign/temporal-php/commit/7ee134560ddb45f9f8b42775166db3e67568014c))
* read property bags with a faithful Get(O, P) instead of get_object_vars() ([#44](https://github.com/MidnightDesign/temporal-php/issues/44)) ([7aef204](https://github.com/MidnightDesign/temporal-php/commit/7aef20437787fd6f0a70fdb118208c2036246344))
* round Duration time totals exactly instead of through float64 ([#75](https://github.com/MidnightDesign/temporal-php/issues/75)) ([4a5a24b](https://github.com/MidnightDesign/temporal-php/commit/4a5a24b848f3383e4ead44655fbf759dade4f646))
* satisfy Mago 1.25 on IntlCalendar property assignment ([#25](https://github.com/MidnightDesign/temporal-php/issues/25)) ([55c43be](https://github.com/MidnightDesign/temporal-php/commit/55c43be2a11d59c8397836fdde3478ba2b851dab))
* surface and clear 76 hidden test262 spec deviations ([#27](https://github.com/MidnightDesign/temporal-php/issues/27)) ([4b6f261](https://github.com/MidnightDesign/temporal-php/commit/4b6f261719dec4909175392b930edb184b812ddf))
* toLocaleString's sub-second output and ZonedDateTime's default zone name ([#79](https://github.com/MidnightDesign/temporal-php/issues/79)) ([32144c5](https://github.com/MidnightDesign/temporal-php/commit/32144c511612ca490d2e67990fbfce145223f2ab))
* total Duration time fields exactly instead of through float64 ([#78](https://github.com/MidnightDesign/temporal-php/issues/78)) ([fcd3083](https://github.com/MidnightDesign/temporal-php/commit/fcd30837348b700e9a60be5d0e546557ca246217))


### Performance Improvements

* memoize hot paths in calendar/timezone code ([#20](https://github.com/MidnightDesign/temporal-php/issues/20)) ([8fd5bd1](https://github.com/MidnightDesign/temporal-php/commit/8fd5bd1df62d0ce281ccaafb5c6734b6a8250f8d))


### Miscellaneous Chores

* stop tracking build/coverage artifacts ([#10](https://github.com/MidnightDesign/temporal-php/issues/10)) ([58f5ed3](https://github.com/MidnightDesign/temporal-php/commit/58f5ed3cdfc8419567d155954f10d834c2ffa12a))


### Code Refactoring

* drop valueOf() from spec-layer types ([#24](https://github.com/MidnightDesign/temporal-php/issues/24)) ([c70747c](https://github.com/MidnightDesign/temporal-php/commit/c70747cb07f63fad8f77b51d7ddd2bb8f4291c58))

## 0.1.0 (2026-04-19)


### Features

* add ECMA-402 non-ISO calendar support with porcelain Calendar enum ([#3](https://github.com/MidnightDesign/temporal-php/issues/3)) ([64120ed](https://github.com/MidnightDesign/temporal-php/commit/64120ed936f27d9cb41fbf75a9aa4bf5b9802beb))
* add PHP-idiomatic porcelain API over TC39 spec layer ([69e0d4e](https://github.com/MidnightDesign/temporal-php/commit/69e0d4ee58ab5a5591e213bb107faddbd7418a2c))
* add PlainDate add()/subtract() methods and tests (1033 tests) ([0439cd5](https://github.com/MidnightDesign/temporal-php/commit/0439cd540f0658d00a32aec91b709c9a7db5a0c8))
* add PlainDate since()/until() methods and tests (1037 tests) ([015239e](https://github.com/MidnightDesign/temporal-php/commit/015239e4a19d07b7b2b9bd0164a8d67c2c0073d0))
* add PlainDate with() method + more test262 conformance tests (1030) ([1d69399](https://github.com/MidnightDesign/temporal-php/commit/1d6939950db2f6bd488f578c5abad54976766a8b))
* add PlainDateTime implementation with full test262 conformance ([e3f1671](https://github.com/MidnightDesign/temporal-php/commit/e3f1671934cdfb56e8870f691bf2b4407588ca0d))
* add Temporal\Duration and expand Temporal\Instant with test262 conformance ([70b6255](https://github.com/MidnightDesign/temporal-php/commit/70b62553b6c1f1c4fb9eba17f2931c8b313c1d79))
* add toLocaleString() stubs to PlainDate, PlainDateTime, PlainYearMonth ([c323f2f](https://github.com/MidnightDesign/temporal-php/commit/c323f2f6aba93bae468e0a1e84174d157f1e84a9))
* finalize 0.1.0 porcelain factory surface ([#7](https://github.com/MidnightDesign/temporal-php/issues/7)) ([f4709f6](https://github.com/MidnightDesign/temporal-php/commit/f4709f6ef36726ff2d868ec115d722ab558a25b4))
* implement Duration::compare() and Duration::round() ([3fe824e](https://github.com/MidnightDesign/temporal-php/commit/3fe824e889fd7b6daab84cb4b72b87da061562d6))
* implement Instant add, subtract, round, since, until ([4e0d3ba](https://github.com/MidnightDesign/temporal-php/commit/4e0d3ba8e4fd523c610f5851b819696323263249))
* implement locale-aware toLocaleString() for Instant and ZonedDateTime via ext-intl ([1b18e19](https://github.com/MidnightDesign/temporal-php/commit/1b18e192365421019f9f4470f57eff852b6364c1))
* implement Now::plainDateTimeISO, Now::zonedDateTimeISO, ZonedDateTime::startOfDay, PlainDate::toPlainYearMonth/toPlainMonthDay ([97bda9b](https://github.com/MidnightDesign/temporal-php/commit/97bda9be16dac0bc151d8d163fc5851453378759))
* implement PlainDate core properties/methods + 21 test262 scripts (999 tests) ([644a1fc](https://github.com/MidnightDesign/temporal-php/commit/644a1fc1e797fa902fe96e59eca0e2e863ffe91d))
* implement PlainDate, Duration calendar rounding, and transpiler BigInt fixes (319→251) ([9e57372](https://github.com/MidnightDesign/temporal-php/commit/9e57372d4a1c72de79cf00ac95aea274b20f1b97))
* implement Temporal\Now with test262 coverage (36 new tests) ([fe40d37](https://github.com/MidnightDesign/temporal-php/commit/fe40d377a8872f889b4f7afc2c9dea64c7d1632d))
* implement Temporal\PlainMonthDay with full test262 conformance ([f0d0ed6](https://github.com/MidnightDesign/temporal-php/commit/f0d0ed6c0290d598f8799f295b45d41ebebe46bb))
* implement Temporal\PlainTime with full test262 coverage ([c65abb2](https://github.com/MidnightDesign/temporal-php/commit/c65abb269efaefa8b16f6247d67049e879800ac1))
* implement Temporal\PlainYearMonth with full test262 conformance (497 tests) ([a94069e](https://github.com/MidnightDesign/temporal-php/commit/a94069e34a63773f6bb858a37d3c7d5765020c97))
* implement Temporal\ZonedDateTime with full test262 conformance ([92dc9b0](https://github.com/MidnightDesign/temporal-php/commit/92dc9b01ef174d1cbf95de7e12ac85089a0c1f24))
* implement toLocaleString(), fix withPlainTime(null) distinction, 0 failures ([91484a2](https://github.com/MidnightDesign/temporal-php/commit/91484a2e7ddebe7842179eaebbd2ba226e601528))
* implement toZonedDateTimeISO, fix Psalm crashes, add PlainDate test262 coverage ([97ae6aa](https://github.com/MidnightDesign/temporal-php/commit/97ae6aaf599ac43374fbbf97be6b94d361e84018))
* initial implementation of Temporal\Instant ([6f44d2d](https://github.com/MidnightDesign/temporal-php/commit/6f44d2dc8c077070299eb2f8be485fe9dc58e183))
* sync test262 data, implement ZDT/PlainDate/PlainDateTime methods, fix rounding ([f8a1021](https://github.com/MidnightDesign/temporal-php/commit/f8a1021c4aa1a03cd2b233cbbfb1926b2f702165))


### Bug Fixes

* 6 test262 tests: map(), new Proxy(), skip JS globals, Duration fractional validation (327→321) ([9387a64](https://github.com/MidnightDesign/temporal-php/commit/9387a64c80bd6bfc17839049c3c6bd2d02bb71df))
* activate ZonedDateTime::from/compare test262 tests and fix edge cases ([6f52ddd](https://github.com/MidnightDesign/temporal-php/commit/6f52ddd7cf824f4770699020071af4644c7e8ac3))
* download 13 missing test262 files and fix calendar constructor validation ([a3ea298](https://github.com/MidnightDesign/temporal-php/commit/a3ea29848cbdfb3370ba792fa8c89b1ea93edac9))
* improve test262 conformance: transpiler + timezone/relativeTo fixes ([b49a626](https://github.com/MidnightDesign/temporal-php/commit/b49a626ead96cb7faa2bcf34e795dcd70b2df94d))
* TC39 test262 conformance: relativeTo validation and timezone string detection ([c74c309](https://github.com/MidnightDesign/temporal-php/commit/c74c309eb86131a2c40557b65da3097b960419ef))


### Miscellaneous Chores

* add MIT License ([7675068](https://github.com/MidnightDesign/temporal-php/commit/7675068b6b296766c856e85f86ff8d99ff75346c))
