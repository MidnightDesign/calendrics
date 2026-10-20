<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

/**
 * Shared lexical grammar. Each Temporal conversion owns its accepted forms,
 * field validation, annotation interpretation, and representable range.
 *
 * @internal
 */
final class IsoLexical
{
    public const string YEAR = '(?:[+-]\d{6}|\d{4})';
    public const string NUMERIC_OFFSET = '[+-](?:[01]\d|2[0-3])(?::[0-5]\d(?::[0-5]\d(?:[.,]\d{1,9})?)?|[0-5]\d(?:[0-5]\d(?:[.,]\d{1,9})?)?)?';
    private const string TIME = '\d{2}(?::\d{2}(?::\d{2}(?:[.,]\d{1,9})?)?|\d{2}(?:\d{2}(?:[.,]\d{1,9})?)?)?';
    private const string ANNOTATIONS = '(?<annotations>(?:\[[^\]]*\])*)';

    public static function date(string $input, bool $allowYearMonth = false): ?IsoLexicalResult
    {
        $dateRest = $allowYearMonth ? '(?:-\d{2}(?:-\d{2})?|\d{2}(?:\d{2})?)' : '(?:-\d{2}-\d{2}|\d{4})';
        return self::match(
            sprintf(
                '~\A(?<year>%s)(?<dateRest>%s)(?:[Tt ]%s)?%s\z~',
                self::YEAR,
                $dateRest,
                self::timePattern(),
                self::ANNOTATIONS,
            ),
            $input,
        );
    }

    public static function time(string $input): ?IsoLexicalResult
    {
        return self::match(sprintf('~\A[Tt]?%s%s\z~', self::timePattern(), self::ANNOTATIONS), $input);
    }

    private static function timePattern(): string
    {
        return sprintf('(?<time>%s)(?<offset>[Zz]|%s)?', self::TIME, self::NUMERIC_OFFSET);
    }

    /** @param non-empty-string $pattern */
    private static function match(string $pattern, string $input): ?IsoLexicalResult
    {
        $parts = [];
        if (preg_match($pattern, $input, $parts) !== 1) {
            return null;
        }
        // Decode after matching: compact and extended spellings have identical fields.
        $time = str_replace(':', replace: '', subject: $parts['time'] ?? '');
        return new IsoLexicalResult(
            $parts['year'] ?? '',
            $parts['dateRest'] ?? '',
            substr(string: $time, offset: 0, length: 2),
            substr(string: $time, offset: 2, length: 2),
            substr(string: $time, offset: 4, length: 2),
            substr(string: $time, offset: 6),
            $parts['offset'] ?? '',
            $parts['annotations'],
        );
    }
}
