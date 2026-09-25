<?php

declare(strict_types=1);

use Calendrics\Tools\Stats\DashboardRenderer;

require_once __DIR__ . '/../DashboardRenderer.php';

(new DashboardRenderer(
    '/app/tools/Stats/data/git.json',
    '/app/tools/Stats/data/runs',
    '/app/tools/Stats/dashboard-template.html',
    '/app/build/stats/dashboard.html',
))->render();
