<?php

declare(strict_types=1);

// Git identities are supplied by the host; PHP and dependency versions describe
// the container that actually replays the commit, not its historical machine.
[$script, $root, $sha, $collector, $corpus, $generated, $output] = $_SERVER['argv'];
$installedPath = $root . '/vendor/composer/installed.json';
$installed = json_decode(file_get_contents($installedPath), true, flags: JSON_THROW_ON_ERROR);
$versions = [];
foreach ($installed['packages'] ?? $installed as $package) {
    $versions[$package['name']] = $package['version'];
}
ksort($versions);
$collectorFiles = [];
foreach (['RunParser.php', 'collect-tests.sh', 'bin/parse-run.php', 'bin/provenance.php'] as $file) {
    $collectorFiles[$file] = hash_file('sha256', dirname(__DIR__) . '/' . $file);
}
$record = [
    'source_sha' => $sha,
    'collector_sha' => $collector,
    'collector_files_sha256' => $collectorFiles,
    'recorded_at' => gmdate('c'),
    'php_version' => PHP_VERSION,
    'icu_version' => defined('INTL_ICU_VERSION') ? INTL_ICU_VERSION : null,
    'phpunit_version' => $versions['phpunit/phpunit'] ?? null,
    'installed_packages_sha256' => hash('sha256', json_encode($versions, JSON_THROW_ON_ERROR)),
    'source_lock_sha256' => is_file($root . '/composer.lock') ? hash_file('sha256', $root . '/composer.lock') : null,
    'phpunit_config_sha256' => is_file($root . '/phpunit.xml') ? hash_file('sha256', $root . '/phpunit.xml') : null,
    'test262_data_tree' => preg_match('/^[a-f0-9]{40}$/', $corpus) ? $corpus : null,
    'test262_scripts_tree' => preg_match('/^[a-f0-9]{40}$/', $generated) ? $generated : null,
    'test262_upstream_revision' => null,
    'scope' => 'phpunit tests/; all discovered tests, generated variants counted separately',
    'command' => 'php -d memory_limit=1G vendor/bin/phpunit tests/ --coverage-xml build/coverage/coverage-xml --log-junit build/coverage/junit.xml',
    'dependencies' => 'shared current vendor tree; historical lock is recorded, not installed',
];
file_put_contents(
    $output,
    json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);
