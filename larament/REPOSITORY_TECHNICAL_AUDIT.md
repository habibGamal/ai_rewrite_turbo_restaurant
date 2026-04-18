# Repository Technical Audit

Date: 2026-03-28
Repository: larament
Analysis scope: Static code and configuration review of the current workspace snapshot

## 1. Project Overview

### Project name
- Larament (customized in this repository as a restaurant POS and operations platform)

### Purpose and problem it solves
- Provides a full restaurant operations backbone:
- Point of sale order lifecycle for dine-in, takeaway, delivery, companies, Talabat, and web channels
- Shift and cash control
- Inventory movement, stocktaking, waste, purchase and return purchase flows
- Kitchen and cashier printing workflows
- Admin and reporting dashboards for operations monitoring

### Target users
- End users: cashiers, shift operators, delivery operations staff
- Business users: restaurant managers, inventory operators, finance/operations reviewers
- Developers/admins: technical operators maintaining deployment and management APIs

### Domain
- RestaurantTech / POS / SaaS-style operational platform

### Maturity level
- Production-oriented application with broad business functionality
- Not enterprise-hardened yet due several correctness and operational risk gaps (documented in sections 13 and 14)

## 2. Architecture Analysis

### High-level architecture
- Modular monolith
- Single Laravel application containing:
- Domain services and repositories
- Filament admin panel
- Inertia + React operational frontend
- REST-like API endpoints
- Event broadcasting and optional queue jobs

### Architectural patterns used
- MVC: controllers in app/Http/Controllers, Eloquent models in app/Models, UI in Filament and React pages
- Service layer: extensive business logic in app/Services
- Repository pattern: order and payment persistence abstractions in app/Repositories + Contracts
- DTO usage: typed order/payment DTOs in app/DTOs/Orders
- Strategy pattern: print strategy selection in app/Services/PrintStrategies and PrintService
- Event-driven updates: order/payment/web-order events broadcasting over private channels

### Key components and interaction model
- Web UI (Inertia React) and Filament call controllers
- Controllers orchestrate service calls
- Services use repositories and Eloquent models
- Services dispatch events and optionally enqueue jobs
- Printing pipeline renders Blade templates to image then sends ESC/POS to network or Windows printers
- Inventory service records movement-level audit entries and daily aggregates

### Frontend and backend separation
- Backend:
- Laravel routes, controllers, services, models, Filament resources/pages/widgets
- Frontend:
- React app at resources/js with Inertia pages for cashier/order operations
- Filament frontend for admin/reporting, generated server-side with Livewire components

### API style
- Primary: REST-like HTTP endpoints under /api and / routes
- Realtime: private WebSocket channels (Reverb-compatible broadcasting)
- No GraphQL or RPC detected

## 3. Technology Stack

### Backend
- PHP 8.2+ target in composer, deployment script attempts PHP 8.4 runtime
- Laravel 11
- Filament 4
- Laravel Sanctum package present
- Inertia Laravel adapter
- Maatwebsite Excel, Laravel Pulse, Laravel Reverb

### Frontend
- React 18 + TypeScript + Inertia React
- Vite build pipeline
- Ant Design UI, Tailwind CSS
- Laravel Echo + Pusher JS client for Reverb-compatible realtime

### Data layer
- Primary DB: MySQL (with sqlite defaults for local/testing)
- ORM: Eloquent
- Additional DB connection: old_system (legacy migration source)

### DevOps and infrastructure signals
- Shell deployment script deploy.sh
- Management API endpoints triggering deploy/start/stop/custom artisan operations
- Queue, cache, broadcasting, pulse, and reverb configs present
- No first-class CI config was inspected in this audit (no claim made)

### Realtime and async
- Events implement ShouldBroadcast and ShouldBroadcastNow
- Private channels: web-orders, shift.{id}, kitchen
- Queue jobs for printing exist; current PrintService constant uses direct mode by default

## 4. Codebase Structure

