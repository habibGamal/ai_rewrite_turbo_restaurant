# Larament — Restaurant ERP, POS & Inventory Management System
## System Features & Technology Stack Documentation

---

## 1. Executive Summary

**Larament** is an enterprise-grade, all-in-one Restaurant Management System (ERP), Point of Sale (POS), Kitchen Display System (KDS), and Inventory Control solution. Designed specifically for food and beverage operations, it combines high-speed counter POS operations with rigorous backend inventory tracking, supplier management, multi-channel order orchestration, thermal hardware automation, and executive analytics.

- **Primary Locale:** Arabic (`ar`) with complete RTL support
- **Currency:** Egyptian Pound (`EGP`)
- **Timezone:** `Africa/Cairo`
- **Application Architecture:** Modern Hybrid — Laravel 11 Backend + FilamentPHP v4 Administration + React 18 / Inertia.js 2.0 POS & Kitchen interfaces

---

## 2. Technology Stack

### 2.1 Backend Core
| Component | Technology / Library | Purpose & Details |
|---|---|---|
| **Base Framework** | **Laravel 11.9+** (PHP 8.2+) | MVC framework, Eloquent ORM, service container, routing, and console commands |
| **Admin Panel** | **FilamentPHP v4** | Modular TALL-stack admin panel powering resource management, custom pages, and reporting dashboards |
| **Frontend Adapter** | **Inertia.js Laravel (v2.0)** | Server-driven SPA architecture linking Laravel controllers to React components without REST boilerplate |
| **Real-time WebSockets** | **Laravel Reverb (v1.5)** | First-party high-performance WebSocket server powering live order dispatch and kitchen notifications |
| **Real-time Broadcasting** | **Laravel Echo & Pusher Protocol** | Event broadcasting system for channels (`web-orders`, `kitchen`, `shift.{id}`) |
| **Database** | **MySQL (v8.0+) / SQLite (Testing)** | Production relational database on port 8085; SQLite in-memory database for fast test suites |
| **Authentication & API** | **Laravel Breeze / Sanctum (v4.0)** | Session-based web authentication for staff and token-based API authentication for external integrations |
| **Telemetry & Observability**| **Laravel Pulse (v1.4) & Laravel Pail (v1.2)** | Application performance monitoring, slow query tracking, and real-time log streaming |

### 2.2 Frontend Core (POS & Kitchen Screen)
| Component | Technology / Library | Purpose & Details |
|---|---|---|
| **UI Framework** | **React 18.2** with **TypeScript 5.0+** | Component-driven reactive UI for high-speed POS cashier desk and Kitchen Display System |
| **SPA Bridge** | **@inertiajs/react (v2.0)** | Client-side adapter providing page visits, form handling, and reactive page props |
| **Component Library** | **Ant Design (antd v5.26)** & **@ant-design/icons** | Rich UI primitives for modals, tables, notification drawers, and complex controls |
| **Accessible Headless UI**| **@headlessui/react (v2.0)** | Fully accessible headless UI primitives for dropdowns, dialogs, and transition states |
| **CSS Framework** | **Tailwind CSS v4** (`@tailwindcss/postcss`) | Utility-first styling with custom Arabic typography and forms plugin |
| **Bundler & Tooling** | **Vite 5.4** & **@vitejs/plugin-react** | Ultra-fast Hot Module Replacement (HMR) and optimized production asset compilation |
| **Route Helper** | **Tightenco Ziggy (v2.0)** | Type-safe Laravel named route generation directly in React JavaScript/TypeScript |

### 2.3 Hardware, Printing & Peripheral Integration
| Component | Technology / Library | Purpose & Details |
|---|---|---|
| **Thermal Printing Engine** | **mike42/escpos-php (v4.0)** | Raw ESC/POS socket and serial communication to standard 80mm/58mm thermal receipt printers |
| **Receipt Rendering** | **Spatie Browsershot (v5.0) & Puppeteer (v24.16)** | Headless Chromium rendering engine generating pixel-perfect graphical receipts from HTML/React |
| **Fallback Print Strategy** | **Wkhtmltoimage** | Alternative image rendering strategy for thermal slip compilation |
| **Arabic Text Shaping** | **khaled.alshamaa/ar-php (v7.0)** | Right-to-Left (RTL) glyph shaping and bidirectional ligatures rendering for ESC/POS hardware |
| **Barcode & Scale Scanners**| **@use-symbology-scanner/react (v1.1)** | Seamless event listener for physical USB/Bluetooth barcode scanners and price-embedded scale barcodes |
| **Canvas & Graphic Export** | **html2canvas (v1.4)** | Client-side DOM-to-canvas rendering for on-the-fly client previews |

