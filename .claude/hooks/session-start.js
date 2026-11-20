// SessionStart hook: only in Claude Code cloud sessions, install larament's
// PHP dependencies via the fast path in larament/scripts/cloud-setup.sh.
// Does nothing on local machines (Windows included).
const { spawnSync } = require('child_process');
const path = require('path');
if (process.env.CLAUDE_CODE_REMOTE !== 'true') process.exit(0);
const script = path.resolve(__dirname, '..', '..', 'larament', 'scripts', 'cloud-setup.sh');
const r = spawnSync('bash', [script], { stdio: ['ignore', process.stderr, process.stderr] });
process.exit(r.status === 0 ? 0 : 1); // non-blocking: a failure only shows a notice
