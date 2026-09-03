<?php

declare(strict_types=1);

namespace Calendrics\Tools\Stats;

/**
 * Merges the two datasets into the dashboard page.
 *
 * git.json always covers the full history; the replay cache in data/runs/ fills
 * in whatever commits have been measured so far, so this renders usefully while
 * a replay is still running.
 */
final class DashboardRenderer
{
    public function __construct(
        private readonly string $gitPath,
        private readonly string $runsDir,
        private readonly string $templatePath,
        private readonly string $outputPath,
    ) {
    }

    public function render(): void
    {
        $git = $this->readJson($this->gitPath);
        $commits = $git['commits'] ?? [];
        if (!is_array($commits) || $commits === []) {
            $this->fail('git.json holds no commits — run collect-git.sh first');
        }

        $measured = 0;
        $records = [];

        foreach ($commits as $commit) {
            $run = $this->readRun((string) $commit['sha']);
            if ($run !== null) {
                $measured++;
            }

            $records[] = [
                'sha' => $commit['short'],
                'date' => $commit['date'],
                'timestamp' => $commit['timestamp'],
                'subject' => $commit['subject'],
                'type' => $commit['type'],
                'tags' => $commit['tags'],
                'loc' => $commit['loc'],
                'files' => $commit['files'],
                'churn' => $commit['churn'],
                'run' => $run,
            ];
        }

        $data = [
            'generated_at' => gmdate('Y-m-d H:i') . ' UTC',
            'commit_count' => count($records),
            'measured_count' => $measured,
            'commits' => $records,
        ];

        $template = file_get_contents($this->templatePath);
        if ($template === false) {
            $this->fail(sprintf('cannot read %s', $this->templatePath));
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->fail('cannot encode dashboard data');
        }

        $marker = '/*__STATS_DATA__*/null';
        if (!str_contains($template, $marker)) {
            $this->fail(sprintf('template is missing the %s marker', $marker));
        }

        file_put_contents($this->outputPath, str_replace($marker, $json, $template));

        printf(
            "==> wrote %s\n    %d commits, %d with test measurements (%.0f%%)\n",
            $this->outputPath,
            count($records),
            $measured,
            count($records) > 0 ? $measured / count($records) * 100 : 0.0,
        );
    }

    /**
     * Flattens one cached run into the handful of series the dashboard plots.
     *
     * @return array<string, mixed>|null
     */
    private function readRun(string $sha): ?array
    {
        $path = sprintf('%s/%s.json', $this->runsDir, $sha);
        if (!is_file($path)) {
            return null;
        }

        $run = $this->readJson($path);
        $coverage = $run['coverage'] ?? null;
        $tests = $run['tests'] ?? null;
        if (!is_array($coverage) || !is_array($tests)) {
            return null;
        }

        $suites = $tests['by_suite'] ?? [];

        return [
            'status' => $run['status'],
            'seconds' => $run['duration_seconds'],
            'suite_time' => $tests['time'],
            'tests' => $tests['tests'],
            'assertions' => $tests['assertions'],
            'failures' => $tests['failures'] + $tests['errors'],
            'skipped' => $tests['skipped'],
            'test262_tests' => $suites['test262']['tests'] ?? 0,
            'porcelain_tests' => $suites['porcelain']['tests'] ?? 0,
            'other_tests' => $suites['other']['tests'] ?? 0,
            'coverage' => $coverage['lines']['percent'],
            'executable' => $coverage['lines']['executable'],
            'executed' => $coverage['lines']['executed'],
            'code_lines' => $coverage['lines']['code'],
            'comment_lines' => $coverage['lines']['comments'],
            'methods' => $coverage['methods']['count'],
            'methods_covered' => $coverage['methods']['percent'],
            'classes' => $coverage['classes']['count'],
            'classes_covered' => $coverage['classes']['percent'],
            'spec_coverage' => $coverage['by_dir']['/Spec']['lines']['percent'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->fail(sprintf('cannot read %s', $path));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->fail(sprintf('%s is not valid JSON', $path));
        }

        return $decoded;
    }

    /**
     * @return never
     */
    private function fail(string $message): void
    {
        fwrite(STDERR, sprintf("error: %s\n", $message));
        exit(1);
    }
}