### 2.4 Data Processing, Testing & Developer Experience
| Component | Technology / Library | Purpose & Details |
|---|---|---|
| **Spreadsheets & Import** | **Maatwebsite Excel (v3.1)** | Bulk import/export of menus, categories, ingredients, and inventory items from Excel files |
| **Testing Framework** | **Pest PHP (v3.7)** | Parallel test execution engine with plugins for Laravel, Faker, and Livewire |
| **Static Code Analysis** | **Larastan / PHPStan (Level 5)** | Static type safety and strict contract validation across the entire `app/` domain |
| **Code Style & Formatting** | **Laravel Pint (v1.13)** | Opinionated PHP code linter enforcing PSR-12 and Laravel style standards |
| **End-to-End Testing** | **Cypress (v14.5)** with `@cypress/react18` | Browser automation testing for POS transactions and critical ordering workflows |
| **Quality Gate** | `composer review` | Unified pre-push script running Pint formatting, Pest parallel tests, and PHPStan analysis |

---

## 3. Comprehensive Feature Catalog

### 3.1 Point of Sale (POS) Cashier Workspace
- **High-Velocity Order Entry:**
  - Multi-category navigation with product search and live price recalculation.
  - Barcode and electronic weighing-scale barcode scanning (with automatic weight and price extraction based on prefix configuration).
  - Configurable item modifications, sides, extras, and specific kitchen preparation notes (`OrderItemChange`).
  - Real-time cart calculation taking into account subtotal, dine-in service charge, regional delivery fees, and tax.
- **Omnichannel Order Types:**
  - **Dine-In (الصالة):** Visual table selection, table status management, and table type filtering.
  - **Takeaway (تيك أواي):** Quick counter ordering with customer name/phone attachment.
  - **Delivery (توصيل):** Customer address lookup, delivery zone selection, and driver dispatching.
  - **Corporate / Companies (شركات):** Dedicated order handling for corporate accounts and invoiced contracts.
  - **Aggregators / Talabat (طلبات):** Third-party food delivery platform order tagging and reconciliation.
  - **Web Delivery & Web Takeaway:** Native processing for orders coming from web storefronts.
- **Split & Multi-Tender Payments:**
  - Multiple payment types: Cash, Credit Card / Visa, mixed/split payments, and account balance.
  - Change calculation and cash tender assistance.
- **Customer CRM & Rapid Creation:**
  - Fast search by phone number or name.
  - In-place quick customer creation modal (`quickCustomer`) without navigating away from the order cart.
  - Order history review and automatic address assignment.
- **Driver Dispatch Management:**
  - Fast driver creation (`quickDriver`) and direct delivery order linking.
  - Real-time driver status tracking.
- **Cashier Permissions & Governance:**
  - Granular feature toggles: allow/disallow cashier discount application, order cancellations, and item changes via global settings.

---

### 3.2 Shift & Cash Drawer Management
- **Shift Lifecycle Controls:**
  - Forced shift start: cashiers cannot perform POS actions without an open shift (`shift` middleware).
  - Opening cash float recording.
- **Shift Real-Time Tracking:**
  - Shift cash metrics: opening float, cash sales, card sales, and shift expenses paid out.
  - Expected cash in drawer calculated continuously.
- **Shift Closing & Reconciliation:**
  - Cashier actual cash count verification against system-expected figures.
  - Variance detection (shortage / surplus).
  - Automated Shift Summary receipt printing on thermal printers upon shift closure.

---

### 3.3 Kitchen Display System (KDS) & Kitchen Workflow
- **Live Kitchen Board (`/kitchen`):**
  - Dedicated full-screen React KDS updating instantaneously over Laravel Reverb WebSockets.
  - Color-coded tickets based on order status (Pending, Processing, Completed).
  - Elapsed order timer (`OrderTimer`) indicating kitchen wait time and alerting on delayed orders.
  - Item-level and ticket-level status completion triggers.
- **Hardware Kitchen Printing:**
  - Station-specific printing: automatic routing of food items to designated kitchen printers (e.g., Grill, Fryer, Barista, Salad station).
  - Partial ticket printing for kitchen order amendments.

---

### 3.4 Web Orders Hub & Omnichannel API
- **Live Inbound Web Orders Management (`/web-orders`):**
  - Instant sound and visual alerts when new online orders are received via Reverb (`WebOrderReceived` event).
  - Accept or reject controls with cancellation reasons.
  - Order modification capability prior to kitchen confirmation.
  - One-click dispatch to kitchen and status synchronization (Out for Delivery, Completed).
