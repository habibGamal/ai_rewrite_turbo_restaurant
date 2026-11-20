# KDS Redesign — Kitchen Display System + Configuration

## Context

The current KDS (`/kitchen`, [Kitchen/Index.tsx](resources/js/Pages/Kitchen/Index.tsx), [KitchenOrderCard.tsx](resources/js/Components/Kitchen/KitchenOrderCard.tsx)) is display-only. Cooks can't mark items or orders done, nothing records timing, it refreshes by polling every 10 seconds, it puts the newest order first, and it is tied to the current shift. Item changes are shown from `OrderItemChange` rows, which are hard to read.

Goal: a KDS that
- shows order changes clearly (increases, decreases, removals, new items added later)
- stays usable with 20+ active orders
- tracks three separate clocks
- alerts cooks with configurable sounds
- uses configurable lateness thresholds that never flag an order whose kitchen work is already finished

**Key finding.** `OrderItemRepository::updateOrderItems()` ([OrderItemRepository.php:25](app/Repositories/OrderItemRepository.php:25)) **deletes and recreates every `order_items` row on each save**. Kitchen state and timing therefore cannot live on `order_items`. They need their own tables, kept in step with the order's items by comparing the two (reconciliation).

## Decisions already made with the user

- **Three clocks:**
  1. Order lifecycle: `orders.created_at` → close. Exists already; no work needed.
  2. Kitchen order time.
  3. Per-item prep time.
- **Kitchen order time counts only the time something was pending.** It is made of *rounds*. A round opens when items arrive while nothing is pending, and closes when the last pending item is done. Total kitchen time is the sum of the rounds. Lateness is judged on the open round only. Example: food finished at 12 min and a dessert added at minute 40 gives 12 min + the dessert's own time.
- **Stations:** a new `kitchen_stations` list. Each category picks a station, and a product can override it. A screen shows one station or all of them. A ticket is done when every station has finished its part.
- **Completing or paying an order auto-finishes its kitchen ticket.** This is a setting, on by default. Items finished this way are flagged `auto_finished` and left out of prep-time statistics.
- **Kitchen data stays local to each branch.** No `HasSyncUuid` and no outbox records.
- **Layout:** the enterprise KDS pattern (see UI section). Your answer on layout was "tell me what enterprise systems do". That pattern is used here as the proposal, pending your approval.

## Data model (new migrations)

- `kitchen_stations`: `id, name, is_active, sort_order, timestamps`.
- `categories`, new columns:
  - `kitchen_station_id` (nullable FK)
  - `show_on_kds` (bool, default true)
  - `prep_minutes` (nullable smallint)
- `products`, new columns (null = inherit from the category):
  - `kitchen_station_id` (nullable FK)
  - `show_on_kds` (nullable bool)
  - `prep_minutes` (nullable)
- `kitchen_tickets`: one per order.
  - `order_id` (unique FK, cascade)
  - `status` (`active|done|cancelled`)
  - `is_rush`
  - `first_sent_at`, `done_at`, `cancelled_at`
  - `seen_at`: the "new" badge shows until this is set
  - `cancel_acknowledged_at`
  - `auto_finished`
  - timestamps; index on `status`
- `kitchen_ticket_rounds`: `id, kitchen_ticket_id, number, started_at, ended_at (null = open)`.
- `kitchen_ticket_items`: kitchen-side lines, never deleted.
  - Links: `kitchen_ticket_id`, `kitchen_ticket_round_id`
  - Snapshot at send time: `product_id`, `product_name`, `kitchen_station_id`, `target_minutes`
  - `quantity` decimal(10,3); `original_quantity` (the quantity as first sent)
  - `notes`
  - `kind`: `original` (in the first send), `added` (a product new to the order), `increased` (+N of a product already sent)
  - `status`: `pending|done|voided`
  - Times: `sent_at`, `done_at`, `voided_at`
  - `done_by` (user id)
  - Flags: `voided_after_done` (made but not needed, a waste candidate), `needs_ack`, `auto_finished`
