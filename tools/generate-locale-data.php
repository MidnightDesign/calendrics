<?php

declare(strict_types=1);

/**
 * Generate only the CLDR data needed for Unicode locale canonicalization.
 *
 * Usage: php tools/generate-locale-data.php /path/to/cldr-checkout
 * Source: https://github.com/unicode-org/cldr/tree/acd6d88ae493633240e19a87a721076a8a75c310
 * CLDR 48, Unicode-3.0. The complete notice is in LICENSES/Unicode-3.0.txt.
 * SimpleXML is needed by this development tool, not by the runtime library.
 */

const INPUT_HASHES = [
    'common/bcp47/calendar.xml' => 'fb24616f77fac1ab3246f869b69efc8a4b86d2437a947f3f6a53843ac0b60821',
    'common/bcp47/collation.xml' => '2fa8fef733069c4651a5c60c85cb38ba09a07151d71b9b148d9372eff28a4d42',
    'common/bcp47/currency.xml' => '93849191705b5e52adab6766d3bf41ae627373c34c7cb73259fdc17d22bd3bc7',
    'common/supplemental/likelySubtags.xml' => 'd7732172848cc2870d0d316818190e704bc6614486168dc52af56ee1427b6860',
    'common/bcp47/measure.xml' => '6a1445e2f6af8fad47b9d5b40e67d582e7f4b9502609443780d3e3641d23be4d',
    'common/bcp47/number.xml' => '0c10e9578f2191527475d0f57105d56c399b2f44425ceebadf474e50157abbb6',
    'common/bcp47/segmentation.xml' => '9a3a00df3181b51279389655ef06f12be593d3b288c44b54caf7b2d86ed8b285',
    'common/supplemental/supplementalMetadata.xml' => '36e807ce72b15304dd993f132216f408351be1c4376edaa2fe9e9547e2efcac1',
    'common/bcp47/timezone.xml' => '54ce7d9f1d385101e24356df7d48ae62cf2acc4421378da60a4007d2488c1aad',
    'common/bcp47/transform-destination.xml' => '469976ddb878572cdede31e94a6e60a4cd9d2d83df9bda3e47045c8489cf76a0',
    'common/bcp47/transform.xml' => 'd564dd996541afbcab77b3446bea5e591c3ec5c3b27ed0cd08326ae873ec7bdb',
    'common/bcp47/transform_hybrid.xml' => '83e32a739b70b6fe6c650095e0fa88b9cef3ae67310ec134992d054791ad8c77',
    'common/bcp47/transform_ime.xml' => 'cdc020c18ef8d7c9d3b1ccc12b3ea711cde758f8c0aed828c9bc1bc3197e051c',
    'common/bcp47/transform_keyboard.xml' => 'a719796bd59b8daf4b98d6fd294e6d5405b8bf3b36a5086a70242bd5e8a12ef9',
    'common/bcp47/transform_mt.xml' => '508428eaa699cdd857d0daf7ce8ff232023abd162b5f623640b10de1348d95e7',
    'common/bcp47/transform_private_use.xml' => 'b1157b7e690aa1f17894f0c2e1d8a16e749a1115a93f743d7032fdfb1686fbe2',
    'common/bcp47/variant.xml' => 'e5500779baa55e838c5bf1529561c3b441fe2c331caac6ceddecffb4e425a514',
];

/** @return SimpleXMLElement */
function readInput(string $source, string $path, string $hash): SimpleXMLElement
{
    $text = file_get_contents(sprintf('%s/%s', rtrim($source, '/'), $path));
    if ($text === false) {
        throw new RuntimeException(sprintf('Cannot read CLDR input %s.', $path));
    }
    $text = sprintf("%s\n", rtrim(str_replace("\r\n", "\n", $text), "\n"));
    if (hash('sha256', $text) !== $hash) {
        throw new RuntimeException(sprintf('CLDR input %s does not match the pinned version.', $path));
    }
    $xml = simplexml_load_string($text, options: LIBXML_NONET);
    if ($xml === false) {
        throw new RuntimeException(sprintf('Cannot parse CLDR input %s.', $path));
    }
    return $xml;
}

/** @param array<array-key, mixed> $values */
function renderArray(array $values, int $indent): string
{
    $lines = ['['];
    foreach ($values as $key => $value) {
        $rendered = is_array($value) ? renderArray($value, $indent + 4) : var_export($value, true);
        $lines[] = sprintf('%s%s => %s,', str_repeat(' ', $indent + 4), var_export($key, true), $rendered);
    }
    $lines[] = sprintf('%s]', str_repeat(' ', $indent));
    return implode("\n", $lines);
}

/** @param array<string, string> $aliases */
function flattenAliases(array $aliases): array
{
    foreach ($aliases as $source => $replacement) {
        $seen = [$source => true];
        while (isset($aliases[$replacement])) {
            if (isset($seen[$replacement])) {
                throw new RuntimeException(sprintf('Cyclic extension alias: %s.', $source));
            }
            $seen[$replacement] = true;
            $replacement = $aliases[$replacement];
        }
        $aliases[$source] = $replacement;
    }
    ksort($aliases, SORT_STRING);
    return $aliases;
}

