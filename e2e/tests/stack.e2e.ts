// Exact checks only (no agent, no model): the stack is up and the two apps are linked.
import { describe, test } from '@e2e-dev/web';
import { expect } from 'e2e';

const POS_URL = process.env.POS_URL ?? 'http://localhost:8001';
const API_KEY = process.env.E2E_WEB_API_KEY ?? '193b6c8a03c1e8d097b8b942ded69113448c1239c66c47ba';

describe('stack', () => {
  test('the POS accepts website orders with the shared API key', async ({ request }) => {
    const response = await request.get(`${POS_URL}/api/can-accept-order`, { headers: { 'X-Api-Key': API_KEY } });
    expect(response).toBeOK();
    expect(await response.json()).toEqual({ can_accept: true });
  });

  test('the POS rejects a wrong API key', async ({ request }) => {
    const response = await request.get(`${POS_URL}/api/can-accept-order`, { headers: { 'X-Api-Key': 'wrong-key-wrong-key' } });
    expect(response.status).toBe(401);
  });

  test('the storefront opens with the e2e menu', async ({ app, screen }) => {
    await app.open('/');
    await expect(screen.getByText('E2E Espresso').first()).toBeVisible();
  });
});