- New enums in `app/Enums/`: `KitchenTicketStatus`, `KitchenItemStatus`, `KitchenItemKind`.
- Models: `KitchenStation`, `KitchenTicket`, `KitchenTicketRound`, `KitchenTicketItem`, plus a `kitchenTicket()` relation on `Order`.

## Backend services (`app/Services/Kitchen/`)

### KitchenRoutingService

For a product, resolves three things. In each case the product value wins, then the category value, then the fallback.
- **Station:** product → category → none ("general").
- **Shown on the KDS:** product → category → true.
- **Target minutes:** product → category → the order-type override in `kds_order_type_targets` → `kds_default_prep_minutes`.

### KitchenTicketService

Holds all kitchen state changes. Each runs in a transaction and broadcasts after commit.

- **`syncOrder(Order)`** reconciles the ticket with the order's items. Items are matched by `product_id`, the same key used by `ShiftLoggingService::calculateItemDifferences`. For each product, compare the order quantity with the quantity on non-voided kitchen lines:
  - **More in the order:** create a new line in the open round (open a round if none is open). Its `kind` is `original`, `added` or `increased`, and it gets its own `sent_at`. A line already done is never touched, so "2 done + 1 new" shows as two lines.
  - **Less in the order:** reduce pending lines first, newest first. Keep `original_quantity` so the card can show 3→2, and set `needs_ack`. Any remaining difference voids done lines, newest first, with `voided_after_done = true` and `needs_ack`. Fractional quantities split a line.
  - **Product removed:** the same as "less", down to 0.
  - **Notes changed:** update the notes on pending lines and set `needs_ack`. On done lines, only set `needs_ack` as information.
  - Products where `show_on_kds = false` are ignored.
  - Then call `closeRoundIfIdle`.
- **`finishItem`, `undoItem`:**
  - `finishItem` sets `done_at` and `done_by`.
  - `undoItem` works only within `kds_recall_minutes`. If the round had already closed, it reopens that round (`ended_at = null`) and sets the ticket back to `active`.
- **`finishTicket(ticket, ?stationId)`:** marks all pending lines done, limited to one station when a station is given.
- **`acknowledge(ticket)`:** sets `seen_at` and clears `needs_ack`.
- **`setRush(ticket, bool)`.**
- **`cancelForOrder(order)`:** sets the ticket to `cancelled` and voids all lines (done lines become `voided_after_done`). The ticket stays on screen until `cancel_acknowledged_at` is set.
- **`autoFinishForOrder(order)`:** runs only when `kds_auto_finish_on_complete` is on. Marks the remaining lines done with `auto_finished = true`.
- **`closeRoundIfIdle`:** when no pending lines remain, close the round and set the ticket to `done` with `done_at`.
- **Timing helpers:**
  - kitchen seconds = Σ round durations, plus the time elapsed in the open round
  - round target = the largest `target_minutes` among unfinished lines in the open round
  - state: `normal`, `warning` from `kds_warning_percent` of the target, `late` past the target
  - the same logic per line

### Hook points

Sync runs only when the order status is `processing`.

- **Item edits:** call it inside `OrderService::updateOrderItems()` ([OrderService.php:58](app/Services/Orders/OrderService.php:58)), after `orderCreationService->updateOrderItems`. Every save path is then covered, not only `OrderController::saveOrder`.
- **Web orders:** call `syncOrder` in `WebApiService::acceptOrder()` ([WebApiService.php:220](app/Services/WebApiService.php:220)). Web orders are placed as `pending` and should reach the kitchen only once accepted. Verify the status `placeOrder` uses.
- **Cancellation:** call `cancelForOrder` from `OrderService::cancelOrder` and `WebApiService::rejectOrder`.
- **Completion:** call `autoFinishForOrder` from `OrderService::completeOrder` and `WebApiService::completeOrder`.
- **Type change:** `OrderService::changeOrderType` re-broadcasts the ticket, because the targets may change.
- **Leave as is:** `OrderItemChangeService` and the old changes print template.

### Realtime

- New event `App\Events\Kitchen\KitchenTicketUpdated` (`ShouldBroadcast`, `afterCommit`) on `PrivateChannel('kitchen')`. Its payload is `ticket_id`, `order_number`, `reason` (`new|changed|cancelled|done`) and the station ids involved.
- Tighten [routes/channels.php:20](routes/channels.php:20) so that only users with `kitchen.access` can join.