### Folder structure explanation
- app/Models: domain entities
- app/Services: business workflows and reports
- app/Repositories and Contracts: persistence abstraction for order/payment
- app/Http/Controllers: web and api controllers
- app/Http/Requests: request validation rules
- app/Filament: admin resources, pages, widgets, traits
- database/migrations: schema evolution
- resources/js: Inertia React cashier and order interfaces
- routes: web/api/auth/channels/console routing
- tests and cypress: Pest/PHPUnit and browser E2E tests

### Entry points and bootstrap
- bootstrap/app.php configures routing, middleware aliases, and maintenance exceptions
- routes/web.php and routes/api.php are main HTTP surfaces
- resources/js/app.tsx is Inertia React bootstrap
- app/Providers/Filament/AdminPanelProvider.php is Filament panel definition

### Configuration management
- Environment-driven configs under config/
- Dynamic key-value settings table with SettingsService and SettingKey enum
- setting helper in app/Helpers.php reads settings with defaults

## 5. Feature Extraction (Critical)

All features below are inferred from implemented code paths.

### A. Authentication and Authorization

Feature name: Session-based login/logout
- Description: User authentication for web/admin access
- Technical flow:
- Guest routes expose login form and login action
- Authenticated users can logout
- Key files/components:
- routes/auth.php
- app/Http/Controllers/Auth/AuthenticatedSessionController.php
- config/auth.php
- Dependencies:
- Laravel auth/session stack

Feature name: Role and permission model
- Description: Role-based UI and action permissions for admin, cashier, viewer, watcher
- Technical flow:
- User role cast to enum
- Permission checks via User methods and UserRole enum
- Inertia middleware shares canApplyDiscounts/canCancelOrders/canChangeOrderItems
- Key files/components:
- app/Models/User.php
- app/Enums/UserRole.php
- app/Http/Middleware/AddUserPermissions.php
- resources/js/Components/CanAccess.tsx
- Dependencies:
- SettingsService for cashier override toggles

Feature name: Shift-gated operations authorization
- Description: Order-critical operations require active shift
- Technical flow:
- Shift middleware checks open shift (end_at null and closed false)
- Redirects to shift start route if absent
- Key files/components:
- app/Http/Middleware/ShiftMiddleware.php
- bootstrap/app.php
- routes/web.php (shift middleware assignment)

### B. User and Profile Management

Feature name: Profile self-service
- Description: User can view/update profile and delete account
- Technical flow:
- Authenticated profile routes and validation
- Covered by feature tests
- Key files/components:
- routes/web.php (profile endpoints)
- tests/Feature/ProfileTest.php

Feature name: User admin resource
- Description: User management via Filament resource pages
- Technical flow:
- CRUD pages under Filament Users resource namespace
- Key files/components:
- app/Filament/Resources/Users/UserResource.php and pages

### C. Shift and Cash Management

Feature name: Start shift
- Description: Opens a shift with start cash and optional transfer of open web orders
- Technical flow:
- Validates start cash
- Checks no active shift
- Calls ShiftService::startShift in transaction
- Optionally transfers prior open web orders if setting enabled
- Key files/components:
- app/Http/Controllers/OrderController.php (startShift)
- app/Services/ShiftService.php
- app/Enums/SettingKey.php (ALLOW_WEB_ORDERS_SHIFT_TRANSFER)

Feature name: End shift
- Description: Closes current shift and computes expected cash/deficit
- Technical flow:
- Blocks closure when processing/pending/out_for_delivery orders remain (with web-order exceptions by config)
- Computes end cash from completed orders with cash payments
- Stores end values and closure timestamp
- Key files/components:
- app/Http/Controllers/OrderController.php (end route registration)
- app/Services/ShiftService.php

Feature name: Shift operation logging
- Description: Audit-style logging for shift/order/customer/driver actions
- Technical flow:
- Controllers call ShiftLoggingService on key operations
- Key files/components:
- app/Services/ShiftLoggingService.php
- app/Http/Controllers/OrderController.php
- app/Http/Controllers/WebOrderController.php

