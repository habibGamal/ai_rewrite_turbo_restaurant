import type { E2EConfig } from 'e2e';
import { web } from '@e2e-dev/web';
import { acp } from '@e2e-dev/acp';

// URLs come from e2e/.e2e/urls.env (written by `stack.sh seed`) or the runner container.
const SITE_URL = process.env.SITE_URL ?? 'http://e2e.localhost:8000';

// agent.act / agent.assert run on agy (Antigravity CLI) over ACP.
// `--process-per-session` is required because e2e hands the agent its step tools
// through an HTTP MCP server. E2E_AGY_MODEL must match a model agy offers; when it
// does not, e2e prints the list. Set E2E_AGENT=none to run only the exact steps.
const agyArgs = ['acp', '--process-per-session', ...(process.env.E2E_AGY_ARGS ?? '').split(' ').filter(Boolean)];

export default {
  targets: [
    {
      name: 'chromium',
      engine: web({ viewport: { width: 1280, height: 800 } }),
      app: { url: SITE_URL },
    },
  ],
  ...(process.env.E2E_AGENT === 'none'
    ? {}
    : {
        agents: {
          default: {
            executor: acp({
              name: 'agy',
              command: process.env.E2E_AGY_COMMAND ?? 'agy',
              args: agyArgs,
              ...(process.env.E2E_AGY_MODEL ? { model: process.env.E2E_AGY_MODEL } : {}),
              system:
                'You test an Arabic (RTL) restaurant ordering website and its POS. Buttons and labels are mostly Arabic. Never place real payments; only use the test data you are given.',
            }),
          },
        },
      }),
  timeout: 180_000,
  workers: 1,
  reporters: ['list', 'junit'],
  // Keep data use low: no video, trace only when a test fails.
  video: 'off',
  trace: 'retain-on-failure',
} satisfies E2EConfig;
