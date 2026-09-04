# Changelog

All notable changes to this project are documented in this file. It is generated from
[Conventional Commits](https://www.conventionalcommits.org/) by
[release-please](https://github.com/googleapis/release-please), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Until 1.0.0 the public API may change between minor versions.

## [0.3.2](https://github.com/MidnightDesign/calendrics/compare/v0.3.1...v0.3.2) (2026-09-04)


### Bug Fixes

* negate directed rounding modes on Duration::round()'s pure-time path ([#124](https://github.com/MidnightDesign/calendrics/issues/124)) ([3b459dc](https://github.com/MidnightDesign/calendrics/commit/3b459dcdcae042321705dd02a1fb41ac8a82d121))
* reject a Duration seconds field that overflows int64 ([#127](https://github.com/MidnightDesign/calendrics/issues/127)) ([965b42a](https://github.com/MidnightDesign/calendrics/commit/965b42ad95cd0f6eb8551c3f61260cc98bd11bdf))
* reject a null options argument wherever TC39 does ([#153](https://github.com/MidnightDesign/calendrics/issues/153)) ([99453df](https://github.com/MidnightDesign/calendrics/commit/99453dff21f70743397cefef93528c8662767329))
* reject conflicting month and monthCode in non-ISO PlainMonthDay::with() ([#113](https://github.com/MidnightDesign/calendrics/issues/113)) ([ac8f599](https://github.com/MidnightDesign/calendrics/commit/ac8f599ffe518afa70540e6cfc1d5da2fb521b5b))
* reject unknown time zone identifiers in Instant::toString ([#97](https://github.com/MidnightDesign/calendrics/issues/97)) ([d6e3afc](https://github.com/MidnightDesign/calendrics/commit/d6e3afcbda84dee6c381424f07b43da581f822f7))
* resolve PlainYearMonth's largestUnit 'auto' before ranking it ([#120](https://github.com/MidnightDesign/calendrics/issues/120)) ([42d7142](https://github.com/MidnightDesign/calendrics/commit/42d714279dbcabe46e75c6e1c0ab2b00e03b2e46))


### Code Refactoring

* catch up with the current Mago analyzer ([#101](https://github.com/MidnightDesign/calendrics/issues/101)) ([6c6a383](https://github.com/MidnightDesign/calendrics/commit/6c6a383ab1009a13657212820e37b2f797271018))
* delete the write-only _locale option-bag key ([#112](https://github.com/MidnightDesign/calendrics/issues/112)) ([604ef15](https://github.com/MidnightDesign/calendrics/commit/604ef15440d66f2c14e2312f44cb279f26017f9b))
* mark the Plain toLocaleString types with an interface ([#111](https://github.com/MidnightDesign/calendrics/issues/111)) ([0575131](https://github.com/MidnightDesign/calendrics/commit/057513137505397c73868aceae901446c8331e08)), closes [#105](https://github.com/MidnightDesign/calendrics/issues/105)
* replace the locale component mode string with an internal enum ([#110](https://github.com/MidnightDesign/calendrics/issues/110)) ([fc32a4c](https://github.com/MidnightDesign/calendrics/commit/fc32a4cc6506be878de6dd581ad1b53bd4e066fd))
* route the ISO calendar through the calendar protocol ([#108](https://github.com/MidnightDesign/calendrics/issues/108)) ([e4de2f5](https://github.com/MidnightDesign/calendrics/commit/e4de2f5b2ffdd8a9d7e9323c94670f3fdfc7e8fb))
* split the Plain-type toLocaleString seam out of HasStringRepresentations ([#107](https://github.com/MidnightDesign/calendrics/issues/107)) ([3cfe9b7](https://github.com/MidnightDesign/calendrics/commit/3cfe9b7806173c98a62e56c05a750d822cacd29b)), closes [#68](https://github.com/MidnightDesign/calendrics/issues/68)
* state the month-branch invariant without naming the rank check ([#128](https://github.com/MidnightDesign/calendrics/issues/128)) ([10946f3](https://github.com/MidnightDesign/calendrics/commit/10946f36a531d351edfb0153c4a1a4f6853640c6)), closes [#119](https://github.com/MidnightDesign/calendrics/issues/119)


### Documentation

* state which layer gets which tests ([#131](https://github.com/MidnightDesign/calendrics/issues/131)) ([44b7e08](https://github.com/MidnightDesign/calendrics/commit/44b7e083facf928a7b8ef283f956bd7559980904))
* warn that tests/Test262/data is a subset of test262 ([#156](https://github.com/MidnightDesign/calendrics/issues/156)) ([669e996](https://github.com/MidnightDesign/calendrics/commit/669e996f0699976f45f953ef90942d75d5ca75ab))


### Tests

* delete the legacy hand-written spec-layer tests ([#141](https://github.com/MidnightDesign/calendrics/issues/141)) ([1ef3451](https://github.com/MidnightDesign/calendrics/commit/1ef3451f6d69fd91ae42b639d8b75af84e703fa6))
* drop the empty catch-all PHPUnit suite ([#139](https://github.com/MidnightDesign/calendrics/issues/139)) ([273b557](https://github.com/MidnightDesign/calendrics/commit/273b557543b01065e1af4be4bbe50f8fcdbfbc90))


### Build System

* raise the container's memory_limit so `composer check` can run ([#109](https://github.com/MidnightDesign/calendrics/issues/109)) ([6ebb6ee](https://github.com/MidnightDesign/calendrics/commit/6ebb6ee0873f31c740b7396d2986affc3c6ba311))
* raise the container's memory_limit to 512M ([6ebb6ee](https://github.com/MidnightDesign/calendrics/commit/6ebb6ee0873f31c740b7396d2986affc3c6ba311)), closes [#106](https://github.com/MidnightDesign/calendrics/issues/106)
* run the php container as the developer's uid ([#100](https://github.com/MidnightDesign/calendrics/issues/100)) ([1ca223f](https://github.com/MidnightDesign/calendrics/commit/1ca223f8cf0facb996b1887cf1819c4595be2f47))
* type DateTimeZone::listIdentifiers() as returning non-empty strings ([#99](https://github.com/MidnightDesign/calendrics/issues/99)) ([c77e057](https://github.com/MidnightDesign/calendrics/commit/c77e057c5d3b9ea7ceaccef16f18e92531a49573))


### Continuous Integration

* show refactor, docs, test, build, and ci commits in the changelog ([#116](https://github.com/MidnightDesign/calendrics/issues/116)) ([b773de3](https://github.com/MidnightDesign/calendrics/commit/b773de315af53b65bfba5b2c543d295c9cf63e5a))


### Miscellaneous Chores

* **deps-dev:** update infection/infection requirement || ^0.35 ([309ab76](https://github.com/MidnightDesign/calendrics/commit/309ab7640095ffba5baab01e5db59cf2a87d46b1))
* **deps-dev:** update infection/infection requirement from ^0.32 to ^0.32 || ^0.35 ([#96](https://github.com/MidnightDesign/calendrics/issues/96)) ([309ab76](https://github.com/MidnightDesign/calendrics/commit/309ab7640095ffba5baab01e5db59cf2a87d46b1))
* **deps:** bump actions/checkout from 6 to 7 ([#35](https://github.com/MidnightDesign/calendrics/issues/35)) ([a4573ae](https://github.com/MidnightDesign/calendrics/commit/a4573ae8e0b93b5fbb3aa221ac446126e0b3c596))

## [0.3.1](https://github.com/MidnightDesign/calendrics/compare/v0.3.0...v0.3.1) (2026-08-18)


### Miscellaneous Chores

* add package discovery metadata ([#93](https://github.com/MidnightDesign/calendrics/issues/93)) ([b0b7867](https://github.com/MidnightDesign/calendrics/commit/b0b7867fb55f9dd8763d0b9d927a95dffc059b24))

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