### Controller and routes

Rework `KitchenController` and keep `GET /kitchen` with `permission:kitchen.access`.

- **`index` props:**
  - `tickets`: active tickets, tickets done within the recall window, and cancelled tickets not yet acknowledged
  - `stations`
  - `kdsSettings`
  - `serverNow`, to correct for clock differences
- The query is **no longer tied to the current shift**, because web orders can carry over to the next shift. The "no active shift" message is only shown when there are no tickets.
- Shape the data with a `KitchenTicketResource` (or an array builder).
- **New routes** under `/kitchen`, all `kitchen.access`, each returning a redirect back for an Inertia partial reload:
  - `POST items/{item}/done`
  - `POST items/{item}/undo`
  - `POST tickets/{ticket}/done` (optional `station_id`)
  - `POST tickets/{ticket}/acknowledge`
  - `POST tickets/{ticket}/rush` (also reachable from the cashier with `pos.access`)

### Backfill

New command `kitchen:backfill-open-orders`. It creates tickets for `processing` orders that already have items, with `sent_at = now()`, so the first deploy does not show everything as late. Add it to `deploy.sh` only after review.

## Configuration

### Global settings

Add these to the `SettingKey` enum. Every new case needs `defaultValue`, `validationRules`, `label`, `helperText` and `placeholder` match arms. Add a new "شاشة المطبخ" section in [Settings.php](app/Filament/Pages/Settings.php) using the existing pattern.

| Key | Type | Default | Meaning |
|---|---|---|---|
| `kds_item_tracking` | bool | true | Cooks can mark individual items done. When off, only the whole ticket can be marked done, and its items take the ticket's time. |
| `kds_default_prep_minutes` | int | 10 | Fallback target. |
| `kds_order_type_targets` | JSON map type→min | `{}` | Optional target per order type. Use a KeyValue input over `OrderType` cases. |
| `kds_warning_percent` | int 1–100 | 75 | The card turns yellow at this % of the target and red past it. |
| `kds_auto_finish_on_complete` | bool | true | Completing or paying an order finishes its kitchen ticket. |
| `kds_recall_minutes` | int | 15 | How long done tickets stay recallable or undoable. |
| `kds_new_order_sound` / `kds_change_sound` / `kds_cancel_sound` | select | `chime`/`bell`/`beep`/`alert`/`none` (defaults: chime, bell, alert) | Tones are synthesized in the browser with the Web Audio API; no audio files. |
| `kds_sound_volume` | int 0–100 | 80 | |
| `kds_reminder_seconds` | int | 30 (0 = off) | Repeats a sound while there are unseen new or changed tickets. |

The JSON value is stored as a string, since settings are string key-value pairs.

### Per category and product

In the Categories, ManufacturedProducts and ConsumableProducts Filament resources, add:
- a station select
- a `show_on_kds` toggle (tri-state on products: inherit, show or hide)
- a `prep_minutes` field

Also add a new `KitchenStations` Filament resource (simple CRUD).

### Per screen

Saved in the browser's `localStorage` through a gear menu on the KDS:
- station filter (all or one)
- number of columns (auto, or 3–6)
- font scale
- mute
- "test sound" button

## KDS UI

Rewrite `Kitchen/Index.tsx`. Add new components in `resources/js/Components/Kitchen/`. Keep antd + Tailwind, as the app uses now.

