<?php

declare(strict_types=1);

namespace Calendrics\Tools\Stats;

/**
 * Turns raw git output into tools/Stats/data/git.json.
 *
 * Reads build/stats/raw/numstat.txt (written by collect-git.sh) plus a stream of
 * per-commit `git ls-tree` output on stdin. Line counts are accumulated from the
 * churn rather than read out of every blob: master is linear, so summing each
 * commit's added/deleted per bucket reproduces the tree's line count for a
 * fraction of the I/O.
 */
final class GitHistoryBuilder
{
    private const UNIT = "\x1f";

    /** @var list<string> */
    private const BUCKETS = ['src', 'tests', 'test262_data', 'test262_scripts', 'tools', 'docs', 'config'];

    /** @var array<string, array<string, mixed>> */
    private array $commits = [];

    /** @var list<string> */
    private array $order = [];

    /** @var array<string, list<string>> */
    private array $tags = [];

    public function __construct(
        private readonly string $numstatPath,
        private readonly string $tagsPath,
        private readonly string $outputPath,
    ) {
    }

    public static function bucket(string $path): string
    {
        return match (true) {
            str_starts_with($path, 'src/') => 'src',
            str_starts_with($path, 'tests/Test262/data/') => 'test262_data',
            str_starts_with($path, 'tests/Test262/scripts/') => 'test262_scripts',
            str_starts_with($path, 'tests/') => 'tests',
            str_starts_with($path, 'tools/') => 'tools',
            str_ends_with($path, '.md') => 'docs',
            default => 'config',
        };
    }

    public function run(): void
    {
        $this->readTags();
        $this->readChurn();
        $this->readTrees();
        $this->write();
    }

    private function readTags(): void
    {
        if (!is_file($this->tagsPath)) {
            return;
        }

        foreach (file($this->tagsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $fields = explode("\t", $line);
            if (count($fields) < 2) {
                continue;
            }

            // An annotated tag names a tag object, so the dereferenced target is
            // the commit; a lightweight tag names the commit directly.
            $target = ($fields[2] ?? '') !== '' ? $fields[2] : $fields[1];
            $this->tags[$target][] = $fields[0];
        }
    }

    /**
     * Walks the `git log --numstat` dump, recording each commit's metadata and
     * carrying a running per-bucket line total forward.
     */
    private function readChurn(): void
    {
        $handle = fopen($this->numstatPath, 'r');
        if ($handle === false) {
            $this->fail(sprintf('cannot read %s', $this->numstatPath));
        }

        $running = array_fill_keys(self::BUCKETS, 0);
        $sha = null;

        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\n");
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'C' . self::UNIT)) {
                if ($sha !== null) {
                    $this->commits[$sha]['loc'] = $this->withTotal($running);
                }
                $sha = $this->startCommit($line);
                continue;
            }

            if ($sha === null) {
                continue;
            }

            $parts = explode("\t", $line);
            if (count($parts) !== 3) {
                continue;
            }

            [$added, $deleted, $path] = $parts;
            // Binary files report "-"; they carry no lines to count.
            if ($added === '-' || $deleted === '-') {
                continue;
            }

            $bucket = self::bucket($path);
            $running[$bucket] += (int) $added - (int) $deleted;

            $this->commits[$sha]['churn']['added'] += (int) $added;
            $this->commits[$sha]['churn']['deleted'] += (int) $deleted;
            $this->commits[$sha]['churn']['files_touched']++;
        }

        fclose($handle);

        if ($sha !== null) {
            $this->commits[$sha]['loc'] = $this->withTotal($running);
        }

        fprintf(STDERR, "    churn: %d commits\n", count($this->commits));
    }

    /**
     * @return string the sha of the commit that was started
     */
    private function startCommit(string $line): string
    {
        $fields = explode(self::UNIT, $line);
        if (count($fields) < 5) {
            $this->fail(sprintf('malformed commit header: %s', $line));
        }

        $sha = substr($fields[1], 1);
        $timestamp = (int) substr($fields[2], 1);
        $author = substr($fields[3], 1);
        $subject = substr($fields[4], 1);

        preg_match('/^([a-z]+)(?:\([^)]*\))?(!)?: /', $subject, $match);

        $this->commits[$sha] = [
            'sha' => $sha,
            'short' => substr($sha, 0, 9),
            'timestamp' => $timestamp,
            'date' => gmdate('Y-m-d', $timestamp),
            'author' => $author,
            'subject' => $subject,
            'type' => $match[1] ?? null,
            'breaking' => ($match[2] ?? '') === '!',
            'tags' => $this->tags[$sha] ?? [],
            'churn' => ['added' => 0, 'deleted' => 0, 'files_touched' => 0],
            'loc' => array_fill_keys([...self::BUCKETS, 'total'], 0),
            'files' => array_fill_keys([...self::BUCKETS, 'total'], 0),
            'bytes' => array_fill_keys([...self::BUCKETS, 'total'], 0),
        ];
        $this->order[] = $sha;

        return $sha;
    }

    /**
     * Consumes the piped `git ls-tree -r -l` stream, one block per commit, and
     * folds each block into file and byte counts per bucket.
     */
    private function readTrees(): void
    {
        $sha = null;
        $files = [];
        $bytes = [];

        while (($line = fgets(STDIN)) !== false) {
            $line = rtrim($line, "\n");
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'C' . self::UNIT)) {
                $this->storeTree($sha, $files, $bytes);
                $sha = substr($line, 2);
                $files = array_fill_keys(self::BUCKETS, 0);
                $bytes = array_fill_keys(self::BUCKETS, 0);
                continue;
            }

            $tab = strpos($line, "\t");
            if ($tab === false || $sha === null) {
                continue;
            }

            $meta = preg_split('/\s+/', substr($line, 0, $tab));
            if ($meta === false || count($meta) < 4 || $meta[1] !== 'blob') {
                continue;
            }

            $bucket = self::bucket(substr($line, $tab + 1));
            $files[$bucket]++;
            $bytes[$bucket] += (int) $meta[3];
        }

        $this->storeTree($sha, $files, $bytes);
    }

    /**
     * @param array<string, int> $files
     * @param array<string, int> $bytes
     */
    private function storeTree(?string $sha, array $files, array $bytes): void
    {
        if ($sha === null || !array_key_exists($sha, $this->commits)) {
            return;
        }

        $this->commits[$sha]['files'] = $this->withTotal($files);
        $this->commits[$sha]['bytes'] = $this->withTotal($bytes);
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function withTotal(array $counts): array
    {
        return [...$counts, 'total' => array_sum($counts)];
    }

    private function write(): void
    {
        $records = array_values(array_map(fn(string $sha): array => $this->commits[$sha], $this->order));

        $json = json_encode(
            ['ref_collected_at' => gmdate('c'), 'commits' => $records],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            $this->fail('cannot encode git.json');
        }

        file_put_contents($this->outputPath, $json . "\n");

        $last = $records[array_key_last($records)] ?? null;
        fprintf(
            STDERR,
            "==> wrote %s (%d commits)\n",
            $this->outputPath,
            count($records),
        );
        if (is_array($last)) {
            fprintf(
                STDERR,
                "    at tip: src %s LOC, hand-written tests %s LOC, %s files tracked\n",
                number_format($last['loc']['src']),
                number_format($last['loc']['tests']),
                number_format($last['files']['total']),
            );
        }
    }

    /**
     * @return never
     */
    private function fail(string $message): void
    {
        fprintf(STDERR, "error: %s\n", $message);
        exit(1);
    }
}
