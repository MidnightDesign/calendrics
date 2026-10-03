# Locale formatting benchmark

Run inside the PHP container after installing the checkout's dependencies:

```sh
docker compose exec php php -d xdebug.mode=off tools/benchmark-formatting.php --iterations=1000 --samples=7 --warmup=100
```

The script reports PHP and ICU versions, CLI opcache/Xdebug settings, per-operation samples in their original order and sorted distributions in nanoseconds, retained PHP memory, peak PHP memory and output digests. It uses public date/time formatting methods with repeated and varied dates. It observes performance; it does not pin Temporal conformance results.

Compare branches in separate fresh processes, alternating their order over several runs. Use the same PHP/ICU versions and settings. Record container CPU limits and other active workloads; avoid heavy tests or static analysis while timing. Initial warmup deliberately separates steady-state formatter reuse from autoload and first-call costs. Memory figures describe PHP-managed memory and do not capture all ICU allocations.

Check output digests across the compared runs. An untimed pass hashes every output for the configured iteration count, with a four-byte length prefix per output. The varied-date workload covers all 28 dates when iterations is at least 28; smaller runs cover only their visited dates. Hashing is outside the measured loops. A matching digest is a useful sanity check for the measured workloads, not a substitute for the project test suite or a complete correctness review. Report distributions and relative differences rather than treating one run as a stable absolute latency.
