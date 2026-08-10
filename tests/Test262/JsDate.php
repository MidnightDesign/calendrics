<?php

declare(strict_types=1);

namespace Temporal\Tests\Test262;

use Temporal\Spec\Internal\IntlFormatter;

/**
 * Minimal stand-in for the legacy JavaScript `Date` object, as used by intl402
 * toLocaleString fixtures to cross-check Temporal formatting against legacy-Date
 * formatting of the same point in time.
 *
 * The harness's "local time zone" is UTC: test262 hosts run the suite in a pinned
 * zone, and this runner pins UTC (PHP and ICU defaults in the container). That
 * makes {@see getTimezoneOffset()} constant 0 and makes `toLocaleString()` format
 * the epoch value in UTC unless the options bag carries an explicit `timeZone`.
 *
 * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
 */
final class JsDate
{
    public function __construct(
        public readonly int|float $epochMilliseconds = 0,
    ) {}

    /** JS Date.prototype.getTime(). */
    public function getTime(): int|float
    {
        return $this->epochMilliseconds;
    }

    /**
     * JS Date.prototype.getTimezoneOffset() — minutes between UTC and local time.
     * The harness's local zone is UTC, so always 0.
     */
    public function getTimezoneOffset(): int
    {
        return 0;
    }

    /**
     * JS Date.prototype.toLocaleString ( [ locales [ , options ] ] ) — formats the
     * epoch value through the same IntlDateFormatter plumbing the spec layer uses,
     * so fixture comparisons exercise real ECMA-402 option handling on both sides.
     *
     * @param string|array<array-key, mixed>|null $locales
     * @param array<array-key, mixed>|object|null $options
     */
    public function toLocaleString(string|array|null $locales = null, array|object|null $options = null): string
    {
        $locale = IntlFormatter::resolveLocale($locales);
        /** @var array<string, mixed> $opts */
        $opts = is_object($options) ? get_object_vars($options) : $options ?? [];

        /** @var mixed $tzOpt */
        $tzOpt = $opts['timeZone'] ?? null;
        $timeZone = is_string($tzOpt) ? $tzOpt : 'UTC';

        $opts['_locale'] = $locale;
        $formatter = IntlFormatter::buildIntlFormatter($locale, $timeZone, $opts);
        $result = $formatter->format((float) $this->epochMilliseconds / 1000.0);
        if ($result === false) {
            throw new \RuntimeException('JsDate::toLocaleString(): IntlDateFormatter::format() failed.');
        }
        return $result;
    }
}
