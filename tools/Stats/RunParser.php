<?php

declare(strict_types=1);

namespace Calendrics\Tools\Stats;

use DOMDocument;
use DOMElement;
use XMLReader;

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
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(): array
    {
        $record = [
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
        }

        return $record;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseCoverage(): ?array
    {
        $dom = new DOMDocument();
        if (@$dom->load($this->coveragePath) === false) {
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
     * The JUnit log nests one testsuite per test class inside a single wrapper
     * suite; those class-level nodes already aggregate their data-provider
     * children, so reading depth 2 gives an exact split without loading the
     * thousands of individual testcase nodes.
     *
     * @return array<string, mixed>|null
     */
    private function parseJunit(): ?array
    {
        $reader = new XMLReader();
        if (@$reader->open($this->coverageSafePath($this->junitPath)) === false) {
            return null;
        }

        $totals = $this->emptySuiteTotals();
        $bySuite = ['porcelain' => $this->emptySuiteTotals(), 'test262' => $this->emptySuiteTotals(), 'other' => $this->emptySuiteTotals()];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'testsuite' || $reader->depth !== 2) {
                continue;
            }

            $counts = [
                'tests' => (int) $reader->getAttribute('tests'),
                'assertions' => (int) $reader->getAttribute('assertions'),
                'failures' => (int) $reader->getAttribute('failures'),
                'errors' => (int) $reader->getAttribute('errors'),
                'skipped' => (int) $reader->getAttribute('skipped'),
                'time' => (float) $reader->getAttribute('time'),
            ];

            $name = (string) $reader->getAttribute('name');
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

        $reader->close();

        $totals['time'] = round($totals['time'], 2);
        foreach ($bySuite as $bucket => $counts) {
            $bySuite[$bucket]['time'] = round($counts['time'], 2);
        }

        return [...$totals, 'by_suite' => $bySuite];
    }

    /**
     * @return array<string, float|int>
     */
    private function emptySuiteTotals(): array
    {
        return ['tests' => 0, 'assertions' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'time' => 0.0];
    }

    private function coverageSafePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : './' . $path;
    }
}
