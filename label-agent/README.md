# Turbo Label (Windows print app)

A small Windows tray app that prints barcode labels from the POS on any
label printer installed on the PC (USB or network), through the printer's
Windows driver. It also prints a drawer's receipts and opens its cash
drawer when the POS is hosted away from the shop (see «Receipts» below). The POS draws every label as an image at the printer's
resolution; this app only prints what it is sent, so label designs are
changed in the POS («تصميمات الملصقات»), never here.

## How it works

1. The POS page calls `http://127.0.0.1:47123/print` with a signed link to
   a print job (`labels.jobs.manifest`).
2. The first time a POS address asks, the app asks the person at the PC to
   allow it. Allowed addresses are kept in `%APPDATA%\TurboLabel\config.json`.
3. The app downloads the page images from the POS and prints each one, as
   many times as asked, at the label size with no margins and no scaling.

If the app is not running, the POS opens `turbo-label://start`, which the
app registers for the current Windows user, then tries again.

| Endpoint | |
| --- | --- |
| `GET /status` | `{ok, version, printer, allowed}`; open to any page |
| `GET /printers` | installed printers and the chosen one |
| `POST /print` | `{manifest, printer?}` → `{ok, labels}` or `{ok: false, error}` |

The server listens on 127.0.0.1 only, so nothing else on the network can
reach it. The manifest and its images must come from the same address as
the page that asked.

## Receipts and the cash drawer (multi-terminal, hosted POS)

A POS on a VPS can't reach a USB or LAN receipt printer at the counter.
A point of sale («منافذ البيع» in the POS) set to print «عن طريق برنامج Turbo Label»
queues its ESC/POS bytes on the server instead (receipt, drawer kick, test
page). The app on that drawer's PC is paired with the drawer and asks the
POS for them every 1.5 s, sends them to the printer as RAW, and reports
back; the cashier sees the printer's error, or that the app isn't
connected.

| POS endpoint (Bearer = the drawer's token) | |
| --- | --- |
| `POST /api/print-agent/hello` | `{version, machine, printers}`, every minute |
| `GET /api/print-agent/jobs` | waiting jobs `[{id, kind, label, printer, data(base64)}]` |
| `POST /api/print-agent/jobs/{id}` | `{ok, error?}` |

Pairing: in the POS, «منافذ البيع» → «ربط برنامج الطباعة» shows a code
(`TL1.` + base64url `{s: server, t: token, n: drawer}`) and a «ربط البرنامج»
button that opens `turbo-label://pair?code=...`. Opened on the drawer's PC,
the app asks once and pairs. The code can also be pasted in the window
(«ربط بمنفذ بيع...»). A new code or «إلغاء ربط البرنامج» in the POS stops the
old one.

The receipt printer is the one chosen for the drawer in the POS, else the
app's «طابعة الفواتير». It must take ESC/POS (thermal receipt printers);
the "Generic / Text Only" driver always works for RAW printing.

## The window

Double-click the tray icon (or open the exe again) for:

- «منافذ البيع والفواتير»: paired points of sale with their status, pair / unpair.
- «الطابعات»: label printer and receipt printer, test label (any size),
  test receipt, open the cash drawer.
- «المواقع المسموحة»: POS sites allowed to print labels; remove them.
- «السجل»: the last 300 prints and failures.
- «الإعدادات»: start with Windows, port, settings folder.

## Setup on a PC

1. Install the label printer's Windows driver and set its paper size to
   the label size in the driver's preferences (the app also asks for that
   size on every job).
2. Run `TurboLabel.exe` (no installer, no admin rights). It starts with
   Windows from then on (switch off from the tray menu).
3. Double-click the icon next to the clock → «الطابعات» → pick the label
   printer (and the receipt printer for a drawer PC).
4. Print a label from the POS and press «نعم» when asked to allow it.

## Build

Needs the .NET 8 SDK. GitHub Actions builds it on every change under
`label-agent/` (workflow «Build label agent») and keeps `TurboLabel.exe`
as a downloadable artifact.

```
dotnet publish label-agent/src/TurboLabel.csproj -c Release -r win-x64 --self-contained -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -o out
```
