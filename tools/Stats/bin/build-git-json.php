<?php

declare(strict_types=1);

use Calendrics\Tools\Stats\GitHistoryBuilder;

require_once __DIR__ . '/../GitHistoryBuilder.php';

new GitHistoryBuilder(
    dirname(__DIR__, 3) . '/build/stats/raw/numstat.txt',
    dirname(__DIR__, 3) . '/build/stats/raw/tags.tsv',
    dirname(__DIR__, 3) . '/tools/Stats/data/git.json',
)->run();