### D. Order Management Core

Feature name: Create order
- Description: Creates order for multiple channels with table requirement for dine-in
- Technical flow:
- Request validation + enum mapping
- CreateOrderDTO enforces table for dine-in
- Repository assigns next shift order number
- OrderCreated event dispatched
- Key files/components:
- app/Http/Controllers/OrderController.php (createOrder)
- app/DTOs/Orders/CreateOrderDTO.php
- app/Services/Orders/OrderService.php
- app/Services/Orders/OrderCreationService.php
- app/Repositories/OrderRepository.php

Feature name: Manage order items and notes
- Description: Add/remove/update line items, notes, and order notes
- Technical flow:
- SaveOrderRequest validates payload
- Service recalculates totals and discount rules
- Item-level discounts supported
- Key files/components:
- app/Http/Controllers/OrderController.php (saveOrder, updateOrderNotes)
- app/Http/Requests/SaveOrderRequest.php
- app/Services/Orders/OrderService.php
- app/Services/Orders/OrderCalculationService.php

Feature name: Payment and completion
- Description: Completes processing/out_for_delivery orders with single or split payments
- Technical flow:
- CompleteOrderRequest validates amounts
- completeOrder transaction lockForUpdate on order row
- Payments are capped against remaining balance
- payment_status updated to pending/partial/full
- completion updates status, frees table, dispatches events
- Key files/components:
- app/Http/Controllers/OrderController.php (completeOrder)
- app/Http/Requests/CompleteOrderRequest.php
- app/Services/Orders/OrderService.php
- app/Services/Orders/OrderPaymentService.php
- app/Services/Orders/OrderCompletionService.php

Feature name: Cancellation and stock restoration
- Description: Cancels cancellable orders, deletes payments, optionally restores stock if previously completed
- Technical flow:
- Permission check in controller (canCancelOrders)
- Service transaction updates status and zeroes profit
- For completed orders, addStock path invoked
- OrderCancelled event dispatched
- Key files/components:
- app/Http/Controllers/OrderController.php (cancelOrder)
- app/Services/Orders/OrderService.php
- app/Services/Orders/OrderStockConversionService.php

Feature name: Customer and driver quick operations
- Description: Search, fetch, create, and link customer/driver entities to orders
- Technical flow:
- Separate endpoints for quick create, fetch by phone, search by name, and linking
- Key files/components:
- app/Http/Controllers/OrderController.php (quickCustomer, quickDriver, fetch/search/link methods)

Feature name: Table occupancy management for dine-in
- Description: Reserves and frees tables as order type changes and completion/cancellation occurs
- Technical flow:
- TableManagementService validates availability and assigns order_id on DineTable
- Key files/components:
- app/Services/Orders/TableManagementService.php
- app/Services/Orders/OrderService.php
- app/Models/DineTable.php

### E. Web Orders and External Integration

Feature name: Incoming web order ingestion API
- Description: Accepts web orders, validates payload, creates order/items/customer
- Technical flow:
- API validator enforces nested structure and quantity constraints
- WebApiService verifies shift id, upserts customer by phone, creates order, fills items by product_ref mapping, computes web-pos diff and discount, dispatches WebOrderReceived
- Key files/components:
- routes/api.php
- app/Http/Controllers/Api/WebOrdersController.php
- app/Services/WebApiService.php

