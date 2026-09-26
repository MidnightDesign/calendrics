<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Spec\Internal\Calendar\IntlCalendarFactory;

/** @internal */
final class IntlPatternRecords
{
    /** @var non-empty-array<int, IntlPatternRecord> */
    private array $records;
    /** @var array<string, int> */
    private array $keys = ['G' => 0];
    /** @var array<string, int> */
    private array $bases = ['G' => 0];

    /** @var list<array{string, string|null}> */
    private array $sources = [];
    /** @var array<int, true> */
    private array $hourWidths = [];

    public function __construct(string $locale)
    {
        $this->records = [new IntlPatternRecord('G', null)];
        foreach (str_split('yQMwWEDFdaHmsSv') as $symbol) {
            $this->sources[] = [$symbol, null];
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
                $this->sources[] = [$pattern, null];
            }
        }
        $skeletons = [];
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
                    if (is_string($skeleton) && array_key_exists($skeleton, $skeletons)) {
                        continue;
                    }
                    if ($pattern instanceof \ResourceBundle) {
                        $pattern = $pattern->get('other');
                    }
                    if (is_string($pattern) && is_string($skeleton)) {
                        $skeletons[$skeleton] = true;
                        $this->sources[] = [$pattern, $skeleton];
                        preg_match_all('/([hHKk])\1*/', $skeleton, $hours);
                        if ($hours[0] !== []) {
                            $this->hourWidths[strlen($hours[0][array_key_last($hours[0])])] = true;
                        }
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
    }

    public function hasHourWidth(int $width): bool
    {
        return array_key_exists($width, $this->hourWidths);
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
    public function best(array $request): array
    {
        if ($this->sources !== []) {
            foreach ($this->sources as [$pattern, $skeleton]) {
                $this->add($pattern, $skeleton);
            }
            usort($this->records, static fn(IntlPatternRecord $a, IntlPatternRecord $b): int => strcmp(
                $a->base[0],
                $b->base[0],
            ));
            $this->sources = [];
            $this->keys = [];
            $this->bases = [];
        }
        $best = $this->records[array_key_first($this->records)];
        $distance = PHP_INT_MAX;
        $missing = 0;
        $extra = 0;
        $requestMask = 0;
        foreach (array_keys($request) as $index) {
            $requestMask |= 1 << $index;
        }
        foreach ($this->records as $record) {
            $candidateMissing = $requestMask & ~$record->mask;
            $candidateExtra = $record->mask & ~$requestMask;
            $candidateDistance = 0;
            for ($bits = $candidateMissing; $bits !== 0; $bits &= $bits - 1) {
                $candidateDistance += 4096;
            }
            for ($bits = $candidateExtra; $bits !== 0; $bits &= $bits - 1) {
                $candidateDistance += 65_536;
            }
            if ($candidateDistance > $distance) {
                continue;
            }
            foreach ($request as $index => $field) {
                if (!array_key_exists($index, $record->fields)) {
                    continue;
                }
                $candidateDistance += abs($field->type - $record->fields[$index]->type);
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
}