- **External Integration APIs (`routes/api.php`):**
  - `/api/web-orders/place-order`: Public API for website/mobile app checkout.
  - `/api/can-accept-order`: Store availability and operational check.
  - `/api/get-shift-id`: Current operational shift identifier retrieval.
  - `/api/all-products`, `/api/get-products-master`: Complete product catalog, recipes, and price synchronization for multi-branch environments.

---

### 3.5 Inventory, Recipes & Supply Chain Management
- **Three-Tier Product Hierarchy:**
  - **Raw Material Products:** Raw ingredients measured in kilograms, grams, liters, or units (e.g., meat, cheese, flour).
  - **Consumable Products:** Non-edible packaging materials (e.g., bags, cups, containers, napkins).
  - **Manufactured Products:** Menu items sold to customers, composed of raw materials and consumables.
- **Bill of Materials (BOM) & Recipe Formulation (`ProductComponent`):**
  - Ingredient recipes linked to menu items.
  - **Automated Stock Deduction (`OrderStockConversionService`):** Every completed order automatically depletes the exact ingredient quantities from raw material and consumable stock.
- **Inventory Audits & Movements (`InventoryItemMovement`):**
  - Detailed audit log of every stock transaction (Purchase, Sale, Waste, Stocktaking Adjustment).
  - Daily inventory aggregation and daily snapshot freeze (`DailySnapshot`, `toggleDay`).
- **Purchasing Workflow:**
  - **Purchase Invoices (`PurchaseInvoice`):** Supplier invoice logging, item unit costing, invoice taxes, and payment status tracking.
  - **Return Purchase Invoices (`ReturnPurchaseInvoice`):** Return goods to suppliers with cost debiting.
- **Waste & Spoilage Tracking (`Waste`):**
  - Record spoiled, expired, or damaged raw materials and items.
  - Automatic inventory deduction and cost calculation of wasted stock.
- **Physical Stocktaking & Inventory Reconciliation (`Stocktaking`):**
  - Periodic inventory count sheets.
  - Automatic calculation of variance between theoretical (system) stock and actual counted stock.
  - Instant stock balance adjustment upon finalizing stocktaking.
- **Suppliers Management (`Supplier`):**
  - Supplier directory, contact details, commercial terms, and purchase invoice history.

---

### 3.6 Financials & Expense Management
- **Operational & Shift Expenses (`Expense`, `ExpenceType`):**
  - Direct shift expense registration from the cashier screen (e.g., emergency petty cash, cleaning supplies, utility tips).
  - General operational expenses registered in the admin panel.
  - Expense categorization and deduction from shift cash drawer balance.
- **Order Returns & Customer Refunds (`OrderReturn`, `Refund`):**
  - Full or partial order returns with reason codes.
  - Cash or card refund execution.
  - Automatic stock re-crediting for returned manufactured components if re-usable.

---

### 3.7 Hardware, Printing & Peripherals Automation
- **ESC/POS Direct Thermal Printing:**
  - Network (TCP/IP) and USB printer integration via `mike42/escpos-php`.
  - Cashier Receipt Layout: customizable header, official logo, tax registration numbers, itemized breakdown, VAT/service charge calculations, QR code, and Arabic footer.
  - Full Arabic typography support via `khaled.alshamaa/ar-php` ensuring correct Arabic letter shaping and right-to-left layout on thermal paper.
- **Electronic Cash Drawer Trigger:**
  - Software kick pulse sent to the cash drawer via the printer interface (`openCashierDrawer`).
- **Printer Management in Admin:**
  - Network printer subnet scanner (`PrinterScanService`, `/admin/printers/scan`) to automatically detect thermal printers on the local LAN.
  - One-click printer test connection and diagnostic test slip printing.
- **Strategy Pattern Printing (`PrintStrategyFactory`):**
  - Pluggable rendering strategies: `BrowsershotPrintStrategy` (headless Chrome) and `WkhtmltoimagePrintStrategy`.
- **A4 & Thermal Inventory Invoices:**
  - Printable vouchers for Purchase Invoices, Purchase Returns, Waste Slips, and Stocktaking Audits (`InvoicePrintController`).

---

### 3.8 Business Intelligence, Analytics & Filament v4 Reports
The Filament admin panel features an extensive collection of dedicated reporting pages and interactive analytical widgets:
- **Shift Analytics:**
  - *Current Shift Report & Shift Logs:* Real-time view of orders, tender breakdown, discounts, voids, and shift expenses.
  - *Period Shift Report:* Cross-shift financial aggregation over customizable date ranges.
