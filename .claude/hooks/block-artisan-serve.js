// PreToolUse hook: AGENTS.md forbids `php artisan serve` (the user manages dev servers).
let raw = '';
process.stdin.on('data', (d) => (raw += d));
process.stdin.on('end', () => {
  try {
    const input = JSON.parse(raw);
    const cmd = (input.tool_input && input.tool_input.command) || '';
    if (/artisan\s+serve\b/.test(cmd) || /composer\s+(run\s+)?dev\b/.test(cmd)) {
      console.error('Blocked: the user manages dev servers themselves (see AGENTS.md). Do not run `php artisan serve` or `composer dev`.');
      process.exit(2);
    }
  } catch (e) {}
});
