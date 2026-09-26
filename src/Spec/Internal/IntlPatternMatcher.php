<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

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
    private ?IntlPatternRecords $records = null;
    private readonly string $hourSymbol;

    private function __construct(
        private readonly string $locale,
        private readonly \IntlDatePatternGenerator $generator,
    ) {
        $hourPattern = $generator->getBestPattern('j');
        preg_match("/'(?:[^']|'')*'(*SKIP)(*F)|[hHKk]/", $hourPattern !== false ? $hourPattern : 'H', $match);
        $this->hourSymbol = $match[0];
    }

    public static function pattern(string $locale, string $skeleton): string
    {
        // ICU's fallback locale can change during a long-running PHP process.
        $localeKey = sprintf("%s\0%s", \Locale::getDefault(), $locale);
        $key = sprintf("%s\0%s", $localeKey, $skeleton);
        if (array_key_exists($key, self::$patterns)) {
            return self::$patterns[$key];
        }
        if (!array_key_exists($localeKey, self::$models)) {
            if (count(self::$models) === 16) {
                array_shift(self::$models);
            }
            self::$models[$localeKey] = new self($locale, new \IntlDatePatternGenerator($locale));
        }
        $model = self::$models[$localeKey];
        // Resolve j before PHP cleans the skeleton, or PHP can discard B/b.
        $mapped = str_replace('j', $model->hourSymbol, $skeleton);
        $result = $model->generator->getBestPattern($mapped);
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

    /** @return non-negative-int|null */
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
        $hour = IntlPatternField::parse($pattern)[11] ?? null;
        if ($hour === null) {
            return null;
        }
        $requestedWidth = $request[11]->width ?? null;
        // Record selection can only choose between these two widths.
        if ($requestedWidth === null || $hour->width === $requestedWidth) {
            return $requestedWidth;
        }
        $records = $this->records ??= new IntlPatternRecords($this->locale);
        if (!$records->hasHourWidth($requestedWidth)) {
            return $requestedWidth;
        }
        [$best, $missing, $extra] = $records->best($request);
        if ($missing !== 0 || $extra !== 0) {
            [$best, $missing] = $records->best(array_filter(
                $request,
                static fn(int $key): bool => $key >= 10,
                ARRAY_FILTER_USE_KEY,
            ));
        }
        $seen = [];
        while (($missing & (1 << 11)) !== 0 && !array_key_exists($missing, $seen)) {
            $seen[$missing] = true;
            [$best, $missing] = $records->best(array_filter(
                $request,
                static fn(int $key): bool => ($missing & (1 << $key)) !== 0,
                ARRAY_FILTER_USE_KEY,
            ));
        }
        return $best->skeleton !== null && ($best->fields[11]->width ?? null) === $requestedWidth
            ? $hour->width
            : $requestedWidth;
    }
}
