<?php

declare(strict_types=1);

use Calendrics\Tools\Stats\DashboardRenderer;
use Calendrics\Tools\Stats\RunParser;

require_once __DIR__ . '/../RunParser.php';
require_once __DIR__ . '/../DashboardRenderer.php';

$dir = sys_get_temp_dir() . '/stats-check-' . bin2hex(random_bytes(8));
mkdir($dir);
mkdir($dir . '/runs');
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$junit = <<<'XML'
    <testsuites><testsuite tests="500"><testsuite name="test262" tests="500"><testsuite name="provider">
    <testcase class="Calendrics\Tests\Test262\RunnerTest" assertions="3" time="1"/>
    <testcase class="Calendrics\Tests\Test262\RunnerTest"><failure/></testcase>
    <testcase class="Calendrics\Tests\Test262\RunnerTest"><error/></testcase>
    <testcase class="Calendrics\Tests\Test262\RunnerTest"><skipped/></testcase>
    </testsuite></testsuite><testcase class="Calendrics\Tests\Porcelain\Example" assertions="2"/></testsuite></testsuites>
    XML;
file_put_contents($dir . '/junit.xml', $junit);
$record = new RunParser($dir . '/missing', $dir . '/junit.xml', 'abc', 4, 'tests-failed')->parse();
check($record['tests']['tests'] === 5, 'count each leaf once across arbitrary suite nesting');
check($record['tests']['passed'] === 2, 'passed count excludes each reported failure category');
check(
    $record['tests']['failures'] === 1 && $record['tests']['errors'] === 1 && $record['tests']['skipped'] === 1,
    'outcomes remain distinct',
);
check($record['tests']['by_suite']['test262']['tests'] === 4, 'class identifies suite');
check($record['coverage'] === null, 'missing coverage is unknown');
file_put_contents($dir . '/junit.xml', '<testsuites><testcase>');
check(
    new RunParser('', $dir . '/junit.xml', 'abc', 1, 'ok')->parse()['tests'] === null,
    'malformed XML must not become zero tests',
);
$crashed = new RunParser('', '', 'abc', 1, 'timeout')->parse();
file_put_contents($dir . '/runs/abc.json', json_encode($crashed));
file_put_contents($dir . '/git.json', json_encode([
    'commits' => [[
        'sha' => 'abc',
        'short' => 'abc',
        'date' => '2026-10-03',
        'timestamp' => 1,
        'subject' => '</script>',
        'type' => null,
        'tags' => [],
        'loc' => [],
        'files' => [],
        'churn' => [],
    ]],
]));
file_put_contents($dir . '/template.html', '/*__STATS_DATA__*/null');
new DashboardRenderer($dir . '/git.json', $dir . '/runs', $dir . '/template.html', $dir . '/out.json')->render();
$raw = file_get_contents($dir . '/out.json');
$data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
check(
    $data['measured_count'] === 1 && $data['commits'][0]['run']['status'] === 'timeout',
    'retain failed attempts with missing artifacts',
);
check($data['commits'][0]['run']['coverage'] === null, 'unknown coverage remains null in dashboard');
check(!str_contains($raw, '</script>'), 'embedded commit metadata cannot terminate script');
echo "Statistics checks passed.\n";
