<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\Calendar\IntlCalendarFactory;

/** @internal */
final class LocaleList
{
    /** @param string|array<array-key, mixed>|null $locales */
    public static function resolve(string|array|null $locales): string
    {
        $requested = [];
        foreach (is_string($locales) ? [$locales] : $locales ?? [] as $locale) {
            if (!is_string($locale) && !$locale instanceof \Stringable) {
                throw new TypeError('Locale list entries must be strings or stringable objects.');
            }
            $identifier = LocaleIdentifier::from((string) $locale);
            $requested[$identifier->tag] ??= $identifier;
        }
        // Canonicalize the whole list before a supported first entry can win.
        if ($requested === []) {
            return \Locale::getDefault();
        }
        $available = self::availableLocales();
        foreach ($requested as $request) {
            $candidate = $request->baseName;
            while ($candidate !== '') {
                if (isset($available[$candidate])) {
                    return self::withKeywords($available[$candidate], $request->keywords);
                }
                $separator = strrpos($candidate, needle: '-');
                $candidate = $separator === false ? '' : substr($candidate, offset: 0, length: $separator);
            }
        }
        return \Locale::getDefault();
    }

    /**
     * Canonical keys retain the original installed ICU spelling as their values.
     * CLDR alias upgrades therefore do not hide locales supplied by older ICU data.
     *
     * @return array<string, string>
     */
    private static function availableLocales(): array
    {
        /** @var array<string, string>|null $available */
        static $available = null;
        if ($available !== null) {
            return $available;
        }
        /** @var list<string>|false $locales ICU enumerates locale identifier strings. */
        $locales = \ResourceBundle::getLocales('');
        if ($locales === false) {
            throw new \RuntimeException('ICU could not enumerate its available locales.');
        }
        sort($locales, SORT_STRING);
        $available = [];
        foreach ($locales as $icuLocale) {
            $tag = str_replace('_', replace: '-', subject: $icuLocale);
            $canonical = LocaleIdentifier::from($tag)->baseName;
            // Prefer an installed canonical spelling over an equivalent legacy ID.
            if (!isset($available[$canonical]) || strcasecmp($tag, $canonical) === 0) {
                $available[$canonical] = $icuLocale;
            }
        }
        return $available;
    }

    /** @param array<string, string> $keywords */
    private static function withKeywords(string $locale, array $keywords): string
    {
        $selected = [];
        $calendar = $keywords['ca'] ?? '';
        if ($calendar !== '' && isset(self::calendars($locale)[$calendar])) {
            $selected[] = sprintf('ca-%s', $calendar);
        }
        $hourCycle = $keywords['hc'] ?? '';
        if (in_array($hourCycle, ['h11', 'h12', 'h23', 'h24'], strict: true)) {
            $selected[] = sprintf('hc-%s', $hourCycle);
        }
        $numbering = $keywords['nu'] ?? '';
        if ($numbering !== '' && isset(self::numberingSystems()[$numbering])) {
            $selected[] = sprintf('nu-%s', $numbering);
        }
        if ($selected === []) {
            return $locale;
        }
        // Irrelevant and unsupported extensions are not sent to ICU. Besides matching
        // ResolveLocale, this avoids native length limits on valid long input tags.
        return (
            \Locale::canonicalize(sprintf(
                '%s-u-%s',
                str_replace('_', replace: '-', subject: $locale),
                implode('-', $selected),
            )) ?? throw new \RuntimeException('ICU could not encode the selected locale.')
        );
    }

    /** @return array<string, string> Unicode calendar identifier to installed ICU keyword. */
    private static function calendars(string $locale): array
    {
        $values = \IntlCalendar::getKeywordValuesForLocale('calendar', $locale, false);
        $supported = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \RuntimeException('ICU returned an invalid calendar identifier.');
            }
            $supported[IntlCalendarFactory::calendarId($value)] = $value;
        }
        return $supported;
    }

    /** @return array<string, true> */
    private static function numberingSystems(): array
    {
        /** @var array<string, true>|null $supported */
        static $supported = null;
        if ($supported !== null) {
            return $supported;
        }
        $bundle = \ResourceBundle::create('numberingSystems', null, false);
        $systems = $bundle?->get('numberingSystems', false);
        if (!$systems instanceof \ResourceBundle) {
            throw new \RuntimeException('ICU could not read its numbering systems.');
        }
        $supported = [];
        /**
         * @var mixed $name
         * @var mixed $definition
         */
        foreach ($systems as $name => $definition) {
            if (
                !is_string($name)
                || !$definition instanceof \ResourceBundle
                || $definition->get('algorithmic', false) !== 0
                || $definition->get('radix', false) !== 10
            ) {
                continue;
            }
            $supported[$name] = true;
        }
        return $supported;
    }

    public static function supportsNumberingSystem(string $name): bool
    {
        return isset(self::numberingSystems()[$name]);
    }

    public static function supportsCalendar(string $locale, string $name): bool
    {
        return isset(self::calendars($locale)[$name]);
    }

    /** Formatter calendars include ICU choices outside Temporal's calendar set. */
    public static function calendarKeyword(string $locale, string $name): ?string
    {
        return self::calendars($locale)[$name] ?? null;
    }
}
