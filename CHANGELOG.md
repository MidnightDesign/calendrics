# Changelog

All notable changes to this project are documented in this file. It is generated from
[Conventional Commits](https://www.conventionalcommits.org/) by
[release-please](https://github.com/googleapis/release-please), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Until 1.0.0 the public API may change between minor versions.

## [0.3.3](https://github.com/MidnightDesign/calendrics/compare/v0.3.2...v0.3.3) (2026-10-03)


### Bug Fixes

* bubble expanded calendar date-time differences ([#249](https://github.com/MidnightDesign/calendrics/issues/249)) ([608dbc1](https://github.com/MidnightDesign/calendrics/commit/608dbc10f5d0dccfbf0523799c2162e913c98c9b))
* handle long differences and automatic units ([#194](https://github.com/MidnightDesign/calendrics/issues/194)) ([87ed6a4](https://github.com/MidnightDesign/calendrics/commit/87ed6a47f5cf7d8d37edfc0f77ebcf45c43354f9))
* include whole days in date-time rounding parity ([#252](https://github.com/MidnightDesign/calendrics/issues/252)) ([3cad79b](https://github.com/MidnightDesign/calendrics/commit/3cad79b05171fe7fb8fffc3ee3b44dd20275dea5))
* invert corrected Chinese leap-month fields ([55114d6](https://github.com/MidnightDesign/calendrics/commit/55114d6288fe97432dfd9b456b5c73fb777eeb27))
* invert corrected Chinese leap-month fields during date arithmetic ([#222](https://github.com/MidnightDesign/calendrics/issues/222)) ([55114d6](https://github.com/MidnightDesign/calendrics/commit/55114d6288fe97432dfd9b456b5c73fb777eeb27))
* normalize Intl boolean and fractional-second options ([#227](https://github.com/MidnightDesign/calendrics/issues/227)) ([28bc5e3](https://github.com/MidnightDesign/calendrics/commit/28bc5e3aa69af865b31a60294f56f8ce457eebe7))
* preserve calendar anchors in duration totals and rounding ([#240](https://github.com/MidnightDesign/calendrics/issues/240)) ([6d3e4a3](https://github.com/MidnightDesign/calendrics/commit/6d3e4a3d150c5a01034dcdc0aa5ceb2cff22e035))
* preserve calendar years when rounding month differences ([#243](https://github.com/MidnightDesign/calendrics/issues/243)) ([6e8f60e](https://github.com/MidnightDesign/calendrics/commit/6e8f60ed5b919082f83d0064f42e893353195772))
* preserve fractional inline offsets in ZonedDateTime parsing ([#236](https://github.com/MidnightDesign/calendrics/issues/236)) ([eb35b79](https://github.com/MidnightDesign/calendrics/commit/eb35b79d265dc36c1b97572c8d3532a9fe209145))
* preserve fractional ZonedDateTime inline offsets ([eb35b79](https://github.com/MidnightDesign/calendrics/commit/eb35b79d265dc36c1b97572c8d3532a9fe209145))
* preserve full-range timestamps across PHP API conversions ([#214](https://github.com/MidnightDesign/calendrics/issues/214)) ([2b51017](https://github.com/MidnightDesign/calendrics/commit/2b5101793d2e9174e2e4055eeadfd175af70b523))
* reject large lower units in year-month arithmetic ([#217](https://github.com/MidnightDesign/calendrics/issues/217)) ([4c312c1](https://github.com/MidnightDesign/calendrics/commit/4c312c12d216e911f437ec244cb8b09bf7c7b47f))
* resolve Chinese calendar boundaries consistently ([#223](https://github.com/MidnightDesign/calendrics/issues/223)) ([dd1bba8](https://github.com/MidnightDesign/calendrics/commit/dd1bba89a181c25afcc28a0dd815d445cb645d50))
* resolve corrected Chinese month boundaries consistently ([dd1bba8](https://github.com/MidnightDesign/calendrics/commit/dd1bba89a181c25afcc28a0dd815d445cb645d50))
* retain receiver order in calendar date-time differences ([#248](https://github.com/MidnightDesign/calendrics/issues/248)) ([bf62338](https://github.com/MidnightDesign/calendrics/commit/bf62338430382a449a1089a8288260ae16c0aae0))
* round calendar differences against exact increment boundaries ([#201](https://github.com/MidnightDesign/calendrics/issues/201)) ([8eebf11](https://github.com/MidnightDesign/calendrics/commit/8eebf1125b3bc3e5c58a5092a825b9b32a49d56f))
* total large calendar-relative durations exactly ([#241](https://github.com/MidnightDesign/calendrics/issues/241)) ([852a99e](https://github.com/MidnightDesign/calendrics/commit/852a99ea11ad98f1ab6e53f6526d8c69ae2a3a2c))
* use proleptic Gregorian calendar arithmetic at all years ([#221](https://github.com/MidnightDesign/calendrics/issues/221)) ([4b46bbe](https://github.com/MidnightDesign/calendrics/commit/4b46bbed61441a4c6ef9dfd74e5d86de0bfb99d4))
* validate numeric offset ranges in plain date strings ([#230](https://github.com/MidnightDesign/calendrics/issues/230)) ([c3feda6](https://github.com/MidnightDesign/calendrics/commit/c3feda6f516d3ca1c136dcbffdebac90331f526a))
* validate zoned string components before resolution ([f066080](https://github.com/MidnightDesign/calendrics/commit/f066080db75ad9680342ed627dbbfd4aa07784f1))
* validate ZonedDateTime string components before resolution ([#235](https://github.com/MidnightDesign/calendrics/issues/235)) ([f066080](https://github.com/MidnightDesign/calendrics/commit/f066080db75ad9680342ed627dbbfd4aa07784f1))


### Performance Improvements

* avoid year traversal for fixed-month calendars ([8083b6c](https://github.com/MidnightDesign/calendrics/commit/8083b6c30e121e810e8f2fcaaa333de0b11114ce))
* bound Hebrew month arithmetic by calendar cycles ([d1dca6a](https://github.com/MidnightDesign/calendrics/commit/d1dca6a4459a4b9e318ae0f9a25916d48f3cedd8))
* bound ISO calendar duration total counting ([#242](https://github.com/MidnightDesign/calendrics/issues/242)) ([d727864](https://github.com/MidnightDesign/calendrics/commit/d727864163d3c41ae8debc4e60c7c021c5a904ee))
* cache repeated ICU date pattern generation ([#208](https://github.com/MidnightDesign/calendrics/issues/208)) ([2d3daa6](https://github.com/MidnightDesign/calendrics/commit/2d3daa6038a1808e35767fba35fefb481648722c))
* calculate fixed-calendar month spans directly ([#210](https://github.com/MidnightDesign/calendrics/issues/210)) ([8083b6c](https://github.com/MidnightDesign/calendrics/commit/8083b6c30e121e810e8f2fcaaa333de0b11114ce))
* construct native dates from numeric timestamps ([#211](https://github.com/MidnightDesign/calendrics/issues/211)) ([3b5a920](https://github.com/MidnightDesign/calendrics/commit/3b5a9206a0f4aee48c2f0562cff376da43b118b9))
* construct native timestamps without string parsing ([3b5a920](https://github.com/MidnightDesign/calendrics/commit/3b5a9206a0f4aee48c2f0562cff376da43b118b9))
* reuse ordinary-day timezone resolution ([#213](https://github.com/MidnightDesign/calendrics/issues/213)) ([2f59f42](https://github.com/MidnightDesign/calendrics/commit/2f59f4263a3437dd67ddad7c8942ee2f6d31ecda))
* reuse start-of-day timezone resolution ([2f59f42](https://github.com/MidnightDesign/calendrics/commit/2f59f4263a3437dd67ddad7c8942ee2f6d31ecda))
* skip complete Hebrew calendar month cycles ([#209](https://github.com/MidnightDesign/calendrics/issues/209)) ([d1dca6a](https://github.com/MidnightDesign/calendrics/commit/d1dca6a4459a4b9e318ae0f9a25916d48f3cedd8))


### Documentation

* guarantee porcelain string and JSON round trips ([#196](https://github.com/MidnightDesign/calendrics/issues/196)) ([4a1bba3](https://github.com/MidnightDesign/calendrics/commit/4a1bba34dbe3e43b75cb2130b0d6afd5e19fa3e4))
* rebuild the README around adoption and practical usage ([#207](https://github.com/MidnightDesign/calendrics/issues/207)) ([ad309eb](https://github.com/MidnightDesign/calendrics/commit/ad309eb6a4554f74d9fe957cc66fb4a5c2c82029))


### Tests

* activate current-time instant conformance fixture ([#198](https://github.com/MidnightDesign/calendrics/issues/198)) ([34554ce](https://github.com/MidnightDesign/calendrics/commit/34554ce80b6ce7d09f13a26ad1aec548f1a4c147))
* activate Intl formatter callback and parts fixtures ([#200](https://github.com/MidnightDesign/calendrics/issues/200)) ([01fe351](https://github.com/MidnightDesign/calendrics/commit/01fe35185f43280ece645696f3a4c463565db755))
* activate non-lunisolar calendar consistency fixtures ([#203](https://github.com/MidnightDesign/calendrics/issues/203)) ([1265c8e](https://github.com/MidnightDesign/calendrics/commit/1265c8e111d9d72074d76d3630c8f7d82e72b663))
* activate resolved time zone formatting fixtures ([#202](https://github.com/MidnightDesign/calendrics/issues/202)) ([81f3bbf](https://github.com/MidnightDesign/calendrics/commit/81f3bbff0d395f56a3b73ba9fd5f18f71d4d856d))
* cover Intl fractional-second option validation ([#261](https://github.com/MidnightDesign/calendrics/issues/261)) ([724c04e](https://github.com/MidnightDesign/calendrics/commit/724c04ec241b0e4e5fa579e09c0d7b8a4e2c9254))
* cover shared locale formatting ([#195](https://github.com/MidnightDesign/calendrics/issues/195)) ([e2958e9](https://github.com/MidnightDesign/calendrics/commit/e2958e9ad8c0c439f8d3cd829b45d761f7a1a133))
* cover shared locale formatting with upstream literal assertions ([e2958e9](https://github.com/MidnightDesign/calendrics/commit/e2958e9ad8c0c439f8d3cd829b45d761f7a1a133))


### Miscellaneous Chores

* align calendar match layout with Mago 1.51 ([#193](https://github.com/MidnightDesign/calendrics/issues/193)) ([62eb54a](https://github.com/MidnightDesign/calendrics/commit/62eb54a76f21d6b1700b52c1321d4eed2d922065))
* centralize the porcelain mutation gate and document its scope ([41e424c](https://github.com/MidnightDesign/calendrics/commit/41e424c94d58303882b3fd53c6433f445a817eed))
* clarify and centralize the porcelain mutation gate ([#197](https://github.com/MidnightDesign/calendrics/issues/197)) ([41e424c](https://github.com/MidnightDesign/calendrics/commit/41e424c94d58303882b3fd53c6433f445a817eed))

## [0.3.2](https://github.com/MidnightDesign/calendrics/compare/v0.3.1...v0.3.2) (2026-09-26)


### Bug Fixes

* accept Temporal objects in withCalendar ([#34](https://github.com/MidnightDesign/calendrics/issues/34)) ([904a45f](https://github.com/MidnightDesign/calendrics/commit/904a45fa28b791183e2ff3116cd081e4f09afc4b))
* avoid allocating a Docker subnet per worktree ([#189](https://github.com/MidnightDesign/calendrics/issues/189)) ([f4cdb85](https://github.com/MidnightDesign/calendrics/commit/f4cdb8550acb662d24ca65fe658fc4a99b6af887))
* correct Temporal edge cases and remove redundant Spec code ([#175](https://github.com/MidnightDesign/calendrics/issues/175)) ([bc22422](https://github.com/MidnightDesign/calendrics/commit/bc22422fd29ae18d98998ab5094ae34fd2149a3a))
* honor locale-resolved numeric hour widths ([#174](https://github.com/MidnightDesign/calendrics/issues/174)) ([16d4a92](https://github.com/MidnightDesign/calendrics/commit/16d4a928d0dc59249c031126d35e1d9806e32f02))
* honor numeric locale hour widths ([16d4a92](https://github.com/MidnightDesign/calendrics/commit/16d4a928d0dc59249c031126d35e1d9806e32f02)), closes [#147](https://github.com/MidnightDesign/calendrics/issues/147)
* keep JS parentheses and rest bindings when transpiling test262 ([#157](https://github.com/MidnightDesign/calendrics/issues/157)) ([883267e](https://github.com/MidnightDesign/calendrics/commit/883267e89ea73af68c113ab4f4d3831c668bdcb6))
* match ICU hour widths without locale-specific overrides ([#191](https://github.com/MidnightDesign/calendrics/issues/191)) ([c15cebf](https://github.com/MidnightDesign/calendrics/commit/c15cebf5c0212ad63087e149f92ab0c02bbc1ecd))
* narrow an integer-valued Duration field to int on construction ([#140](https://github.com/MidnightDesign/calendrics/issues/140)) ([fc05eba](https://github.com/MidnightDesign/calendrics/commit/fc05ebace632af4841c901cc44dc65532fc6a527))
* negate directed rounding modes on Duration::round()'s pure-time path ([#124](https://github.com/MidnightDesign/calendrics/issues/124)) ([3b459dc](https://github.com/MidnightDesign/calendrics/commit/3b459dcdcae042321705dd02a1fb41ac8a82d121))
* pad the hour when toLocaleString() asks for hour: '2-digit' ([#138](https://github.com/MidnightDesign/calendrics/issues/138)) ([acf6157](https://github.com/MidnightDesign/calendrics/commit/acf61571f5901078e3d149e34e3a77705e3f8ff5)), closes [#122](https://github.com/MidnightDesign/calendrics/issues/122)
* preserve locale patterns for partial dates ([#166](https://github.com/MidnightDesign/calendrics/issues/166)) ([d27bd98](https://github.com/MidnightDesign/calendrics/commit/d27bd982a0ad0ecfe1dda5c7a5ba507258845654))
* reject a Duration seconds field that overflows int64 ([#127](https://github.com/MidnightDesign/calendrics/issues/127)) ([965b42a](https://github.com/MidnightDesign/calendrics/commit/965b42ad95cd0f6eb8551c3f61260cc98bd11bdf))
* reject a null options argument wherever TC39 does ([#153](https://github.com/MidnightDesign/calendrics/issues/153)) ([99453df](https://github.com/MidnightDesign/calendrics/commit/99453dff21f70743397cefef93528c8662767329))
* reject a null relativeTo whatever the duration's units ([#143](https://github.com/MidnightDesign/calendrics/issues/143)) ([c324e9e](https://github.com/MidnightDesign/calendrics/commit/c324e9e6cd9d5a7b614377d65fb75735f4d05edc)), closes [#126](https://github.com/MidnightDesign/calendrics/issues/126)
* reject conflicting month and monthCode in non-ISO PlainMonthDay::with() ([#113](https://github.com/MidnightDesign/calendrics/issues/113)) ([ac8f599](https://github.com/MidnightDesign/calendrics/commit/ac8f599ffe518afa70540e6cfc1d5da2fb521b5b))
* reject invalid toLocaleString options and stop dropping hourCycle ([#129](https://github.com/MidnightDesign/calendrics/issues/129)) ([49d49c1](https://github.com/MidnightDesign/calendrics/commit/49d49c1d99ded08c5948c60816078366bcdd3cc1))
* reject unknown time zone identifiers in Instant::toString ([#97](https://github.com/MidnightDesign/calendrics/issues/97)) ([d6e3afc](https://github.com/MidnightDesign/calendrics/commit/d6e3afcbda84dee6c381424f07b43da581f822f7))
* render pre-1582 and extreme-year dates correctly in toLocaleString() ([#159](https://github.com/MidnightDesign/calendrics/issues/159)) ([c7115d1](https://github.com/MidnightDesign/calendrics/commit/c7115d1c8b4e22847e571bc25d6b38c9c91beddd))
* resolve PlainYearMonth's largestUnit 'auto' before ranking it ([#120](https://github.com/MidnightDesign/calendrics/issues/120)) ([42d7142](https://github.com/MidnightDesign/calendrics/commit/42d714279dbcabe46e75c6e1c0ab2b00e03b2e46))
* resolve relativeTo bags through their calendar ([#162](https://github.com/MidnightDesign/calendrics/issues/162)) ([2141b3b](https://github.com/MidnightDesign/calendrics/commit/2141b3b294a5ebfea5b5832f37200b7c3370a687))
* total oversized subsecond duration fields ([#167](https://github.com/MidnightDesign/calendrics/issues/167)) ([00f751b](https://github.com/MidnightDesign/calendrics/commit/00f751b41718d0c2e99c31b541c4b170cd18976e)), closes [#149](https://github.com/MidnightDesign/calendrics/issues/149)


### Code Refactoring

* catch up with the current Mago analyzer ([#101](https://github.com/MidnightDesign/calendrics/issues/101)) ([6c6a383](https://github.com/MidnightDesign/calendrics/commit/6c6a383ab1009a13657212820e37b2f797271018))
* delete the write-only _locale option-bag key ([#112](https://github.com/MidnightDesign/calendrics/issues/112)) ([604ef15](https://github.com/MidnightDesign/calendrics/commit/604ef15440d66f2c14e2312f44cb279f26017f9b))
* mark the Plain toLocaleString types with an interface ([#111](https://github.com/MidnightDesign/calendrics/issues/111)) ([0575131](https://github.com/MidnightDesign/calendrics/commit/057513137505397c73868aceae901446c8331e08)), closes [#105](https://github.com/MidnightDesign/calendrics/issues/105)
* replace the locale component mode string with an internal enum ([#110](https://github.com/MidnightDesign/calendrics/issues/110)) ([fc32a4c](https://github.com/MidnightDesign/calendrics/commit/fc32a4cc6506be878de6dd581ad1b53bd4e066fd))
* route ISO calendar operations through protocol ([7cc7583](https://github.com/MidnightDesign/calendrics/commit/7cc75831286c3e1d2031d46eaadd904bcb923a58))
* route ISO operations through calendar protocol ([#168](https://github.com/MidnightDesign/calendrics/issues/168)) ([7cc7583](https://github.com/MidnightDesign/calendrics/commit/7cc75831286c3e1d2031d46eaadd904bcb923a58))
* route the ISO calendar through the calendar protocol ([#108](https://github.com/MidnightDesign/calendrics/issues/108)) ([e4de2f5](https://github.com/MidnightDesign/calendrics/commit/e4de2f5b2ffdd8a9d7e9323c94670f3fdfc7e8fb))
* share partial calendar field resolution ([#165](https://github.com/MidnightDesign/calendrics/issues/165)) ([d56ec22](https://github.com/MidnightDesign/calendrics/commit/d56ec227b81dd6d00e073a9d4b0f76fd04fa189a))
* split the Plain-type toLocaleString seam out of HasStringRepresentations ([#107](https://github.com/MidnightDesign/calendrics/issues/107)) ([3cfe9b7](https://github.com/MidnightDesign/calendrics/commit/3cfe9b7806173c98a62e56c05a750d822cacd29b)), closes [#68](https://github.com/MidnightDesign/calendrics/issues/68)
* state the month-branch invariant without naming the rank check ([#128](https://github.com/MidnightDesign/calendrics/issues/128)) ([10946f3](https://github.com/MidnightDesign/calendrics/commit/10946f36a531d351edfb0153c4a1a4f6853640c6)), closes [#119](https://github.com/MidnightDesign/calendrics/issues/119)


### Documentation

* state which layer gets which tests ([#131](https://github.com/MidnightDesign/calendrics/issues/131)) ([44b7e08](https://github.com/MidnightDesign/calendrics/commit/44b7e083facf928a7b8ef283f956bd7559980904))
* warn that tests/Test262/data is a subset of test262 ([#156](https://github.com/MidnightDesign/calendrics/issues/156)) ([669e996](https://github.com/MidnightDesign/calendrics/commit/669e996f0699976f45f953ef90942d75d5ca75ab))


### Tests

* delete the legacy hand-written spec-layer tests ([#141](https://github.com/MidnightDesign/calendrics/issues/141)) ([1ef3451](https://github.com/MidnightDesign/calendrics/commit/1ef3451f6d69fd91ae42b639d8b75af84e703fa6))
* drop the empty catch-all PHPUnit suite ([#139](https://github.com/MidnightDesign/calendrics/issues/139)) ([273b557](https://github.com/MidnightDesign/calendrics/commit/273b557543b01065e1af4be4bbe50f8fcdbfbc90))
* model the ECMA-402 constructor/format split in the Intl shim ([#158](https://github.com/MidnightDesign/calendrics/issues/158)) ([94f470f](https://github.com/MidnightDesign/calendrics/commit/94f470f3b6fa0eb3ea6b834e4d9b25d2c2b1091a))
* sync the Temporal-tagged Intl formatter fixtures ([#160](https://github.com/MidnightDesign/calendrics/issues/160)) ([5cbd117](https://github.com/MidnightDesign/calendrics/commit/5cbd11787e6b8ccaacd34506f436711d1cb9e2df))


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
* simplify tuple annotations ([#169](https://github.com/MidnightDesign/calendrics/issues/169)) ([09407c3](https://github.com/MidnightDesign/calendrics/commit/09407c316c3e4eb2217cfe0ff2d522f540dddb61))

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
