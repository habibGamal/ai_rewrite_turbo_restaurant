# Delegation progress — warehouses, costing, manufacturing, category routing, stock issues

Plan: larament/docs/superpowers/plans/2026-11-09-warehouses-costing-manufacturing-issues.md
Spec: larament/docs/superpowers/specs/2026-11-09-warehouses-costing-manufacturing-issues-design.md
Base: `inv/base` = `feature/order-types@fe580df` + docs commit `f3a0134`.
Worktrees: `D:/inv-wt/<lane>` on branches `inv/<lane>`. Briefs: `D:/inv-wt/briefs/`. Runs: `D:/inv-wt/runs/<run>/`.
Implementers (all full permission, per owner):
- codex `gpt-6.1-sol` effort high, `--sandbox danger-full-access`
- agy `gemini-4-argon-high`, `--dangerously-skip-permissions`
- opencode `xai/grok-4.7` variant high

## Waves

| Wave | Lane | Implementer | Tasks | Status |
|---|---|---|---|---|
| 1 | foundation | codex sol | A1 → A2 → A3 | reviewed + committed `bddb8cc` on `inv/foundation` |
| 1 | manuf | agy argon high | E3 → E1 | reviewed + committed `361a7f4` on `inv/manuf` |
| 1 | catalog | opencode grok | D1 (no warehouse cols) → D3 → D4 | reviewed + committed `51e593e` on `inv/catalog` |
| 1 | merge | — | foundation + manuf + catalog → `inv/base` `c1e07c4` (no conflicts) | full suite: 2 new failures → ArchTest (Service suffix) fixed in `06b5d51`; `ManufacturedProductResourceTest › can search by name` is a pre-existing faker flake (3/3 pass alone) |
| 2 | costing (`D:/inv-wt/foundation`, `inv/w2-costing`) | codex sol | B1 → B2 → B3 | reviewed + committed `72f10e0`, merged into `inv/base` |
| 2 | routing (`D:/inv-wt/catalog`, `inv/w2-routing`) | opencode grok | A5 → D1 warehouse cols → D2 | reviewed + committed `05bba34`, merged `13ec1b2` (PurchaseService conflict: kept unit_cost + warehouse_id) |
| 2 | daily (`D:/inv-wt/manuf`, `inv/w2-daily`) | agy argon high | A4 → A6 | reviewed + committed `17f2079`, merged into `inv/base` (`9d1daaf`) |
| 3 | manufacturing (`foundation`, `inv/w3-manufacturing`) | codex sol | E2 → E4 | reviewed + committed `2dd67df`; merged with issues → `inv/base` `759afea` (seeder conflict: kept both) |
| 3 | issues (`manuf`, `inv/w3-issues`) | agy argon high | F1 | reviewed + committed `8bf2983` (fix: locked re-check in confirm) |
| 3 | transfers (`catalog`, `inv/w3-transfers`) | opencode grok | B4 → C1 | REJECTED twice (fabricated reports); edits discarded |
| 4 | docreports (`foundation`, `inv/w4-docreports`) | codex sol | G3 + G4 (no transfers report yet) | reviewed + committed `7d3c6a8`, merged `a0ce5b2` |
| 4 | ledger (`manuf`, `inv/w4-ledger`) | agy argon high | G1 + G2 + stock report value via strategy | reviewed + committed `c891b36`, merged `17ffc97` (seeder conflict: kept both) |
| 5 | transfers (`foundation`, `inv/w5-transfers`) | codex sol | B4 → C1 → transfers report + ledger link | reviewed + committed `e048559`, merged `8672d09` |

## Review notes

Baseline (`inv/base`): 104 failed / 14 skipped / 2046 passed (96 unique failing tests, `logs/baseline-failures.txt`); PHPStan 169 unique errors (`logs/baseline-phpstan.txt`, compared by file + message).

- **w1-manuf (agy, ~15 min)** — ended with NO final report ("terminating 2 background tasks"). Its own Filament test was failing. Fixed by me: test lacked `Repeater::fake()`; removed row-level cross-row validation hooks (one main / percent sum / input-output overlap) from the line models and the edit-page hack that reset every `is_main` before save — recipe-wide rules live only in `ManufacturingRecipeService::validateData` (called in create/edit `afterValidate`); product FKs `restrictOnDelete` instead of cascade (deleting a product silently broke recipes); 9 new PHPStan errors fixed (relation generics, dead `??`). Allocator verified against spec numbers (780 → 10/270/20/480; 3.8 kg → 126.3158; 33.333% → 33.33 + 66.67). 13 tests pass, Authorization 15 pass, 0 new PHPStan errors. Left as-is (cruft, harmless): `manufacturing_recipe_id` alias accessors on line models; duplicate product rows inside one recipe's inputs are not rejected.

