<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Spec\Internal\Calendar\IntlCalendarFactory;

/**
 * Reconstructs ICU's source-record selection for MATCH_HOUR_FIELD_LENGTH, which
 * PHP's IntlDatePatternGenerator does not expose. The selected record's explicit
 * skeleton, not just its rendered pattern, determines whether an hour is padded.
 *
 * @internal
 */
final class IntlPatternMatcher
{
    /** @var array<string, self> */
    private static array $models = [];
    /** @var array<string, string> */
    private static array $patterns = [];
    /** @var non-empty-array<int, IntlPatternRecord> */
    private array $records;
    /** @var array<string, int> */
    private array $keys = ['G' => 0];
    /** @var array<string, int> */
    private array $bases = ['G' => 0];
    private readonly string $hourSymbol;

    private function __construct(string $locale, \IntlDatePatternGenerator $generator)
    {
        $hourPattern = $generator->getBestPattern('j');
        preg_match("/'(?:[^']|'')*'(*SKIP)(*F)|[hHKk]/", $hourPattern !== false ? $hourPattern : 'H', $match);
        $this->hourSymbol = $match[0];
        $this->records = [new IntlPatternRecord('G', null)];
        foreach (str_split('yQMwWEDFdaHmsSv') as $symbol) {
            $this->add($symbol, null);
        }
        $intlCalendar = IntlCalendarFactory::forLocale('UTC', $locale);
        $calendar = $intlCalendar->getType();
        $canonical = \Locale::canonicalize($locale) ?? $locale;
        $base = explode('@', $canonical)[0];
        if ($base === '' && str_contains($canonical, '@')) {
            $base = 'root';
        } elseif ($base === '' || str_starts_with($base, '_')) {
            $base = self::requireLocale($intlCalendar->getLocale(\Locale::VALID_LOCALE));
        }
        $standards = self::resource($base, $calendar, 'DateTimePatterns', fallback: true);
        $standardLocale = sprintf('%s@calendar=%s', $base, $calendar);
        for ($i = 0; $i < 8; $i++) {
            $pattern = $standards === null
                ? new \IntlDateFormatter(
                    $standardLocale,
                    $i < 4 ? -1 : $i - 4,
                    $i < 4 ? $i : -1,
                    'UTC',
                    IntlCalendarFactory::forLocale('UTC', $standardLocale),
                )->getPattern()
                : $standards->get($i);
            if ($pattern instanceof \ResourceBundle) {
                $pattern = $pattern->get(0);
            }
            if (is_string($pattern)) {
                $this->add($pattern, null);
            }
        }
        $initialBase = $base;
        $visited = [];
        $genericAdded = in_array($calendar, ['gregorian', 'generic'], strict: true);
        while (!array_key_exists($base, $visited)) {
            if ($base === 'root' && !$genericAdded) {
                $calendar = 'generic';
                $base = $initialBase;
                $visited = [];
                $genericAdded = true;
            }
            $visited[$base] = true;
            $formats = self::resource($base, $calendar, 'availableFormats');
            if ($formats !== null) {
                /**
                 * @var mixed $skeleton
                 * @var mixed $pattern
                 */
                foreach ($formats as $skeleton => $pattern) {
                    if ($pattern instanceof \ResourceBundle) {
                        $pattern = $pattern->get('other');
                    }
                    if (is_string($pattern) && is_string($skeleton)) {
                        $this->add($pattern, $skeleton);
                    }
                }
            }
            if ($base === 'root') {
                break;
            }
            $bundle = \ResourceBundle::create($base, null, fallback: false);
            $parent = $bundle?->get('%%Parent', fallback: false);
            $separator = strrpos($base, needle: '_');
            $base = match (true) {
                is_string($parent) => $parent,
                $separator === false => 'root',
                default => substr($base, offset: 0, length: $separator),
            };
        }
        usort($this->records, static fn(IntlPatternRecord $a, IntlPatternRecord $b): int => strcmp(
            $a->base[0],
            $b->base[0],
        ));
        $this->keys = [];
        $this->bases = [];
    }

    public static function pattern(string $locale, string $skeleton): string
    {
        // ICU's fallback locale can change during a long-running PHP process.
        $localeKey = sprintf("%s\0%s", \Locale::getDefault(), $locale);
        $key = sprintf("%s\0%s", $localeKey, $skeleton);
        if (array_key_exists($key, self::$patterns)) {
            return self::$patterns[$key];
        }
        $generator = new \IntlDatePatternGenerator($locale);
        if (!array_key_exists($localeKey, self::$models)) {
            if (count(self::$models) === 16) {
                array_shift(self::$models);
            }
            self::$models[$localeKey] = new self($locale, $generator);
        }
        $model = self::$models[$localeKey];
        // Resolve j before PHP cleans the skeleton, or PHP can discard B/b.
        $mapped = str_replace('j', $model->hourSymbol, $skeleton);
        $result = $generator->getBestPattern($mapped);
        $plain = $result !== false ? $result : $mapped;
        $width = $model->hourWidth($skeleton, $plain);
        $pattern = $width === null
            ? $plain
            : preg_replace_callback(
                "/'(?:[^']|'')*'(*SKIP)(*F)|[hHKk]+/",
                static fn(array $match): string => str_repeat($match[0][0], $width),
                $plain,
            ) ?? $plain;
        if (count(self::$patterns) === 256) {
            array_shift(self::$patterns);
        }
        return self::$patterns[$key] = $pattern;
    }