**Layout (the enterprise pattern used by Toast, Square, Oracle MICROS and Fresh KDS):**
- Fixed columns and **no vertical scrolling**. Cooks read the screen from a distance and with greasy hands.
- Ordering: rush tickets first, then oldest first by the open round's start. In RTL the oldest sits top-right.
- A ticket keeps its place. A ticket that receives a new round re-enters the order by that round's start, so it does not jump to the top.
- Long tickets **continue into the next column** with a "يتبع" marker.
- **Horizontal paging:** buttons, swipe, and arrow keys (for a future bump bar). The next-page arrow shows a count and a red dot when late tickets are hidden on other pages, so late orders are never out of sight.
- **Top bar:** station name, counts (جديد / تحذير / متأخر), search by order number (jumps to the card's page and highlights it), all-day toggle, recall drawer, connection dot, clock, gear.

**Card:**
- **Header** colored by state (normal / warning / late), with:
  - order number and type icon
  - table or customer
  - the kitchen timer for the open round
  - a rush badge
  - a "جديد" badge until the ticket is seen
- **Body** is grouped by round. Round 1 lines come first. Each later round is its own section, "إضافة ٢ — 10:42", with its own timer.
- **Line styles:**
  - pending: normal
  - done: dimmed with a check
  - `increased`: "+1 جديد" highlight
  - reduced: "~~3~~ → 2" with a red −1 badge
  - voided: struck through, "ملغي"
  - voided after done: "ملغي بعد التحضير"
- Item notes and `kitchen_notes` are shown prominently.
- **Actions:**
  - tap a line to mark it done (when item tracking is on)
  - tap again within the recall window to undo
  - footer: "تم" finishes the ticket, or this station's part of it
  - a "تم الاطلاع" button appears while `needs_ack` lines exist
- **Cancelled ticket:** red overlay, "الطلب ملغي". It stays until acknowledged.

**All-day panel:** the total pending quantity per product across the visible tickets.

**Recall drawer:** recently done tickets, with undo.

**Hooks:**
- **`useKitchenChannel`:**
  - listens with Echo on `private('kitchen')` for `.KitchenTicketUpdated` and reloads only `tickets`
  - shows a "disconnected" banner using Echo/Pusher connection state
  - polls every 30s as a fallback
  - follows the `window.Echo.private("web-orders")` pattern in [CashierLayout.tsx:75](resources/js/Layouts/CashierLayout.tsx:75)
- **`useKdsSounds`:**
  - a full-screen "اضغط للبدء" overlay on load, needed because browsers block sound until the user interacts with the page
  - picks a sound by `reason`, at the configured volume
  - runs the reminder loop
  - Wake Lock API, so the screen stays on
- **`useKdsClock`:** one 1-second ticker, corrected with `serverNow`.

## Reports (last phase)

The item start step was removed; item prep time is `done_at − sent_at`. Round start timing remains unchanged.

New `app/Filament/Pages/Reports/KitchenPerformanceReport.php`, following the existing `BaseDashboard` report pattern. Filter by date range. It shows:
- average kitchen time per order type
- % of rounds that were late
- average prep time per product and per station (excluding `auto_finished`)
- slowest products
- count of items voided after done (waste candidates)

## Delivery phases

Each phase is a commit point and must pass `composer review`.

1. **Domain.** Migrations, enums, models, `KitchenRoutingService`, `KitchenTicketService`, hook points, broadcast event, channel auth, backfill command, plus Pest tests.
2. **Configuration.** `SettingKey` additions with the Settings section, `KitchenStations` resource, category/product fields, plus Filament tests.
3. **KDS UI.** Controller props and routes, page and components, sounds (Web Audio tones), realtime, per-screen settings. Then `npm run build`.
4. **Kitchen performance report.**

## Execution: OpenCode delegation in parallel

Lanes come from `~/.config/delegate-skills/config.json`:
- `complex` = `xai/grok-4.7#high`
- `glm-flash`
- `deepseek-flash`

Every run is reviewed by me (Claude) before I commit it.

**Branch.** A new branch `feature/kds`, cut from the current HEAD.

**Step 0, foundation** (one `complex` run, in the main checkout). It produces everything the parallel tracks share:
- all migrations
- enums
- models and relations
- factories
- the `SettingKey` cases with all their match arms
- the TypeScript types for the KDS props in `resources/js/types/kitchen.ts`

I review it, run `pest` and `phpstan`, and commit it.

**Step 1, parallel.** Four git worktrees branch from the foundation commit. Each runs its own OpenCode instance in the background and has its own vendor install (`composer install`, plus `npm ci` where a frontend build is needed) and a copied `.env`.

| Track | Lane | Owns |
|---|---|---|
| A, kitchen domain | complex | `app/Services/Kitchen/*`, hook points in `OrderService`/`WebApiService`, the event, channel auth, `KitchenController` and routes, ticket resource, backfill command, `tests/Feature/Kitchen/*` |
| B, configuration | glm-flash | The Settings page section, `KitchenStations` resource, category/product form fields, and their Filament tests |
| C, KDS UI | complex | `resources/js/Pages/Kitchen/*`, `Components/Kitchen/*`, hooks. Sounds are Web Audio tones. It builds against the props contract in `types/kitchen.ts` |
| D, report | deepseek-flash | `KitchenPerformanceReport` page, its service and tests |

No two tracks touch the same file.

**Step 2, integration** (me):
1. Review each track's diff and gates, and send fix-ups with `--resume-last` where needed.
2. Merge the four branches into `feature/kds`.
3. Run `composer review` and `npm run build` on the merged result.
4. Run the manual scenarios below. You run the dev servers.

## Tests (Pest, `tests/Feature/Kitchen/`)

- **`KitchenTicketServiceTest`:**
  - first sync creates a ticket, a round and lines
  - increasing a pending item adds an `increased` line
  - increasing after done adds a new line and keeps the done line
  - decreasing a pending line keeps `original_quantity` and sets `needs_ack`
  - decreasing below the pending quantity voids a done line with `voided_after_done`
  - removing a product
  - a notes change
  - products with `show_on_kds = false` are ignored
  - station routing: product override vs category
  - **round timing:** done at 12 min, dessert at minute 40 gives kitchen time 12 + dessert time, and the closed round is not late
  - undo reopens a closed round
  - cancelling voids lines and waits for acknowledgement
  - auto-finish on complete respects the setting and flags `auto_finished`
  - a web order is not synced while `pending`, and is synced on accept
  - `Event::fake()` checks that `KitchenTicketUpdated` is dispatched
- **`KitchenControllerTest`:** permissions (`kitchen.access`), each action endpoint, and that the index is not tied to a shift.
- **Filament:** the Settings page saves KDS keys, and `KitchenStations` CRUD works.

## Verification

1. Run `composer review` (Pint → Pest → PHPStan) and `npm run build`.
2. You run the dev servers (`composer dev`, never `php artisan serve`).
3. Open the POS as admin in one window and `/kitchen` as the seeded kitchen user in another. Walk through these scenarios:
   - new order → sound plays and the card appears
   - 3 burgers → 2: a "3→2" badge appears, and acknowledging clears it
   - 2 → 3 after done: a new "+1" line appears with its own timer
   - a different item added later: a "إضافة ٢" section appears and the timer restarts for that round
   - cancel: red overlay
   - complete or pay: the ticket auto-finishes
   - 20+ orders: paging works and the late-on-other-page indicator shows
   - stop Reverb: the disconnected banner appears and polling keeps the screen updated
4. Optionally, add a Cypress spec `cypress/e2e/kitchen.cy.ts` for the bump and change flows.

## Out of scope

- Replacing kitchen printing (the manual "print in kitchen" stays as it is)
- Syncing kitchen data across branches
- Courses / hold-and-fire
- Scheduled pre-orders
- Per-cook login tracking (`done_by` is just the logged-in screen user)
- Hardware bump-bar mapping beyond arrow keys

## Shared contracts (fixed before the parallel tracks start)

- `resources/js/types/kitchen.ts`: Inertia props of `GET /kitchen` and the broadcast payload. Track A must output exactly this shape. Track C must consume it.
- Item and ticket actions are plain `POST` routes that return `back()`:
  - `kitchen.items.done` → `/kitchen/items/{item}/done`
  - `kitchen.items.undo` → `/kitchen/items/{item}/undo`
  - `kitchen.tickets.done` → `/kitchen/tickets/{ticket}/done`, with an optional `station_id` in the body
  - `kitchen.tickets.acknowledge` → `/kitchen/tickets/{ticket}/acknowledge`
  - `kitchen.tickets.rush` → `/kitchen/tickets/{ticket}/rush`, with `is_rush` (bool) in the body
- Broadcast: `App\Events\Kitchen\KitchenTicketUpdated`. Its `broadcastAs()` returns `KitchenTicketUpdated`, so the client listens for `.KitchenTicketUpdated` on `private-kitchen`.
