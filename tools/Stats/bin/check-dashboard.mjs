import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const template = readFileSync(new URL('../dashboard-template.html', import.meta.url), 'utf8');
const script = template.match(/<script>([\s\S]*?)<\/script>/)[1];
const partial = {
  status: 'timeout', tests: null, assertions: null, coverage: null,
  executed: null, executable: null, methods: null, classes: null,
  suite_time: null, passed: null, failures: null, errors: null, skipped: null,
};
for (const run of [null, partial]) {
  const nodes = new Map();
  const document = {
    getElementById(id) {
      if (!nodes.has(id)) nodes.set(id, { innerHTML: '', textContent: '' });
      return nodes.get(id);
    },
    querySelector() { return null; },
  };
  const commit = {
    sha: 'abc123', date: '2026-10-03', timestamp: 1, run,
    loc: { src: 1, tests: 1, test262_data: 1, test262_scripts: 1 },
    files: { test262_data: 1, test262_scripts: 1 },
  };
  runInNewContext(script.replace('/*__STATS_DATA__*/null', JSON.stringify({
    generated_at: 'fixture', commits: [commit],
  })), { document, window: { addEventListener() {} } });
  const table = nodes.get('tablehost').innerHTML;
  assert.ok(table.includes(run ? 'timeout' : 'unmeasured'));
  assert.ok(table.includes('unknown'));
  assert.ok(!nodes.get('tiles').innerHTML.includes('null'));
  assert.ok(!table.includes('NaN'));
}
console.log('Dashboard missing/partial-record execution checks passed.');
