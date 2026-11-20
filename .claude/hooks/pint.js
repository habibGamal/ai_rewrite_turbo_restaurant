// PostToolUse hook: run Laravel Pint on PHP files edited inside larament/.
const { spawnSync } = require('child_process');
const path = require('path');
let raw = '';
process.stdin.on('data', (d) => (raw += d));
process.stdin.on('end', () => {
  try {
    const input = JSON.parse(raw);
    const file = input.tool_input && input.tool_input.file_path;
    if (!file || !file.toLowerCase().endsWith('.php')) return;
    const app = path.resolve(__dirname, '..', '..', 'larament');
    const rel = path.relative(app, path.resolve(file));
    if (rel.startsWith('..') || path.isAbsolute(rel)) return;
    if (/^(vendor|node_modules|storage)[\/]/.test(rel)) return;
    spawnSync('php', ['vendor/bin/pint', rel], { cwd: app, stdio: 'ignore' });
  } catch (e) {
    // never block editing because of a formatter problem
  }
});