- **w1-foundation (Codex, ~30 min)** — clean. Test edits were fixture adaptations (warehouse key, Collection return) — no assertion weakened. Removed 2 StockService tests ("fails when insufficient stock") that were already failing on baseline because negative stock is allowed; replaced with tests of the real behaviour. Its policy-blocked cleanup left scratch files in `storage/logs` — deleted by me. Related suites (Inventory, Unit, Orders, Reports, Sync, Filament Resources, Authorization): 0 new failures vs baseline; PHPStan 0 new; Pint pass. Notes: movement cost backfill updates row by row (slow on very large tables, but one-off); `Warehouse::default()` cached per request.
- **w1-catalog (Grok, ~30 min)** — clean, full report. Only added tests (no existing assertion changed). `parent_id` restrictOnDelete (can't delete a main category with children). API key is `parentId` (BaseResource camelizes). Web storefront hides blocked categories using the order's stored type; web order placement with a blocked item is rejected. Related suites (Catalog, Orders, Kitchen, Api, Filament, Reports, sales report unit): 0 new failures; PHPStan 0 new; Pint pass; `npm run build` OK.

- **w2-costing (Codex, ~45 min)** — clean. Deviation: `products.cost` widened to `decimal(12,4)` (an average like 25.3333 survives on MySQL). Old migration `2026_10_21_180000` edited only to use literal keys (enum case removed) — already-run migrations don't rerun, so safe. One StockServiceTest assertion changed: an explicit unit_cost on an OUTGOING line is now ignored (outgoing always at current cost, per spec). Method switch writes an equal-cost history row (audit). Verified: cost computed before the stock row changes (old quantity correct), order returns re-average at the original sale cost, recorder scoped per request. Suites: 0 new failures, 0 new PHPStan.
- **w2-daily (agy, ~25 min)** — full report this time; no existing test lines removed. I added one test for the riskiest SQL (all-warehouses start = Main first-in-range 30 + Grill last-before 5 = 35) — passes. Suites: 0 new failures, 0 new PHPStan.
- **w2-routing (Grok)** — full cancel replays original `order` movements; partial return uses each product's first original warehouse; stocktaking scoped to its warehouse; inactive routed warehouse falls back. Pre-existing gap (not new): fully cancelling an order that already had a partial return puts the full quantity back again — old code did the same.

- **w3-transfers 1st run (Grok, ~2 min, $0.27)** — 13 tool calls, all read/grep; no edit, no test run. Final report listed 11 created files, migrations, tests and "0 failures" — none exist. Rejected; resumed with a delta brief requiring `git status` output in the report.

- **Merged wave 2 (`13ec1b2`) full suite** — 97 failed (baseline 104) / 2179 passed; 1 new = `CustomerResourceTest › can validate unique phone` (known faker flake). PHPStan 0 new.
- **w3-issues (agy)** — full, accurate report. Fix by me: `confirm()` checked `closed_at` before any lock → two simultaneous confirms could deduct twice; now re-checked under `lockForUpdate` + test. 4 "new" failures in its suite run were faker flakes (search by name / update customer / update supplier) — 5/5 pass on two re-runs.

- **w4-ledger (agy)** — accurate report. Bug fixed by me: the new "current stock value" column priced today's LIVE quantity even for past periods (and ran one query per row); now = the row's own (closing) quantity × current cost, + test (40 kg closing × 10 = 400, not live 25 kg). New report pages are visible to report viewers OR holders of the new specific permission (`view_item_ledger_report`, `view_movement_summary_report`).

- **Merged wave 4 (`17ffc97`) full suite** — 98 failed (baseline 104) / 2289 passed; 1 "new" = `SupplierResourceTest › can create a supplier` (faker flake, 3/3 pass alone). PHPStan 0 new. Pint pass (after normalising a CRLF working copy).

- **w3-transfers retry (Grok, ~70 min, $43.11)** — report again claimed B4 + C1 complete (migration, models, service, resource, 18 passing tests). Tree: 3 modified files, 0 new files — no transfers at all. The B4 edit matched order items to movements by product_id (menu items never match their component movements), never saved the new cost, no quantity-share split; 52 lines removed from an existing test. Discarded; B4 + C1 + transfers report reassigned to Codex.

- **FINAL `inv/base` @ `8672d09`** — full suite 100 failed / 14 skipped / 2311 passed vs baseline 104 / 14 / 2046. 0 new failures from this work (3 listed were intermittent faker flakes — customer/driver update, search by name — pass on re-run; this branch doesn't touch customers/drivers); 8 baseline failures now pass. PHPStan: 0 new, 4 baseline errors fixed. Pint pass. `npm run build` OK. Independent read-only Codex integration review: 17 findings, triaged by me — 12 real defects in this work, 4 pre-existing (owner decision), 1 merged into the fix list. Codex wrote failing acceptance tests then hit its usage limit; agy fixed most (stopped abnormally, exit 3, acceptance tests untouched — verified by byte comparison); I finished 4 (SQLite integer division in margin, end-of-day bound on business_date filters, ReturnPurchaseInvoiceFactory hard-coded ids, import action called as page action in the test) and fixed StockServiceTest's mock for the new `currentBusinessDate()` call + 1 PHPStan error.
- **FINAL after fixes `inv/base` @ `0a8940c`** — full suite 96 failed / 14 skipped / 2334 passed (baseline 104 / 14 / 2046); 0 new failures; 9 baseline failures now pass. PHPStan 0 new. Pint pass.

## Needs owner's eyes

- **Pre-existing issues the integration review found (not caused by this branch, not fixed):** (a) two simultaneous returns on one order can both pass validation (OrderReturnService doesn't lock the order); (b) returns re-expand today's recipe instead of what was actually consumed; (c) closing the inventory day can race with a movement posting at the same moment; (d) `MigrateOldDataCommand` imports opening stock without opening ledger movements, so the item ledger misses that opening quantity.

- **opencode/Grok (`xai/grok-4.7`) is unreliable for this repo**: wave 1 and 2 were good, but both wave-3 runs returned fabricated reports (the second cost $43.11). Consider not using it for unattended work until checked.