- **Sales & Category Performance:**
  - *Products Sales Performance Report:* Volume, revenue, unit cost, and gross profit per product.
  - *Category Performance Widget:* Revenue distribution across food and beverage categories.
- **Channel & Order Type Analytics:**
  - *Channel Performance Report:* Comparative metrics across Dine-in, Takeaway, Delivery, Web Orders, and Aggregators (Market Share widget).
- **Customer Insights:**
  - *Customers Performance Report:* Top customers by sales volume and net profit.
  - *Customer Activity Trends & Loyalty Insights:* Frequency analysis, repeat rate, and customer segmentation.
- **Driver Logistics Performance:**
  - *Driver Performance Report:* Total deliveries, delivery fees generated, average delivery completion times, and driver rankings.
- **Operational Optimization:**
  - *Peak Hours Performance Report:* Hourly order density, revenue curves, and operational bottleneck detection.
  - *Daily Comparison Report:* Day-over-day and period-over-period sales and profit comparison charts.
- **Inventory & Stock Health:**
  - *Stock Report:* Real-time stock levels, inventory monetary valuation, and low-stock alerts.

---

### 3.9 System Administration, Security & Multi-Node Architecture
- **Role-Based Access Control (RBAC):**
  - Pre-configured roles (`UserRole`): `Admin` (full administrative rights), `Cashier` (POS operations), `Kitchen` (KDS display only), `Viewer` (read-only reporting), and `Watcher` (auditing).
- **Dynamic Database Settings Engine:**
  - Database-backed configuration (`Setting` model & `SettingsService`) with memory caching and validation.
  - Manageable through both the Filament Admin UI (`/admin/settings`) and Artisan CLI (`php artisan settings`).
  - Key settings: Website URL, Cashier Printer IP, Service Charge percentages, Receipt Footers, Scale Barcode Prefixes, Node Synchronization links, and Cashier permission toggles.
- **Data Import & Migration Utility:**
  - Bulk Excel data importer (`ExcelImport.php`, `SpecificDataImportService`) for instant catalog onboarding.
  - Legacy data migration console command (`MigrateOldDataCommand`).
- **Multi-Node & Ops Management Integration:**
  - Distributed node support (`NODE_TYPE`: independent, master, branch) for multi-branch restaurant synchronization.
  - Dedicated management API endpoints (`/api/management/*`) allowing the ops dashboard (`manage_operations`) to trigger deployments, service restarts, and health checks remotely.

---

## 4. Directory Structure Overview

```
larament/
├── app/
│   ├── Actions/Orders/         # Single-responsibility domain actions (Create, Cancel, Complete, Discount)
│   ├── Console/Commands/       # CLI commands (Deploy, Settings, Inventory Aggregation, Migration)
│   ├── DTOs/                   # Data Transfer Objects for order payloads, stock movements, and payments
│   ├── Enums/                  # Strongly-typed Enums (OrderStatus, OrderType, UserRole, SettingKey, etc.)
│   ├── Filament/
│   │   ├── Pages/              # Admin pages (Settings, BranchManagement, ExcelImport, 11+ Report Pages)
│   │   ├── Resources/          # 20+ CRUD Resources (Orders, Products, Purchases, Stocktaking, etc.)
│   │   └── Widgets/            # 30+ Metric widgets, charts, and data tables
│   ├── Http/Controllers/       # POS, Web Orders, Kitchen, Expenses, Printers, and API controllers
│   ├── Models/                 # 30+ Eloquent models with business relationships and scopes
│   ├── Repositories/           # Order, OrderItem, and Payment repositories
│   └── Services/               # Domain services (Stock, Shifts, Printing, Calculation, Reports)
├── resources/
│   ├── js/
│   │   ├── Components/         # React POS components (Cart, Table selector, Payment, Scanner, Modals)
│   │   │   ├── Kitchen/        # KDS cards and display controls
│   │   │   ├── Orders/         # Order index tabs, category cards, order item modifiers
│   │   │   └── Print/          # Thermal and A4 receipt templates
│   │   └── Pages/              # Inertia pages (Orders/Index, ManageOrder, Kitchen, Shifts, Profile)
├── routes/
│   ├── api.php                 # Public and internal REST APIs (Web orders, Catalog sync, Node ops)
│   ├── channels.php            # Real-time WebSocket broadcasting authorization channels
│   └── web.php                 # Web routes (Inertia POS, KDS, Shifts, Printing, Admin redirects)
└── tests/
    ├── Feature/                # Pest feature and integration tests
    └── Unit/                   # Pest unit and architectural tests
```
