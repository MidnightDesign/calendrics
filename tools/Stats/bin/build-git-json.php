<?php

declare(strict_types=1);

use Calendrics\Tools\Stats\GitHistoryBuilder;

require_once __DIR__ . '/../GitHistoryBuilder.php';

(new GitHistoryBuilder(
    '/app/build/stats/raw/numstat.txt',
    '/app/build/stats/raw/tags.tsv',
    '/app/tools/Stats/data/git.json',
))->run();