$source = $argv[1] ?? throw new InvalidArgumentException('Pass the pinned CLDR source checkout.');
$inputs = [];
foreach (INPUT_HASHES as $path => $hash) {
    $inputs[$path] = readInput($source, $path, $hash);
}
$data = ['language' => [], 'script' => [], 'territory' => [], 'variant' => [], 'subdivision' => []];
$languagePattern = '/\A(?:[a-z]{2,3}|[a-z]{5,8})(?:-[a-z]{4})?(?:-(?:[a-z]{2}|[0-9]{3}))?(?:-(?:[a-z0-9]{5,8}|[0-9][a-z0-9]{3}))*\z/i';
foreach ($inputs['common/supplemental/supplementalMetadata.xml']->xpath('//alias/*') as $alias) {
    $kind = preg_replace('/Alias\z/', '', $alias->getName());
    if (!array_key_exists($kind, $data)) {
        continue;
    }
    $from = str_replace('_', '-', (string) $alias['type']);
    $to = str_replace('_', '-', (string) $alias['replacement']);
    // Invalid legacy sources cannot pass the public Unicode locale grammar.
    $reachable = match ($kind) {
        'language' => preg_match($languagePattern, $from) === 1,
        'script' => preg_match('/\A[a-z]{4}\z/i', $from) === 1,
        'territory' => preg_match('/\A(?:[a-z]{2}|[0-9]{3})\z/i', $from) === 1,
        'variant' => preg_match('/\A(?:[a-z0-9]{5,8}|[0-9][a-z0-9]{3})\z/i', $from) === 1,
        'subdivision' => true,
    };
    if ($reachable) {
        $data[$kind][$from] = $to;
    }
}

$data['likely_region'] = [];
foreach ($inputs['common/supplemental/likelySubtags.xml']->xpath('//likelySubtag') as $entry) {
    $from = str_replace('_', '-', (string) $entry['from']);
    // Territory alias selection queries only language and optional script.
    if (preg_match('/\A(?:[a-z]{2,3}|[a-z]{5,8})(?:-[A-Z][a-z]{3})?\z/', $from) !== 1) {
        continue;
    }
    $target = explode('_', (string) $entry['to']);
    $data['likely_region'][$from] = end($target);
}

$data['extension_types'] = [];
$typePattern = '/\A[a-z0-9]{3,8}(?:-[a-z0-9]{3,8})*\z/i';
foreach ($inputs as $path => $xml) {
    if (!str_starts_with($path, 'common/bcp47/')) {
        continue;
    }
    foreach ($xml->xpath('//key') as $keyElement) {
        $key = (string) $keyElement['name'];
        foreach (preg_split('/\s+/', trim((string) $keyElement['alias'])) as $keyAlias) {
            if (preg_match('/\A(?:[a-z0-9][a-z]|[a-z][0-9])\z/i', $keyAlias) === 1) {
                throw new RuntimeException('A new reachable key alias needs generator support.');
            }
        }
        foreach ($keyElement->type as $type) {
            $name = (string) $type['name'];
            $preferred = (string) $type['preferred'];
            $replacement = $preferred !== '' ? $preferred : $name;
            $aliases = preg_split('/\s+/', trim((string) $type['alias']));
            if ($preferred !== '') {
                $aliases[] = $name;
            }
            foreach ($aliases as $alias) {
                $alias = strtolower($alias);
                if ($alias === $replacement || preg_match($typePattern, $alias) !== 1) {
                    continue;
                }
                if (
                    isset($data['extension_types'][$key][$alias])
                    && $data['extension_types'][$key][$alias] !== $replacement
                ) {
                    throw new RuntimeException(sprintf('Conflicting extension alias %s/%s.', $key, $alias));
                }
                $data['extension_types'][$key][$alias] = $replacement;
            }
        }
    }
}
foreach ($data['extension_types'] as $key => $aliases) {
    $data['extension_types'][$key] = flattenAliases($aliases);
}

$output = <<<'HEADER'
    <?php

    declare(strict_types=1);

    namespace Calendrics\Spec\Internal;

    /**
     * Generated by tools/generate-locale-data.php from CLDR 48.
     * Source commit: acd6d88ae493633240e19a87a721076a8a75c310.
     * Copyright Unicode, Inc. SPDX-License-Identifier: Unicode-3.0.
     * See LICENSES/Unicode-3.0.txt. Do not edit these values by hand.
     *
     * @internal
     */
    final class LocaleCanonicalizationData
    {
    HEADER;
foreach ($data as $name => $values) {
    ksort($values, SORT_STRING);
    $type = $name === 'extension_types' ? 'array<string, array<string, string>>' : 'array<array-key, string>';
    $output = sprintf(
        "%s\n    /** @var %s */\n    public const array %s = %s;\n",
        $output,
        $type,
        strtoupper($name),
        renderArray($values, 4),
    );
}
$output = sprintf("%s}\n", $output);
$destination = sprintf('%s/src/Spec/Internal/LocaleCanonicalizationData.php', dirname(__DIR__));
if (file_put_contents($destination, $output) === false) {
    throw new RuntimeException('Cannot write generated locale data.');
}
printf("Generated %s (%d bytes).\n", $destination, strlen($output));
