<?php
// usage: php uncov.php <relPath> <line> [<line>...]
$rel = $argv[1];
$xml = 'build/coverage/coverage-xml/' . $rel . '.xml';
$d = new DOMDocument(); $d->load($xml);
$x = new DOMXPath($d);
$x->registerNamespace('p', 'https://schema.phpunit.de/coverage/1.0');
$covered = [];
foreach ($x->query('//p:coverage/p:line') as $ln) { $covered[(int) $ln->getAttribute('nr')] = true; }
foreach (array_slice($argv, 2) as $n) {
    $n = (int) $n;
    printf("%s:%d %s\n", $rel, $n, array_key_exists($n, $covered) ? 'COVERED' : 'not-covered');
}