    private static function resource(
        string $locale,
        string $calendar,
        string $name,
        bool $fallback = false,
    ): ?\ResourceBundle {
        $bundle = \ResourceBundle::create($locale, null, fallback: $fallback);
        $calendars = $bundle?->get('calendar', fallback: $fallback);
        $data = $calendars instanceof \ResourceBundle ? $calendars->get($calendar, fallback: $fallback) : null;
        $resource = $data instanceof \ResourceBundle ? $data->get($name, fallback: $fallback) : null;
        return $resource instanceof \ResourceBundle ? $resource : null;
    }

    /** Normalizes the conflicting analyzer stubs for ICU's string-or-false return. */
    private static function requireLocale(mixed $locale): string
    {
        if (!is_string($locale)) {
            throw new \IntlException('ICU could not resolve the formatting locale.');
        }
        return $locale;
    }

    private function add(string $pattern, ?string $skeleton): void
    {
        $new = new IntlPatternRecord($pattern, $skeleton);
        if ($new->fields === []) {
            return;
        }
        $baseIndex = $this->bases[$new->base] ?? null;
        if ($baseIndex !== null && $skeleton === null && $this->records[$baseIndex]->skeleton === null) {
            return;
        }
        $index = $this->keys[$new->key] ?? null;
        if ($index !== null) {
            if ($skeleton !== null && $this->records[$index]->skeleton === null) {
                $this->records[$index] = $new;
            }
            return;
        }
        $index = count($this->records);
        $this->keys[$new->key] = $index;
        $this->bases[$new->base] ??= $index;
        $this->records[] = $new;
    }

    /**
     * @param array<int, IntlPatternField> $request
     * @return array{IntlPatternRecord, int, int}
     */
    private function best(array $request): array
    {
        $best = $this->records[array_key_first($this->records)];
        $distance = PHP_INT_MAX;
        $missing = 0;
        $extra = 0;
        foreach ($this->records as $record) {
            $candidateDistance = 0;
            $candidateMissing = 0;
            $candidateExtra = 0;
            for ($i = 0; $i < 16; $i++) {
                $requested = $request[$i]->type ?? 0;
                $actual = $record->fields[$i]->type ?? 0;
                if ($requested === $actual) {
                    continue;
                }
                if ($requested === 0) {
                    $candidateDistance += 65_536;
                    $candidateExtra |= 1 << $i;
                } elseif ($actual === 0) {
                    $candidateDistance += 4096;
                    $candidateMissing |= 1 << $i;
                } else {
                    $candidateDistance += abs($requested - $actual);
                }
            }
            if ($candidateDistance > $distance || $candidateDistance === $distance && $candidateMissing <= $missing) {
                continue;
            }
            $best = $record;
            $distance = $candidateDistance;
            $missing = $candidateMissing;
            $extra = $candidateExtra;
            if ($distance === 0) {
                break;
            }
        }
        return [$best, $missing, $extra];
    }

    private function hourWidth(string $skeleton, string $pattern): ?int
    {
        $mapped =
            preg_replace_callback(
                '/j+/',
                fn(array $match): string => (
                    (in_array($this->hourSymbol, ['h', 'K'], strict: true) ? 'a' : '')
                    . str_repeat($this->hourSymbol, strlen($match[0]))
                ),
                $skeleton,
            ) ?? $skeleton;
        $request = IntlPatternField::parse($mapped);
        [$best, $missing, $extra] = $this->best($request);
        if ($missing !== 0 || $extra !== 0) {
            [$best, $missing] = $this->best(array_filter(
                $request,
                static fn(int $key): bool => $key >= 10,
                ARRAY_FILTER_USE_KEY,
            ));
        }
        $seen = [];
        while (($missing & (1 << 11)) !== 0 && !array_key_exists($missing, $seen)) {
            $seen[$missing] = true;
            [$best, $missing] = $this->best(array_filter(
                $request,
                static fn(int $key): bool => ($missing & (1 << $key)) !== 0,
                ARRAY_FILTER_USE_KEY,
            ));
        }
        $hour = IntlPatternField::parse($pattern)[11] ?? null;
        if ($hour === null) {
            return null;
        }
        $requestedWidth = $request[11]->width ?? null;
        return $best->skeleton !== null && ($best->fields[11]->width ?? null) === $requestedWidth
            ? $hour->width
            : $requestedWidth;
    }
}
