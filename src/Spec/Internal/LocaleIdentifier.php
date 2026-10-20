<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;

/**
 * A strictly parsed Unicode locale identifier, canonicalized with pinned CLDR data.
 *
 * @phpstan-type LanguageFields array{language: ?string, script: ?string, region: ?string, variants: list<string>}
 * @psalm-type LanguageFields = array{language: ?string, script: ?string, region: ?string, variants: list<string>}
 * @phpstan-type AliasRule array{from: LanguageFields, to: list<LanguageFields>}
 * @psalm-type AliasRule = array{from: LanguageFields, to: list<LanguageFields>}
 * @internal
 */
final readonly class LocaleIdentifier
{
    /** @param array<string, string> $keywords Canonical Unicode extension keywords. */
    private function __construct(
        public string $tag,
        public string $baseName,
        public array $keywords,
    ) {}

    public static function from(string $tag): self
    {
        $lower = strtolower($tag);
        if ($lower === '' || strspn($lower, characters: 'abcdefghijklmnopqrstuvwxyz0123456789-') !== strlen($lower)) {
            self::invalid();
        }
        $tokens = explode('-', $lower);
        $index = 0;
        $fields = self::readLanguage($tokens, $index);
        $base = self::writeLanguage(self::canonicalLanguage($fields));
        $extensions = [];
        $keywords = [];
        $private = '';
        while ($index < count($tokens)) {
            $singleton = $tokens[$index++];
            if (strlen($singleton) !== 1 || isset($extensions[$singleton])) {
                self::invalid();
            }
            if ($singleton === 'x') {
                $parts = array_slice($tokens, $index);
                if ($parts === []) {
                    self::invalid();
                }
                foreach ($parts as $part) {
                    if (preg_match('/\A[a-z0-9]{1,8}\z/', $part) === 1) {
                        continue;
                    }
                    self::invalid();
                }
                $private = sprintf('-x-%s', implode('-', $parts));
                break;
            }
            $parts = [];
            while ($index < count($tokens) && strlen($tokens[$index]) !== 1) {
                $part = $tokens[$index++];
                if (preg_match('/\A[a-z0-9]{2,8}\z/', $part) !== 1) {
                    self::invalid();
                }
                $parts[] = $part;
            }
            if ($parts === []) {
                self::invalid();
            }
            if ($singleton === 'u') {
                [$extension, $keywords] = self::unicodeExtension($parts);
            } elseif ($singleton === 't') {
                $extension = self::transformExtension($parts);
            } else {
                $extension = implode('-', $parts);
            }
            $extensions[$singleton] = $extension;
        }
        ksort($extensions, SORT_STRING);
        $canonical = $base;
        foreach ($extensions as $singleton => $extension) {
            $canonical = sprintf('%s-%s-%s', $canonical, $singleton, $extension);
        }
        return new self(sprintf('%s%s', $canonical, $private), $base, $keywords);
    }

    /**
     * @param list<string> $tokens
     * @return LanguageFields
     */
    private static function readLanguage(array $tokens, int &$index): array
    {
        $language = $tokens[$index] ?? '';
        if (preg_match('/\A(?:[a-z]{2,3}|[a-z]{5,8})\z/', $language) !== 1) {
            self::invalid();
        }
        ++$index;
        $script = null;
        $region = null;
        if (isset($tokens[$index]) && preg_match('/\A[a-z]{4}\z/', $tokens[$index]) === 1) {
            $script = ucfirst($tokens[$index++]);
        }
        if (isset($tokens[$index]) && preg_match('/\A(?:[a-z]{2}|[0-9]{3})\z/', $tokens[$index]) === 1) {
            $region = strtoupper($tokens[$index++]);
        }
        $variants = [];
        while (
            isset($tokens[$index])
            && preg_match('/\A(?:[a-z0-9]{5,8}|[0-9][a-z0-9]{3})\z/', $tokens[$index]) === 1
        ) {
            $variant = $tokens[$index++];
            if (in_array($variant, $variants, strict: true)) {
                self::invalid();
            }
            $variants[] = $variant;
        }
        sort($variants, SORT_STRING);
        return [
            'language' => $language === 'und' ? null : $language,
            'script' => $script,
            'region' => $region,
            'variants' => $variants,
        ];
    }

    /** @param LanguageFields $fields */
    private static function writeLanguage(array $fields): string
    {
        $parts = [$fields['language'] ?? 'und'];
        if ($fields['script'] !== null) {
            $parts[] = $fields['script'];
        }
        if ($fields['region'] !== null) {
            $parts[] = $fields['region'];
        }
        return implode('-', [...$parts, ...$fields['variants']]);
    }

    /**
     * UTS35 Annex C: apply the first matching ordered rule, then restart.
     *
     * @param LanguageFields $fields
     * @return LanguageFields
     */
    private static function canonicalLanguage(array $fields): array
    {
        $seen = [];
        while (true) {
            $before = self::writeLanguage($fields);
            if (isset($seen[$before])) {
                throw new \LogicException('Generated locale aliases contain a cycle.');
            }
            $seen[$before] = true;
            $matched = false;
            foreach (self::aliasRules() as $rule) {
                $from = $rule['from'];
                if (!self::matches($fields, $from)) {
                    continue;
                }
                $replacement = $rule['to'][0];
                if (count($rule['to']) > 1) {
                    $likely = self::likelyRegion($fields);
                    foreach ($rule['to'] as $candidate) {
                        if ($candidate['region'] !== $likely) {
                            continue;
                        }
                        $replacement = $candidate;
                        break;
                    }
                }
                $fields['language'] =
                    $from['language'] !== null || $fields['language'] === null
                        ? $replacement['language']
                        : $fields['language'];
                $fields['script'] =
                    $from['script'] !== null || $fields['script'] === null ? $replacement['script'] : $fields['script'];
                $fields['region'] =
                    $from['region'] !== null || $fields['region'] === null ? $replacement['region'] : $fields['region'];
                $fields['variants'] = array_values(array_unique([
                    ...array_diff($fields['variants'], $from['variants']),
                    ...$replacement['variants'],
                ]));
                sort($fields['variants'], SORT_STRING);
                $matched = true;
                break;
            }
            if (!$matched) {
                return $fields;
            }
        }
    }

    /**
     * @param LanguageFields $fields
     * @param LanguageFields $pattern
     */
    private static function matches(array $fields, array $pattern): bool
    {
        if (
            $pattern['language'] !== null && $fields['language'] !== $pattern['language']
            || $pattern['script'] !== null && $fields['script'] !== $pattern['script']
            || $pattern['region'] !== null && $fields['region'] !== $pattern['region']
        ) {
            return false;
        }
        return array_diff($pattern['variants'], $fields['variants']) === [];
    }

    /** @return list<AliasRule> */
    private static function aliasRules(): array
    {
        /** @var list<AliasRule>|null $rules */
        static $rules = null;
        if ($rules !== null) {
            return $rules;
        }
        $rules = [];
        foreach ([
            LocaleCanonicalizationData::LANGUAGE,
            LocaleCanonicalizationData::SCRIPT,
            LocaleCanonicalizationData::TERRITORY,
            LocaleCanonicalizationData::VARIANT,
        ] as $kind => $aliases) {
            foreach ($aliases as $from => $to) {
                $prefix = $kind === 0 ? '' : 'und-';
                $sourceTokens = explode('-', strtolower(sprintf('%s%s', $prefix, $from)));
                $index = 0;
                $source = self::readLanguage($sourceTokens, $index);
                $replacements = [];
                foreach (explode(' ', $to) as $replacement) {
                    $tokens = explode('-', strtolower(sprintf('%s%s', $prefix, $replacement)));
                    $index = 0;
                    $replacements[] = self::readLanguage($tokens, $index);
                }
                $rules[] = ['from' => $source, 'to' => $replacements];
            }
        }
        usort($rules, self::compareRules(...));
        return $rules;
    }

    /**
     * @param AliasRule $a
     * @param AliasRule $b
     */
    private static function compareRules(array $a, array $b): int
    {
        $left = self::ruleFields($a['from']);
        $right = self::ruleFields($b['from']);
        $size = array_sum(array_map(count(...), $right)) - array_sum(array_map(count(...), $left));
        if ($size !== 0) {
            return $size;
        }
        foreach ($left as $index => $values) {
            if (($values === []) !== ($right[$index] === [])) {
                return $values === [] ? 1 : -1;
            }
        }
        foreach ($left as $index => $values) {
            $order = strcmp(implode("\0", $values), implode("\0", $right[$index]));
            if ($order !== 0) {
                return $order;
            }
        }
        return 0;
    }

    /**
     * @param LanguageFields $fields
     * @return array{list<string>, list<string>, list<string>, list<string>}
     */
    private static function ruleFields(array $fields): array
    {
        return [
            $fields['language'] === null ? [] : [$fields['language']],
            $fields['script'] === null ? [] : [$fields['script']],
            $fields['region'] === null ? [] : [$fields['region']],
            $fields['variants'],
        ];
    }

    /** @param LanguageFields $fields */
    private static function likelyRegion(array $fields): ?string
    {
        $language = $fields['language'] ?? 'und';
        $script = $fields['script'] === 'Zzzz' ? null : $fields['script'];
        $keys = $script === null
            ? [$language, 'und']
            : [sprintf('%s-%s', $language, $script), $language, sprintf('und-%s', $script), 'und'];
        foreach ($keys as $key) {
            if (isset(LocaleCanonicalizationData::LIKELY_REGION[$key])) {
                return LocaleCanonicalizationData::LIKELY_REGION[$key];
            }
        }
        return null;
    }

    /**
     * @param non-empty-list<string> $parts
     * @return array{string, array<string, string>}
     */
    private static function unicodeExtension(array $parts): array
    {
        $index = 0;
        $attributes = [];
        while (isset($parts[$index]) && strlen($parts[$index]) >= 3) {
            $attributes[$parts[$index++]] = true;
        }
        $keywords = [];
        while (isset($parts[$index])) {
            $key = $parts[$index++];
            if (preg_match('/\A[a-z0-9][a-z]\z/', $key) !== 1) {
                self::invalid();
            }
            $values = [];
            while (isset($parts[$index]) && strlen($parts[$index]) >= 3) {
                $values[] = $parts[$index++];
            }
            if (!array_key_exists($key, $keywords)) {
                $value = self::canonicalType($key, implode('-', $values));
                $keywords[$key] = $value === 'true' ? '' : $value;
            }
        }
        ksort($attributes, SORT_STRING);
        ksort($keywords, SORT_STRING);
        $result = array_keys($attributes);
        foreach ($keywords as $key => $value) {
            $result[] = $value === '' ? $key : sprintf('%s-%s', $key, $value);
        }
        return [implode('-', $result), $keywords];
    }

    /** @param non-empty-list<string> $parts */
    private static function transformExtension(array $parts): string
    {
        $index = 0;
        $language = '';
        if (preg_match('/\A[a-z][0-9]\z/', $parts[0]) !== 1) {
            $fields = self::readLanguage($parts, $index);
            $language = strtolower(self::writeLanguage(self::canonicalLanguage($fields)));
        }
        $fields = [];
        while (isset($parts[$index])) {
            $key = $parts[$index++];
            if (preg_match('/\A[a-z][0-9]\z/', $key) !== 1) {
                self::invalid();
            }
            $values = [];
            while (isset($parts[$index]) && strlen($parts[$index]) >= 3) {
                $values[] = $parts[$index++];
            }
            if ($values === []) {
                self::invalid();
            }
            $fields[] = sprintf('%s-%s', $key, self::canonicalType($key, implode('-', $values)));
        }
        sort($fields, SORT_STRING);
        return implode('-', $language === '' ? $fields : [$language, ...$fields]);
    }

    /** Canonicalize a validated, lowercase Unicode type in its key's context. */
    public static function canonicalType(string $key, string $value): string
    {
        $value = LocaleCanonicalizationData::EXTENSION_TYPES[$key][$value] ?? $value;
        if (($key === 'sd' || $key === 'rg') && isset(LocaleCanonicalizationData::SUBDIVISION[$value])) {
            $value = strtolower(explode(' ', LocaleCanonicalizationData::SUBDIVISION[$value])[0]);
            if (strlen($value) === 2) {
                $value = sprintf('%szzzz', $value);
            }
        }
        return $value;
    }

    private static function invalid(): never
    {
        throw new RangeError('Invalid Unicode locale identifier.');
    }
}
