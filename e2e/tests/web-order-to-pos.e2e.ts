// The main cross-system flow: a customer orders on the website, the cashier accepts it
// on the POS, and the website shows the new status. Agent steps run on agy.
import { describe, test } from '@e2e-dev/web';
import { expect } from 'e2e';

const POS_URL = process.env.POS_URL ?? 'http://localhost:8001';

describe('website order reaches the POS', () => {
  test('a cash-on-delivery order is accepted on the POS and the website shows it in preparation', async ({ app, agent, browser }) => {
    // Website: sign in as the seeded customer (has a saved address) and order one espresso.
    await app.open('/');
    await agent.act('sign in to the store with email {email} and password {password}', {
      params: { email: 'admin@example.com', password: 'password' },
    });
    await agent.act('add one "E2E Espresso" to the cart and go to checkout');
    await agent.act('place the order for delivery to the saved address, paying cash on delivery');
    await agent.assert('the order was placed and an order number is shown');

    // POS: the cashier sees the web order and accepts it.
    const site = (await browser.tabs()).at(-1)!;
    const pos = await browser.newTab();
    await app.open(`${POS_URL}/login`);
    await agent.act('sign in to the POS with email {email} and password {password}', {
      params: { email: 'admin@example.com', password: 'password' },
    });
    await agent.act('open the web orders (طلبات الموقع) list and accept the newest pending order');
    await agent.assert('the newest web order is now accepted or in preparation');

    // Website: the customer's order page reflects the POS decision.
    await site.activate();
    await browser.reload();
    await agent.assert('the order status shows it is being prepared (processing), not pending');
    await pos.close();
  });
});
