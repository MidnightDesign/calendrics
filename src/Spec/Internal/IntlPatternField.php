<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

/** @internal */
final class IntlPatternField
{
    /** @var array<string, self> */
    private static array $tokens = [];

    /** @param non-negative-int $width */
    public function __construct(
        public readonly string $symbol,
        public readonly int $width,
        public readonly int $type,
        public readonly string $base,
    ) {}

    /** @return array<int, self> */
    public static function parse(string $pattern): array
    {
        $pattern = preg_replace("/'(?:[^']|'')*'/", replacement: '', subject: $pattern) ?? $pattern;
        preg_match_all('/([A-Za-z])\1*/', $pattern, $tokens);
        $fields = [];
        foreach ($tokens[0] as $token) {
            $symbol = $token[0];
            $width = strlen($token);
            $index = match ($symbol) {
                'G' => 0,
                'y', 'Y', 'u', 'U', 'r' => 1,
                'Q', 'q' => 2,
                'M', 'L', 'l' => 3,
                'w' => 4,
                'W' => 5,
                'E', 'e', 'c' => 6,
                'D' => 7,
                'F' => 8,
                'd', 'g' => 9,
                'a', 'b', 'B' => 10,
                'h', 'H', 'k', 'K' => 11,
                'm' => 12,
                's', 'A' => 13,
                'S' => 14,
                'z', 'v', 'V', 'O', 'Z', 'X', 'x' => 15,
                default => null,
            };
            if ($index === null) {
                continue;
            }
            if (array_key_exists($token, self::$tokens)) {
                $fields[$index] = self::$tokens[$token];
                continue;
            }
            $text =
                in_array($index, [0, 10, 15], strict: true)
                || $symbol === 'U'
                || $index === 6 && ($symbol === 'E' || $width >= 3)
                || in_array($index, [2, 3], strict: true) && $width >= 3;
            $delta = match ($symbol) {
                'H' => 160,
                'k' => 176,
                'K', 'Y', 'L', 'l', 'q', 'e', 'b', 'O', 'Z', 'V', 'X', 'x', 'g', 'A' => 16,
                'u', 'c', 'v' => 32,
                'r', 'B' => 48,
                default => 0,
            };
            $textType = match ($width) {
                5 => -257,
                6 => -258,
                4 => -260,
                default => -259,
            };
            $textType = match ($symbol) {
                'Z' => $width < 4 ? -257 : ($width === 4 ? -260 : -259),
                'V' => $width === 1 ? -259 : -258 - $width,
                'X', 'x' => $width === 1 ? -257 : ($width < 4 ? -259 : -260),
                default => $textType,
            };
            $baseWidth = match (true) {
                !$text => 1,
                $symbol === 'V' => min(4, $width),
                in_array($symbol, ['X', 'x'], strict: true) => $width === 1 ? 1 : ($width < 4 ? 2 : 4),
                $width > 3 => $width,
                in_array($symbol, ['E', 'e', 'c', 'M', 'L', 'Q', 'q'], strict: true) => 3,
                default => 1,
            };
            if (count(self::$tokens) === 256) {
                array_shift(self::$tokens);
            }
            $fields[$index] =
                self::$tokens[$token] = new self(
                    $symbol,
                    $width,
                    $text ? $textType - $delta : 256 + $delta + $width,
                    str_repeat($symbol, $baseWidth),
                );
        }
        if (array_key_exists(12, $fields) && array_key_exists(14, $fields) && !array_key_exists(13, $fields)) {
            $fields[13] = new self('s', 1, 257, 's');
        }
        if (array_key_exists(11, $fields)) {
            if (in_array($fields[11]->symbol, ['h', 'K'], strict: true)) {
                $fields[10] ??= new self('a', 1, -259, 'a');
            } else {
                unset($fields[10]);
            }
        }
        ksort($fields);
        return $fields;
    }
}
