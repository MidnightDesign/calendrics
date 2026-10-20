<?php

declare(strict_types=1);

use Calendrics\Tools\Stats\DashboardRenderer;

require_once __DIR__ . '/../DashboardRenderer.php';

@mkdir(dirname(__DIR__, 3) . '/build/stats', 0777, true);

new DashboardRenderer(
    dirname(__DIR__, 3) . '/tools/Stats/data/git.json',
    dirname(__DIR__, 3) . '/tools/Stats/data/runs',
    dirname(__DIR__, 3) . '/tools/Stats/dashboard-template.html',
    dirname(__DIR__, 3) . '/build/stats/dashboard.html',
)->render();
