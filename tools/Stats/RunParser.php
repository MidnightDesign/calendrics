<?php

declare(strict_types=1);

namespace Calendrics\Tools\Stats;

use DOMDocument;
use DOMElement;

/**
 * Reads one replayed commit's PHPUnit artifacts and writes a single cache record.
 *
 * Usage: parse-run.php <coverage-index.xml> <junit.xml> <sha> <seconds> <status> <out.json>
 *
 * Both artifacts come out of the same run, which is why the replay only pays for
 * one test execution per commit: the coverage XML carries the line/method/class
 * totals and the JUnit log carries test, assertion and failure counts.
 */
final class RunParser
{
    private const SUITES = ['porcelain' => '\\Porcelain\\', 'test262' => '\\Test262\\'];

    public function __construct(
        private readonly string $coveragePath,
        private readonly string $junitPath,
        private readonly string $sha,
        private readonly float $seconds,
        private readonly string $status,
        private readonly ?array $provenance = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function parse(): array
    {
        $record = [
            'schema_version' => 2,
            'collected_at' => gmdate('c'),
            'provenance' => $this->provenance,
            'sha' => $this->sha,
            'status' => $this->status,
            'duration_seconds' => round($this->seconds, 1),
            'coverage' => null,
            'tests' => null,
        ];

        if (is_file($this->coveragePath)) {
            $record['coverage'] = $this->parseCoverage();
        }

        if (is_file($this->junitPath)) {
            $record['tests'] = $this->parseJunit();
        }

        if ($record['coverage'] === null && $record['tests'] === null && $this->status === 'ok') {
            $record['status'] = 'no-artifacts';
        } elseif (($record['coverage'] === null || $record['tests'] === null) && $this->status === 'ok') {
            $record['status'] = 'partial-artifacts';
        }

        $record['artifacts'] = [
            'coverage' => $record['coverage'] === null ? 'missing-or-invalid' : 'available',
            'junit' => $record['tests'] === null ? 'missing-or-invalid' : 'available',
        ];

        return $record;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseCoverage(): ?array
    {
        $dom = new DOMDocument();
        if (@$dom->load($this->coveragePath, LIBXML_NONET) === false) {
            return null;
        }

        $project = $dom->getElementsByTagName('project')->item(0);
        if (!$project instanceof DOMElement) {
            return null;
        }

        $root = $this->firstChildElement($project, 'directory');
        if ($root === null) {
            return null;
        }

        $byDir = [];
        $this->collectDirectories($root, '', $byDir);

        $totals = $this->readTotals($root);
        if ($totals === null) {
            return null;
        }

        return [...$totals, 'by_dir' => $byDir];
    }

    /**
     * PHPUnit names each directory node by its leaf, so the full path is only
     * recoverable by walking down from the project root.
     *
     * @param array<string, array<string, mixed>> $out
     */
    private function collectDirectories(DOMElement $dir, string $prefix, array &$out): void
    {
        $name = $dir->getAttribute('name');
        $path = $name === '/' ? '/' : rtrim($prefix, '/') . '/' . $name;

        $totals = $this->readTotals($dir);
        if ($totals !== null) {
            $out[$path] = $totals;
        }

        foreach ($dir->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'directory') {
                $this->collectDirectories($child, $path, $out);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readTotals(DOMElement $dir): ?array
    {
        $totals = $this->firstChildElement($dir, 'totals');
        if ($totals === null) {
            return null;
        }

        $lines = $this->firstChildElement($totals, 'lines');
        $methods = $this->firstChildElement($totals, 'methods');
        $classes = $this->firstChildElement($totals, 'classes');
        $traits = $this->firstChildElement($totals, 'traits');
        if ($lines === null) {
            return null;
        }

        return [
            'lines' => [
                'total' => (int) $lines->getAttribute('total'),
                'code' => (int) $lines->getAttribute('code'),
                'comments' => (int) $lines->getAttribute('comments'),
                'executable' => (int) $lines->getAttribute('executable'),
                'executed' => (int) $lines->getAttribute('executed'),
                'percent' => (float) $lines->getAttribute('percent'),
            ],
            'methods' => $this->countAndTested($methods),
            'classes' => $this->countAndTested($classes),
            'traits' => $this->countAndTested($traits),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function countAndTested(?DOMElement $node): array
    {
        if ($node === null) {
            return ['count' => 0, 'tested' => 0, 'percent' => 0.0];
        }

        return [
            'count' => (int) $node->getAttribute('count'),
            'tested' => (int) $node->getAttribute('tested'),
            'percent' => (float) $node->getAttribute('percent'),
        ];
    }

    private function firstChildElement(DOMElement $parent, string $tag): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === $tag) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Count leaf testcases once, independent of suite nesting. PHPUnit emits
     * the same empty skipped element for incomplete and skipped tests.
     *
     * @return array<string, mixed>|null
     */
    private function parseJunit(): ?array
    {
        $dom = new DOMDocument();
        if (@$dom->load($this->junitPath, LIBXML_NONET) === false) {
            return null;
        }
        if (!in_array($dom->documentElement?->tagName, ['testsuites', 'testsuite'], true)) {
            return null;
        }

        $totals = $this->emptySuiteTotals();
        $bySuite = [
            'porcelain' => $this->emptySuiteTotals(),
            'test262' => $this->emptySuiteTotals(),
            'other' => $this->emptySuiteTotals(),
        ];

        foreach ($dom->getElementsByTagName('testcase') as $test) {
            $failed = $test->getElementsByTagName('failure')->length > 0;
            $errored = $test->getElementsByTagName('error')->length > 0;
            $skipped = $test->getElementsByTagName('skipped')->length > 0;
            $counts = [
                'tests' => 1,
                'assertions' => (int) $test->getAttribute('assertions'),
                'failures' => (int) (!$errored && $failed),
                'errors' => (int) $errored,
                'skipped' => (int) (!$errored && !$failed && $skipped),
                'passed' => (int) (!$errored && !$failed && !$skipped),
                'time' => (float) $test->getAttribute('time'),
            ];

            $name = $test->getAttribute('class') ?: $test->getAttribute('classname');
            $bucket = 'other';
            foreach (self::SUITES as $candidate => $marker) {
                if (str_contains($name, $marker)) {
                    $bucket = $candidate;
                    break;
                }
            }

            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
                $bySuite[$bucket][$key] += $value;
            }
        }

        $totals['time'] = round($totals['time'], 2);
        foreach ($bySuite as $bucket => $counts) {
            $bySuite[$bucket]['time'] = round($counts['time'], 2);
        }

        return [
            ...$totals,
            'by_suite' => $bySuite,
            'denominator' => 'reported JUnit testcases (generated variants counted separately)',
            'skipped_semantics' => 'skipped or incomplete; JUnit cannot distinguish',
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function emptySuiteTotals(): array
    {
        return [
            'tests' => 0,
            'assertions' => 0,
            'failures' => 0,
            'errors' => 0,
            'skipped' => 0,
            'passed' => 0,
            'time' => 0.0,
        ];
    }
}
