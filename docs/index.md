---
layout: home

hero:
  name: Turbo POS
  text: One point of sale for restaurants, cafés and shops
  tagline: Laravel + Filament admin, a fast React cashier, kitchen display, inventory, and a linked online-ordering website. Business-type modules switch features on per install.
  image:
    src: /logo.svg
    alt: Turbo POS
  actions:
    - theme: brand
      text: Technical handbook
      link: /technical/
    - theme: alt
      text: دليل المستخدم (عربي)
      link: /ar/guide/
    - theme: alt
      text: Website API
      link: /technical/website-integration

features:
  - icon: 🧩
    title: Modular core
    details: Restaurant, Delivery, Web orders, Manufacturing, External sellers and Retail are modules on a shared core, wired through domain events.
    link: /technical/modules
  - icon: 🛒
    title: Instant sale & variants
    details: Scan-and-pay cashier tab for shops, product groups with any number of option axes (size, colour, fit) and per-SKU price overrides.
    link: /technical/modules#retail
  - icon: 🍽️
    title: Restaurant workflow
    details: Tables and floor designer, kitchen tickets and a live kitchen display over Reverb, guest counts and service charge.
    link: /technical/orders-and-events
  - icon: 💵
    title: Honest shift money
    details: Returns, custom fees, equipment, other revenues and expenses all reach the shift's expected cash, with an append-only audit log.
    link: /technical/money
  - icon: 🌐
    title: Online ordering
    details: The turbo_tenant website places orders over an API-key protected endpoint with idempotent retries and status callbacks.
    link: /technical/website-integration
  - icon: 🔐
    title: Vendor control
    details: A hidden vendor account sets the business type, modules and paid features. Shop admins never see it.
    link: /technical/vendor
---

## Features

- **Point of sale:** Order-type tabs, instant barcode/scale selling, held carts, dine-in tables and floor grid, delivery dispatch, split and deferred payments, order fees and equipment rentals.
- **Shifts and cash:** Opening float, shift cash tracking, multi-terminal sessions and handovers, expenses, other revenues, refunds and returns, and audit logging.
- **Kitchen and printing:** Live KDS over Reverb, station routing, ESC/POS thermal receipt printing, network printer scanning, and barcode label templates via Turbo Label.
- **Inventory and purchasing:** Raw materials, consumables, manufactured recipes (BOM), multiple warehouses, costing methods (immediate or weighted average), purchase invoices, purchase returns, waste, stocktaking, transfers and issues.
- **Online ordering and sync:** Linked `turbo_tenant` storefront API with idempotent retries and status callbacks, master/slave catalog sync, and central platform outbox sync.
- **Administration and analytics:** Role-based permissions, Filament 5 reports with Excel export, and a read-only remote MCP server for AI assistants.
