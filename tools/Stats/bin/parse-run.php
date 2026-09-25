<?php

declare(strict_types=1);

use Calendrics\Tools\Stats\RunParser;

require_once __DIR__ . '/../RunParser.php';

$argv = $_SERVER['argv'];
if (count($argv) < 7) {
    fwrite(STDERR, "usage: parse-run.php <coverage-index> <junit> <sha> <seconds> <status> <out.json>\n");
    exit(1);
}

$record = (new RunParser($argv[1], $argv[2], $argv[3], (float) $argv[4], $argv[5]))->parse();

$json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "error: cannot encode record\n");
    exit(1);
}

file_put_contents($argv[6], $json . "\n");

$tests = $record['tests'];
$coverage = $record['coverage'];
printf(
    "%s tests=%s asserts=%s fail=%s line=%s",
    $record['status'],
    is_array($tests) ? number_format($tests['tests']) : '-',
    is_array($tests) ? number_format($tests['assertions']) : '-',
    is_array($tests) ? $tests['failures'] + $tests['errors'] : '-',
    is_array($coverage) ? sprintf('%.2f%%', $coverage['lines']['percent']) : '-',
);