Feature name: Web order lifecycle management in UI
- Description: Accept/reject/complete/out-for-delivery/apply discount and save notes for web orders
- Technical flow:
- WebOrderController coordinates local OrderService and remote status notification
- Key files/components:
- routes/web.php (/web-orders/*)
- app/Http/Controllers/WebOrderController.php
- app/Services/WebApiService.php
- resources/js/Pages/Orders/ManageWebOrder.tsx

Feature name: External status callback to website
- Description: Sends status updates to configured website URL
- Technical flow:
- Http::post to setting(WEBSITE_URL)/api/order-status with orderNumber and status
- Throws on failed response
- Key files/components:
- app/Services/WebApiService.php (notifyWebOrderWithStatus)
- app/Enums/SettingKey.php (WEBSITE_URL)

### F. Inventory and Stock

Feature name: Day open/close inventory state
- Description: Requires an open inventory day for stock-affecting operations
- Technical flow:
- shouldDayBeOpen helper throws if no open day record
- InventoryController can open/close day with checks for open shifts and unclosed docs
- Key files/components:
- app/Helpers.php
- app/Http/Controllers/InventoryController.php
- app/Services/InventoryDailyAggregationService.php

Feature name: Stock movement engine
- Description: Bulk in/out stock operations with movement audit and daily aggregation updates
- Technical flow:
- StockService validates item shape and product existence
- Outgoing movements optionally validate stock (currently bypassed by constant)
- Writes InventoryItem updates and InventoryItemMovement rows in transaction
- Re-aggregates daily movement counters
- Key files/components:
- app/Services/StockService.php
- app/Models/InventoryItem.php
- app/Models/InventoryItemMovement.php
- app/Models/InventoryItemMovementDaily.php

Feature name: Order stock decomposition
- Description: Converts manufactured product sales into component-level inventory movements
- Technical flow:
- Recursive decomposition via product components
- Consolidates same component quantities
- Applies order completion remove and cancellation add operations
- Key files/components:
- app/Services/Orders/OrderStockConversionService.php
- app/Models/Product.php
- app/Models/ProductComponent.php

Feature name: Waste closure
- Description: Closes waste records and deducts inventory
- Technical flow:
- Loads wasted items, removes stock with reason WASTE, sets total and closed_at
- Key files/components:
- app/Services/WasteService.php
- app/Models/Waste.php
- app/Models/WastedItem.php

Feature name: Stocktaking closure
- Description: Reconciles counted quantity against system stock and applies variance adjustments
- Technical flow:
- Computes variance per item and performs add/remove operations under STOCKTAKING reason
- Key files/components:
- app/Services/StocktakingService.php
- app/Models/Stocktaking.php
- app/Models/StocktakingItem.php

### G. Purchases, Returns, and Refunds

Feature name: Purchase invoice closure
- Description: Adds purchased items to stock and updates product average cost
- Technical flow:
- Validates day open and non-closed invoice
- Updates costs then addStock with PURCHASE reason in transaction
- Key files/components:
- app/Services/PurchaseService.php
- app/Services/ProductCostManagementService.php
- app/Models/PurchaseInvoice.php
- app/Models/PurchaseInvoiceItem.php

Feature name: Return purchase invoice closure
- Description: Removes returned purchase quantities from stock
- Technical flow:
- Validates day open and non-closed invoice
- removeStock with PURCHASE_RETURN reason
- Key files/components:
- app/Services/PurchaseService.php
- app/Models/ReturnPurchaseInvoice.php
- app/Models/ReturnPurchaseInvoiceItem.php

Feature name: Order returns and refunds
- Description: Tracks item-level returns and refund allocations by method
- Technical flow:
- order_returns, order_return_items, and refunds tables support return workflow and reverse_stock behavior
- Filament OrderReturns resource exists
- Key files/components:
- database/migrations/2025_11_14_000002_create_order_returns_table.php
- database/migrations/2025_11_14_000003_create_order_return_items_table.php
- database/migrations/2025_11_14_000004_create_refunds_table.php
- app/Filament/Resources/OrderReturns/OrderReturnResource.php
- app/Services/Orders/OrderReturnService.php

### H. Printing and Hardware Integration

Feature name: Cashier receipt printing
- Description: Generates receipt HTML, converts to image, sends to ESC/POS printer
- Technical flow:
- Print strategy factory selects strategy (currently wkhtmltoimage path)
- Connector auto-selects network IP vs Windows/UNC printer naming
- Can queue through job classes, default direct path
- Key files/components:
- app/Services/PrintService.php
- app/Services/PrintStrategies/*
- app/Jobs/PrintOrderReceipt.php
- resources/views/print/*

Feature name: Kitchen printing by product-printer mapping
- Description: Sends grouped order items to printers linked to products
- Technical flow:
- Resolves products and their many-to-many printers
- Groups items per printer id and prints kitchen ticket
- Key files/components:
- app/Services/PrintService.php
- app/Models/Printer.php
- app/Models/Product.php (printers relation)
- database/migrations/2025_08_04_235012_create_printer_product_table.php

Feature name: Cash drawer open and printer diagnostics
- Description: Opens drawer pulse and provides test/scan endpoints
- Technical flow:
- openCashierDrawer sends printer pulse
- PrinterController exposes test and network scan APIs
- Key files/components:
- app/Services/PrintService.php
- app/Http/Controllers/PrinterController.php
- app/Services/PrinterScanService.php

### I. Reporting and Analytics

Feature name: Filament report dashboards
- Description: Multi-dashboard reporting with filters and widgets
- Technical flow:
- Report pages use HasFiltersForm and service-based queries
- Widgets selected conditionally when period/shift has data
- Key files/components:
- app/Filament/Pages/Reports/*
- app/Filament/Widgets/*
- app/Services/*ReportService.php

Feature domains observed:
- Driver performance, channel performance, products sales, customer performance, peak hours, shift reports, expenses, stock report, web orders report

### J. Admin Panel and Operational Configuration

Feature name: Filament admin resources
- Description: CRUD and operations for categories, products, suppliers, customers, drivers, purchases, return purchases, wastes, stocktaking, inventory, printers, regions, users, settings, table types, orders, order returns
- Technical flow:
- Resource classes and pages under app/Filament/Resources
- Admin panel configured with SPA mode, notifications, profile page, Arabic navigation groups
- Key files/components:
- app/Providers/Filament/AdminPanelProvider.php
- app/Filament/Resources/**/*
- app/Filament/Pages/Settings.php

Feature name: Dynamic settings and node behavior toggles
- Description: Runtime operation settings for printer, website link, cashier permissions, web order shift transfer, node topology metadata
- Technical flow:
- settings table persisted key-value
- SettingKey enum defines defaults/validation/labels
- SettingsService fetch/set API used across services
- Key files/components:
- app/Enums/SettingKey.php
- app/Services/SettingsService.php
- database/migrations/2025_07_07_000020_create_settings_table.php

### K. APIs and Integrations

Feature name: Product master APIs
- Description: Product search, validation, and master data feeds with refs/prices/recipes
- Technical flow:
- ApiController endpoints query products/categories with relations and transformations
- Key files/components:
- routes/api.php
- app/Http/Controllers/Api/ApiController.php

Feature name: Management API (deploy/control/status/custom artisan)
- Description: Remote operational control API guarded by management secret key
- Technical flow:
- Key validation by query or X-Management-Key header
- Uses Artisan::call for deploy/down/up/custom command
- Key files/components:
- routes/api.php (management prefix)
- app/Http/Controllers/Api/ManagementController.php
- app/Console/Commands/DeployCommand.php

## 6. Data Layer

### Database schema overview (main entities)

Core operational entities:
- users
- shifts
- orders
- order_items
- payments

Inventory and costing entities:
- products
- product_components
- inventory_items
- inventory_item_movements
- inventory_item_movement_daily
- wastes and wasted_items
- stocktakings and stocktaking_items

Procurement and return entities:
- suppliers
- purchase_invoices and purchase_invoice_items
- return_purchase_invoices and return_purchase_invoice_items

Customer/delivery entities:
- customers
- drivers
- regions

Returns/refunds entities:
- order_returns
- order_return_items
- refunds

Configuration/support entities:
- settings
- dine_tables
- table_types
- daily_snapshots

### Important relationships
- orders belongs to shift/user/customer/driver
- order has many order_items and payments and order_returns
- product has inventoryItem and has many inventory movements
- product has many components through product_components (recursive recipe graph possible)
- printer belongsToMany products via pivot mapping
- inventory movement references polymorphic origin via referenceable_type and referenceable_id

### Migration system
- Standard Laravel migration workflow with broad migration history
- Includes later schema expansions for web order fields, discount fields, return/refund support, and daily inventory aggregates

### Query patterns
- Eloquent with eager loading for common order/report flows
- Service and repository patterns wrap common operations
- Report services compute aggregates based on date and shift filters

### Caching strategy
- Cache store defaults to database
- SettingsService caches all settings list (settings.all) for one hour
- Individual setting getter currently queries DB directly and only forgets key cache on set

## 7. API Design

### Main endpoints (representative)

Health and management:
- GET and POST /api/check
- POST /api/management/deploy
- POST /api/management/stop
- POST /api/management/start
- POST /api/management/custom-script
- GET /api/management/status

Product APIs:
- GET /api/products/product_search
- POST /api/validate_products
- GET /api/all-products
- GET /api/all-products-refs-master
- GET /api/all-products-prices-master
- GET /api/all-products-recipes-master
- GET /api/get-products-master
- GET /api/get-products-master-by-refs
- GET /api/get-products-prices-master

Web order APIs:
- POST /api/web-orders/place-order
- GET /api/can-accept-order
- GET /api/get-shift-id

Operational web endpoints (auth-protected):
- Shift start/end
- Order create/manage/save/complete/cancel
- Web order accept/reject/complete/out-for-delivery
- Printer test/scan and print actions
- Expenses and inventory day toggle

### Authentication mechanism
- Web/admin: session guard
- API:
- Management API uses custom secret key validation
- Product and web-order ingestion APIs are effectively open under default api middleware (no token policy in inspected code)
- Sanctum package is installed but explicit sanctum middleware protection is not shown on API routes inspected

### Request/response patterns
- JSON responses for API controllers
- Validation via FormRequest and Validator::make rules
- Inertia responses for cashier UI endpoints

### Error handling strategy
- Try/catch in controllers with JSON or redirect error responses
- Domain exceptions thrown from services for invalid state transitions
- Logging via Log facade and ShiftLoggingService

## 8. Concurrency and State Management (Important)

### Concurrent request handling
- Widespread use of DB transactions in critical flows
- Notable explicit lock:
- Order completion uses lockForUpdate on target order row

### Transaction and consistency model
- Transactions used for:
- create order
- update order items
- complete/cancel order
- shift start/end
- purchase and return purchase closures
- waste and stocktaking closure
- stock movement processing
- Consistency boundaries are service-level and mostly robust for single-resource operations

### Locking and isolation strategies
- Positive:
- lockForUpdate on completeOrder order row
- Gaps:
- No row-level locking around inventory item updates in StockService
- Stock update computes current quantity then updates, vulnerable to lost updates under high concurrency

### State management
- Backend state:
- Status enums for orders, payments, returns, movement operations and reasons
- Frontend state:
- React local state plus useReducer for order items
- Server synchronization through Inertia router.post flows
- Realtime state:
- Broadcast events for order/payment/web-order changes over private channels

## 9. Security Analysis

### Authentication
- Session auth for web/admin
- Password hashing via Laravel cast
- Filament panel access method currently returns true for all authenticated users

### Authorization
- Role enum and user helper methods define permissions
- Shift middleware enforces active-shift requirement for selected routes
- Permission flags exposed to frontend for conditional controls

### Input validation and sanitization
- Form requests for order operations
- Validator-based nested validation for web-order ingestion and management APIs
- String/numeric constraints are present for many payloads

### Common vulnerability protections
- CSRF: VerifyCsrfToken middleware present in Filament panel middleware stack; web group uses Laravel defaults
- SQL injection: Eloquent/query builder usage dominates, reducing raw SQL risk
- XSS: React/Blade default escaping generally applies unless explicitly bypassed (no bypass observed in reviewed files)

### Secrets management
- Management secret key sourced from environment/config
- Reverb/Pusher/AWS and other third-party secrets in env-backed config

### Security concerns observed
- Management API allows arbitrary artisan command execution when prefixed by php artisan and key is known
- No explicit rate limiting observed on management or web-order API endpoints
- Several APIs are open by route design (by intent or oversight)

## 10. Performance and Scalability

### Current optimizations
- Eager loading in key order/report paths
- Caching of full settings collection
- Aggregated daily inventory movement table
- Optional queue jobs for printing
- Pulse for telemetry and slow query/request/job observation

### Potential bottlenecks
- Inventory write contention without row locks
- Direct (non-queued) printing may block request lifecycle
- Report pages can become expensive on large datasets if not indexed/tuned
- SettingsService individual get path bypasses cache for common single-key reads

### Scaling strategy indicators
- Horizontal readiness hints:
- Reverb scaling config includes Redis pub/sub mode
- Queue backends support database/redis/sqs
- Practical constraints:
- Monolithic app and tightly coupled hardware printing workflows may require decomposition for very high scale

## 11. DevOps and Deployment

### Build process
- Frontend build via npm run build (Vite + TypeScript)
- Backend dependencies via composer install
- Migration and optimization commands executed in deploy.sh

### Environment configuration
- Extensive env-driven config for DB, queue, cache, reverb, pulse, management operations

### Deployment strategy
- API endpoint can trigger app:deploy command
- app:deploy runs shell script with git operations, dependency install, migrations, cache optimization
- Script includes hard reset and pull, PHP/extension installation, permission updates

### CI/CD and quality gates
- Composer scripts include pest, pint, and phpstan through review target
- Cypress configuration present for E2E
- No in-repo CI workflow was explicitly audited here

### Observability
- Application logs and custom action logs
- Laravel Pulse configured with multiple recorders
- Debugbar present for development profile/debugging

## 12. Code Quality Assessment

### Structure and consistency
- Positive:
- Clear service separation for complex business workflows
- Enums and DTOs reduce primitive-string logic drift
- Repository contracts reduce controller/model coupling in order domain

### Design pattern usage
- Strong use of service and strategy patterns
- Reporting separated into dedicated services and dashboard widgets

### Test coverage signals
- Pest feature tests present for settings, profile, report access/behavior
- Arch tests enforce structural conventions
- Cypress E2E tests cover order workflow, responsive behavior, modal actions, and Arabic/RTL assertions
- Coverage is meaningful but not exhaustive in this audit (no measured percentage generated)

### Maintainability and extensibility
- Generally maintainable module boundaries
- Some duplication and mixed language strings in controllers/UI
- Operational risk from deployment and management command design decisions

## 13. Risks and Weaknesses

### High severity risks
- ShiftService uses undefined variable lossesAmount during shift end update
- Impact: runtime failure or incorrect persisted value on shift closure path
- Evidence: app/Services/ShiftService.php

- Filament CategoryResource static property type mismatch breaks artisan route:list bootstrapping
- Impact: tooling/operations depending on full app boot may fail
- Evidence: app/Filament/Resources/Categories/CategoryResource.php and observed artisan failure

- Management API custom-script endpoint can execute any artisan command string with key
- Impact: high privilege remote operation if key leaked or intercepted
- Evidence: app/Http/Controllers/Api/ManagementController.php

### Medium severity risks
- Inventory updates can race under concurrency because updates are not lockForUpdate-based
- Impact: potential stock inaccuracies under concurrent order completion and stock operations
- Evidence: app/Services/StockService.php

- Stock sufficiency guard is effectively disabled by constant in StockService
- Impact: inventory may go negative by design, may violate accounting expectations
- Evidence: app/Services/StockService.php

- Deploy script includes git reset --hard and hardcoded server path assumptions
- Impact: destructive deployment behavior and brittle environment coupling
- Evidence: deploy.sh

- Rate limiting not explicitly applied to sensitive APIs
- Impact: abuse risk, brute-force or accidental flooding
- Evidence: routes/api.php and bootstrap/app.php (no throttle customization observed)

### Lower severity and technical debt indicators
- SettingsService single-key reads query DB directly while also maintaining cache key invalidation paths
- Some controller classes contain broad responsibilities and long methods
- Filament and React role checks are strong but service-layer permission enforcement is inconsistent for all business actions

## 14. Improvement Recommendations

### Refactoring and architecture improvements
1. Fix correctness defects first:
- Define and persist losses/deficit variables correctly in ShiftService::endShift
- Correct CategoryResource static property type declaration to match Filament base class requirements

2. Strengthen domain integrity:
- Add explicit service-layer authorization checks for sensitive actions (discount, cancellation, item changes), not only controller/UI guards
- Consolidate order state transition logic in a dedicated state policy/service

3. Reduce controller orchestration complexity:
- Move more request-shaping and side-effect logic to dedicated action classes

### Performance and scalability enhancements
1. Inventory concurrency hardening:
- Use lockForUpdate on affected inventory rows before computing new quantities
- Consider optimistic versioning or serialized queue for stock-critical events

2. Queue external/IO-heavy paths:
- Enable queued print mode in production and configure reliable retry/backoff
- Offload external web status callbacks to jobs with idempotency keys

3. Cache improvements:
- Add per-key cache read-through in SettingsService::get and ensure consistent invalidation strategy

### Security enhancements
1. Management API hardening:
- Require signed requests or mTLS/IP allowlist in addition to secret key
- Restrict custom-script to strict allowlist of artisan commands
- Add route throttling and audit event persistence for management actions

2. API access controls:
- Apply explicit authentication middleware where required for product/web-order endpoints
- Add request rate limiting on public APIs

3. Secrets and operations:
- Rotate management key regularly and enforce secure transport

### DevOps and reliability
1. Deployment safety:
- Remove git reset --hard from deployment script
- Make deployment idempotent and environment-parameterized
- Add health checks and rollback strategy around deployment steps

2. Quality gates:
- Ensure route:list passes in CI to catch bootstrap-level regressions
- Add tests for shift end cash/deficit calculations and inventory concurrency scenarios

## Feature Matrix (Summary)

| Domain | Implemented | Notes |
|---|---|---|
| Authentication | Yes | Session auth + role checks |
| Authorization | Yes (partial hardening) | UI/controller strong, service-layer consistency can improve |
| Shift management | Yes | Start/end, active shift gating, transfer of web orders option |
| Order lifecycle | Yes | Create/manage/complete/cancel with payment states |
| Web orders | Yes | Ingestion API + operational management + external status callback |
| Inventory and stock | Yes | Movement ledger + daily aggregates + day open/close control |
| Purchases and returns | Yes | Purchase and return purchase closure integrated with stock |
| Refunds/order returns | Yes | Return tables and resources present |
| Printing and kitchen routing | Yes | Strategy-based image generation + ESC/POS output |
| Reporting analytics | Yes | Multiple Filament report dashboards and widgets |
| DevOps management API | Yes | Deploy/start/stop/custom command/status |
| Realtime | Yes | Broadcast events + private channels |

## System Diagram (ASCII)

Operational Users and Admin
        |
        v
React Inertia UI          Filament Admin UI
        |                         |
        +-----------HTTP----------+
                        |
                        v
                Laravel Monolith
Controllers -> Services -> Repositories -> Eloquent Models -> MySQL/SQLite
                        |
            +-----------+-----------+
            |                       |
        Broadcasting            Printing Pipeline
      (Reverb channels)   (Blade HTML -> Image Strategy -> ESC/POS)
            |                       |
      Realtime clients       Network/Windows printers
                        |
                        v
                 External Website API
            (web-order status callback)

## Assumptions and Unknowns

- Route inventory was extracted from route files because artisan route:list currently fails due Filament resource type mismatch.
- No explicit CI workflow files were inspected in this run, so CI/CD assertions are limited to scripts and config evidence.
- Runtime production topology (single node vs multi-node) is inferred from settings and config, not from live deployment manifests.
