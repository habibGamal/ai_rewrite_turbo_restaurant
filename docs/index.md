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
