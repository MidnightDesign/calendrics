<?php

declare(strict_types=1);

use Calendrics\Spec\Instant;
use Calendrics\Spec\Internal\Calendar\IntlCalendarFactory;

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

$process = proc_open([$argv[1] ?? '/tmp/calendrics-hour-pattern'], [['pipe', 'r'], ['pipe', 'w'], STDERR], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the native ICU diagnostic.');
}

$instant = Instant::from('2024-01-01T09:04:05Z');
$failures = 0;
$cases = 0;
foreach (['ko', 'en-US', 'en-GB', 'af-NA'] as $locale) {
    foreach (['gregory', 'iso8601', 'hebrew', 'buddhist', 'japanese', 'persian'] as $calendar) {
        $nativeLocale = sprintf('%s@calendar=%s;hours=h23', $locale, IntlCalendarFactory::icuType($calendar));
        fwrite($pipes[0], sprintf("%s jmmss\n", $nativeLocale));
        $pattern = fgets($pipes[1]);
        if ($pattern === false) {
            throw new RuntimeException('The native ICU diagnostic did not return a pattern.');
        }
        $formatter = new IntlDateFormatter(
            $nativeLocale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            'UTC',
            IntlCalendarFactory::forFormatting(timeZone: 'UTC', locale: $nativeLocale),
            trim($pattern),
        );
        $expected = $formatter->format(1_704_099_845);
        $actual = $instant->toLocaleString($locale, [
            'calendar' => $calendar,
            'hour' => 'numeric',
            'hourCycle' => 'h23',
            'minute' => '2-digit',
            'second' => '2-digit',
            'timeZone' => 'UTC',
        ]);
        $cases++;
        if ($actual !== $expected) {
            $failures++;
            fprintf(STDERR, "%s / %s: expected %s, got %s\n", $locale, $calendar, $expected, $actual);
        }
    }
}
fclose($pipes[0]);
fclose($pipes[1]);
$status = proc_close($process);
printf("%d calendar/hour cases; %d differences\n", $cases, $failures);
exit($status === 0 && $failures === 0 ? 0 : 1);
