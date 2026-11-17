# Turbo Label (Windows print app)

A small Windows tray app that prints barcode labels from the POS on any
label printer installed on the PC (USB or network), through the printer's
Windows driver. The POS draws every label as an image at the printer's
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

## Setup on a PC

1. Install the label printer's Windows driver and set its paper size to
   the label size in the driver's preferences (the app also asks for that
   size on every job).
2. Run `TurboLabel.exe` (no installer, no admin rights). It starts with
   Windows from then on (switch off from the tray menu).
3. Right-click the icon next to the clock → «طابعة الملصقات» → pick the
   printer.
4. Print a label from the POS and press «نعم» when asked to allow it.

## Build

Needs the .NET 8 SDK. GitHub Actions builds it on every change under
`label-agent/` (workflow «Build label agent») and keeps `TurboLabel.exe`
as a downloadable artifact.

```
dotnet publish label-agent/src/TurboLabel.csproj -c Release -r win-x64 --self-contained -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -o out
```
