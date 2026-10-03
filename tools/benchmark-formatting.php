<?php

declare(strict_types=1);

// Run inside the project's PHP container. This measures public operations; it is
// not a conformance test. Run each checkout in a fresh process for comparisons.
require dirname(__DIR__) . '/vendor/autoload.php';

use Calendrics\Spec\PlainDate;
use Calendrics\Spec\PlainDateTime;
use Calendrics\Spec\ZonedDateTime;

$options = getopt('', ['iterations:', 'samples:', 'warmup:']);
$iterations = max(1, (int) ($options['iterations'] ?? 1000));
$samples = max(1, (int) ($options['samples'] ?? 7));
$warmup = max(0, (int) ($options['warmup'] ?? 100));
$date = PlainDate::from('2024-02-29');
$dateTime = PlainDateTime::from('2024-02-29T12:34:56');
$zoned = ZonedDateTime::from('2024-03-09T12:00[America/New_York]');
$dates = [];
for ($day = 1; $day <= 28; $day++) {
    $dates[] = PlainDate::from(sprintf('2024-02-%02d', $day));
}
$operations = [
    'plain_date' => static fn(int $i): string => $date->toLocaleString('en-US', ['dateStyle' => 'long']),
    'plain_datetime' => static fn(int $i): string => $dateTime->toLocaleString('en-US', [
        'dateStyle' => 'long',
        'timeStyle' => 'short',
    ]),
    'zoned_datetime' => static fn(int $i): string => $zoned->toLocaleString('en-US', [
        'dateStyle' => 'long',
        'timeStyle' => 'short',
    ]),
    'varied_dates' => static fn(int $i): string => $dates[$i % count($dates)]->toLocaleString('de-DE', [
        'year' => 'numeric',
        'month' => 'long',
        'day' => 'numeric',
    ]),
];
$report = [
    'php' => PHP_VERSION,
    'icu' => INTL_ICU_VERSION,
    'opcache_cli' => ini_get('opcache.enable_cli'),
    'xdebug_mode' => ini_get('xdebug.mode'),
    'iterations' => $iterations,
    'samples' => $samples,
    'warmup' => $warmup,
    'results' => [],
];
foreach ($operations as $name => $operation) {
    $firstStart = hrtime(true);
    $firstOutput = $operation(0);
    $firstNanoseconds = hrtime(true) - $firstStart;
    for ($i = 0; $i < $warmup; $i++) {
        $operation($i);
    }
    $memoryBefore = memory_get_usage();
    $times = [];
    $digest = hash_init('sha256');
    for ($sample = 0; $sample < $samples; $sample++) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $output = $operation($i);
        }
        $times[] = (hrtime(true) - $start) / $iterations;
    }
    for ($i = 0; $i < $iterations; $i++) {
        $output = $operation($i);
        hash_update($digest, pack('N', strlen($output)));
        hash_update($digest, $output);
    }
    $sortedTimes = $times;
    sort($sortedTimes);
    $report['results'][$name] = [
        'first_call_nanoseconds' => $firstNanoseconds,
        'first_output_digest' => hash('sha256', $firstOutput),
        'nanoseconds_per_operation' => $times,
        'sorted_nanoseconds_per_operation' => $sortedTimes,
        'retained_php_bytes' => memory_get_usage() - $memoryBefore,
        'output_digest' => hash_final($digest),
    ];
}
$report['peak_php_bytes'] = memory_get_peak_usage(true);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
