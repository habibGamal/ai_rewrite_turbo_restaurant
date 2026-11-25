# Printing, labels & Turbo Label

Three kinds of printing live in the POS:

| What | How it reaches the printer |
|---|---|
| Receipts, cash drawer kick, kitchen tickets (ESC/POS) | From the server to an IP / shared printer (`PrintService`), **or** queued for the Turbo Label app on a shop PC (print agent). |
| Barcode labels | Rendered as black-and-white PNG pages on the server, printed by Turbo Label through the Windows driver, or from a browser print page. |
| Badge cards, purchase invoice print | Browser print pages. |

## Receipts and the print agent

Each sales point (`terminals`) and each kitchen printer (`printers`) implements `App\PrintAgent\PrintAgentTarget` and has `print_via`:

- `server` (default): `PrintService` sends ESC/POS bytes to the target's printer (IP or shared name). The receipt printer is the device's sales point `receipt_printer`, else the `cashier_printer_ip` setting.
- `agent`: the bytes are queued in `print_agent_jobs` (`PrintAgentQueue::push()`) for the Turbo Label app paired with that target. Use this when the POS runs on a VPS and can't reach a USB/LAN printer in the shop.

Which sales point prints a receipt or drawer kick: the device's bound sales point, else the order's sales point (admin buttons), else `Terminal::default()` when multi-terminal is **off**. So a single-counter shop can still print through the agent, provided the default point was set to `agent` (the «منافذ البيع» screen is only visible while multi-terminal is on).

`PrintService` waits up to `PRINT_AGENT_WAIT_SECONDS` (default 6) for the app to report back (`PrintAgentQueue::await()`). A failure, or an app that isn't connected, is thrown so the cashier sees the printer's error. Jobs nobody picks up in time are failed, not printed late.

### Agent API

`routes/api.php`, `throttle:900,1`, Bearer = the target's token:

| Endpoint | |
|---|---|
| `POST /api/print-agent/hello` | `{version, machine, printers}`, every minute. Stores the printer list shown in the admin select. |
| `GET /api/print-agent/jobs` | Waiting jobs `[{id, kind, label, printer, data(base64)}]`, oldest first, marked sent. |
| `POST /api/print-agent/jobs/{id}` | `{ok, error?}` |

### Pairing

«ربط برنامج الطباعة» on a sales point or printer calls `PrintAgentQueue::pair()`: a new token (the old one stops working) and a code `TL1.` + base64url JSON `{s: server, t: token, n: name, k: targetKey}`. The «ربط البرنامج» button opens `turbo-label://pair?code=…` on the shop PC; the code can also be pasted in the app. One PC can serve several targets of the same POS. «إلغاء ربط البرنامج» revokes the token.

On a sales point that prints through the agent, the cashier screen checks `http://127.0.0.1:47123/status` every 10 s. While the app doesn't answer, a warning bar shows and the next click anywhere opens `turbo-label://start` (browsers only launch apps from a user gesture), so the queued receipt prints once the app is up.

::: tip Fixed in #78
`PrintService` must import `App\Models\Terminal`. Without it, `Terminal::default()` resolved to `App\Services\Terminal` and every cashier print crashed while multi-terminal was off.
:::

## Barcode labels

Behind `OptionalFeature::LABEL_PRINTING`. Code in `app/Labels`.

- **Designs** (`label_templates`, `LabelTemplateResource`, «تصميمات الملصقات» under products): width/height in mm, labels per row and gap, printer DPI, default flag, and positioned elements (`LabelElementType`: shop name, product name, variant, barcode bars, barcode text, price, fixed text with `{name} {variant} {barcode} {price} {shop}`). A 38×25 mm starter design is seeded.
- **Rendering** (`LabelRenderer`, `LabelImageMaker`): HTML at one CSS pixel per printer dot → `wkhtmltoimage` → GD threshold to pure black and white. Arabic shapes correctly on any printer and bars land on whole dots. Cached page images are stored base64 so the database cache store can hold them.
- **Jobs** (`LabelPrintJobs`): products × copies (max 10,000 labels) laid out into printed rows; runs of identical rows are stored once with a copy count. Jobs live in the cache for 120 minutes and are served over signed URLs: `labels.jobs.manifest` (`/labels/jobs/{job}`) and `labels.jobs.page`. `labels.jobs.browser` is the browser fallback.
- **Actions** (`App\Filament\Actions\PrintLabelsAction`, needs `print_labels`): purchase invoices (counts = quantities bought, rounded up), product group variants, bulk on product lists; and, with `OptionalFeature::DOCUMENT_BARCODES`, purchase returns, stock issues and stock transfers. Products without a barcode are skipped.
- A small admin script posts the manifest to Turbo Label (`POST http://127.0.0.1:47123/print`), starts it through `turbo-label://start` when it doesn't answer, and otherwise offers «اطبع من المتصفح».

## Purchase barcodes

`OptionalFeature::PURCHASE_BARCODES`: «توليد باركود للأصناف» on the purchase invoice gives every line whose product has no barcode the next code of the series (`purchase_barcode_prefix`, `purchase_barcode_digits`, `purchase_barcode_next_number`). The counter row is locked, taken codes and codes that would parse as scale labels are skipped, and the code is saved on the product.

`OptionalFeature::DOCUMENT_BARCODES` shows a read-only barcode per line on purchase invoices, purchase returns, stock issues, stock transfers and waste. Every stock document also has a «مسح باركود» box (scan adds the product or one more on its line); it finds retired SKUs too, which the POS still won't sell.

## The Turbo Label app

`label-agent/`: a .NET 8 Windows tray app (single-file `TurboLabel.exe`, built by the «Build label agent» GitHub Actions workflow). See `label-agent/README.md` for its endpoints and build command.

- Listens on `127.0.0.1:47123` only (`/status`, `/printers`, `/print`). The first time a POS address asks to print labels, the person at the PC must allow it; allowed sites and settings are kept in `%APPDATA%\TurboLabel\config.json`.
- Downloads the label page images over the signed links and prints each at the label size with no margins or scaling, through the Windows driver.
- Polls `/api/print-agent/jobs` every 1.5 s for each paired target and prints the bytes RAW on the target's printer, else its own «طابعة الفواتير» (must take ESC/POS; "Generic / Text Only" always works).
- Registers `turbo-label://` (start, pair) and starts with Windows. Double-click the tray icon for the window: paired targets, printers with test label / test receipt / open drawer, allowed sites, the last 300 prints, settings.

## Receipt content

The thermal receipt template (`resources/views/print/receipt-template.blade.php`) prints custom fee and equipment lines through `print/receipt-charges.blade.php` (`ReceiptView::fee_lines`), and order notes (including instant sale notes). The `#<order id>` reference barcode (`print/receipt-reference.blade.php`) is used by returns lookup and is hidden while returns are off.
