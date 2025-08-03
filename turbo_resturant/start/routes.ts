/*
|--------------------------------------------------------------------------
| Routes file
|--------------------------------------------------------------------------
|
| The routes file is used for defining the HTTP routes.
|
*/
import router from '@adonisjs/core/services/router'
import { middleware } from './kernel.js'
import StockLevelsRender from '#render/StockLevelsRender'
import transmit from '@adonisjs/transmit/services/main'
import './routes/api.js'

transmit.registerRoutes()

router.on('/inertia').renderInertia('home', { version: 6 })

router.post('/test-printer', '#controllers/PrintersController.testPrinter')
router.post('/print/shift-summary', '#controllers/PrintersController.printShiftSummary')
router.post('/print/:id', '#controllers/PrintersController.printOrder').as('print-receipt')
router
  .post('/print-in-kitchen', '#controllers/PrintersController.printInKitchen')
  .as('print-in-kitchen')
router.get('/open-drawer', '#controllers/PrintersController.openCashDrawer').as('open-drawer')
router.post('/printers-of-products', '#controllers/PrintersController.printersOfProducts')
router.get('/', async ({ response }) => {
  return response.redirect().toRoute('login.screen')
})
router.get('/active-license', (ctx) => {
  return ctx.inertia.render('ActiveLicense')
})
router
  .group(() => {
    router.get('/login', '#controllers/UsersController.loginScreen').as('login.screen')
    router.post('/login', '#controllers/UsersController.login').as('login')
  })
  .middleware(middleware.guest({ guards: ['web'] }))

router
  .post('/search-product', '#controllers/Products/ProductsController.searchProduct')
  .as('search-product')

router
  .get('/logout', '#controllers/UsersController.logout')
  .as('logout')
  .middleware(middleware.auth())
router
  .group(() => {
    router
      .get(
        '/mapping-printer-products/:id',
        '#controllers/PrintersController.mappingPrinterProducts'
      )
      .as('mapping-printer-products')
    router
      .post(
        '/mapping-printer-products',
        '#controllers/PrintersController.saveMappingPrinterProducts'
      )
      .as('save-mapping-printer-products')
    router
      .get('/snapshot/open-day', '#controllers/DailySnapshotsController.openDay')
      .as('snapshot.open-day')
    /**
     * Admin routes
     */
    router
      .group(() => {
        // master products
        router.get('/import-from-master', '#controllers/MasterProductsController.show')
        router.post('/import-products', '#controllers/MasterProductsController.importProducts')
        router.post('/update-prices', '#controllers/MasterProductsController.updatePrices')
        router.post('/update-recipes','#controllers/MasterProductsController.updateRecipes')
        // settings
        router.get('/settings', '#controllers/SettingsController.index').as('settings')
        router.get('/scan-printers', '#controllers/SettingsController.scanForPrinters')
        router
          .post('/import-data-from-excel', '#controllers/SettingsController.importDataFromExcel')
          .as('importDataFromExcel')
        router
          .group(() => {
            router
              .post('/casheir-printer', '#controllers/SettingsController.setCasheirPrinter')
              .as('casheir-printer')
          })
          .prefix('settings')
          .as('settings.')
        router.post('/settings/:key', '#controllers/SettingsController.setSetting')
        router
          .resource('suppliers', '#controllers/SuppliersController')
          .except(['show', 'create', 'edit'])
        router.resource('users', '#controllers/UsersController').except(['show', 'create', 'edit'])
        router
          .resource('regions', '#controllers/RegionsController')
          .except(['show', 'create', 'edit'])
        router
          .resource('expense-types', '#controllers/ExpenseTypesController')
          .except(['show', 'create', 'edit'])
        router
          .resource('customers', '#controllers/CustomersController')
          .except(['show', 'create', 'edit'])
        router
          .resource('categories', '#controllers/CategoriesController')
          .except(['show', 'create', 'edit'])
        router
          .post(
            '/categories/import-from-excel',
            '#controllers/CategoriesController.importFromExcel'
          )
          .as('categories.import-from-excel')
        router
          .resource('printers', '#controllers/PrintersController')
          .except(['show', 'create', 'edit'])
        router
          .resource('raw-products', '#controllers/Products/RawProductsController')
          .except(['show', 'create', 'edit'])
        router
          .resource('consumable-products', '#controllers/Products/ConsumableProductsController')
          .except(['show', 'create', 'edit'])
        router
          .resource('manifactured-products', '#controllers/Products/ManifacturedProductsController')
          .except(['show', 'create', 'edit'])
        router
          .get(
            '/manifacture-product/components/:id',
            '#controllers/Products/ManifacturedProductsController.edit'
          )
          .as('manifacture-product.edit')
        router
          .put(
            '/manifacture-product/components/:id',
            '#controllers/Products/ManifacturedProductsController.updateComponents'
          )
          .as('manifacture-product.updateComponents')

        // show order
        router.get('/orders/:id', '#controllers/OrdersController.show').as('orders.show')

        // stock levels
        router
          .get('/stock-levels', async ({ inertia }) => {
            return inertia.render('RenderModel', await new StockLevelsRender().render())
          })
          .as('stock_levels.index')

        // invoices
        router.resource('purchase-invoices', '#controllers/PurchaseInvoicesController')
        router
          .get(
            '/purchase-invoices/close/:id',
            '#controllers/PurchaseInvoicesController.closeInvoice'
          )
          .as('purchase_invoices.close')

        router
          .post('/pay-old-invoice', '#controllers/PurchaseInvoicesController.payOldInvoice')
          .as('pay-old-invoice')

        router.resource('return-purchase-invoices', '#controllers/ReturnPurchaseInvoicesController')
        router
          .get(
            '/return-purchase-invoices/close/:id',
            '#controllers/ReturnPurchaseInvoicesController.closeInvoice'
          )
          .as('return_purchase_invoices.close')

        router
          .post(
            '/pay-old-return-invoice',
            '#controllers/ReturnPurchaseInvoicesController.payOldReturnInvoice'
          )
          .as('pay-old-return-invoice')

        // stocktaking
        router.resource('stocktaking', '#controllers/StocktakingsController').except(['destroy'])
        router
          .get('/stocktaking/close/:id', '#controllers/StocktakingsController.close')
          .as('stocktaking.close')

        // wastes
        router.resource('wastes', '#controllers/WastesController').except(['destroy'])
        router.get('/wastes/close/:id', '#controllers/WastesController.close').as('wastes.close')

        // Accounting
        router
          .get(
            '/accounting/suppliers-accounting',
            '#controllers/AccountingController.suppliersAccounting'
          )
          .as('accounting.suppliers-accounting')
        router
          .get(
            '/accounting/customers-accounting',
            '#controllers/AccountingController.customersAccounting'
          )
          .as('accounting.customers-accounting')

        // print shift receipts
        router
          .get('/print-shift-receipts/:id', '#controllers/PrintersController.printShiftReceipts')
          .as('print-shift-receipts')

        // daily snapshot
        router
          .get(
            '/snapshot/start-accounting',
            '#controllers/DailySnapshotsController.startAccounting'
          )
          .as('snapshot.start-accounting')
        router
          .get('/snapshot/close-day', '#controllers/DailySnapshotsController.closeDay')
          .as('snapshot.close-day')

        router.get('/load-order-to-print/:id', '#controllers/OrdersController.loadOrderToPrint')
      })
      .middleware(middleware.admin())

    /**
     * Cashier routes
     */
    router
      .get('/continue-shift', '#controllers/ShiftsController.continueShift')
      .as('continue-shift')
    router
      .group(() => {
        router.get('/start-shift', '#controllers/ShiftsController.startShift').as('start-shift')
        router.post('/start-shift', '#controllers/ShiftsController.createShift').as('create-shift')
      })
      .middleware(middleware.noShift())
    router
      .group(() => {
        router
          .group(() => {
            router
              .post(
                '/orders/cancel-completed-order/:id',
                '#controllers/OrdersController.cancelCompletedOrder'
              )
              .as('cancel-completed-order')
            router
              .post('/orders/make-discount/:id', '#controllers/OrdersController.makeDiscount')
              .as('make-discount')
            router.post('/end-shift', '#controllers/ShiftsController.endShift').as('end-shift')
          })
          .middleware(middleware.admin())

        router.get('/orders', '#controllers/OrdersController.index').as('cashier-screen')
        router.post('/make-order', '#controllers/OrdersController.makeOrder').as('make-order')
        router
          .get('/orders/manage-order/:id', '#controllers/OrdersController.manageOrder')
          .as('manage-order')
        router
          .get('/orders/manage-web-order/:id', '#controllers/OrdersController.manageWebOrder')
          .as('manage-web-order')
        router
          .post('/orders/save-order/:id', '#controllers/OrdersController.saveOrder')
          .as('save-order')
        router
          .post('/orders/pay-old-order/:id', '#controllers/OrdersController.payOldOrder')
          .as('pay-old-order')
        router
          .post('/orders/complete-order/:id', '#controllers/OrdersController.completeOrder')
          .as('complete-order')
        router
          .post('/orders/link-customer/:id', '#controllers/OrdersController.linkCustomer')
          .as('link-customer')
        router
          .post('/orders/link-driver/:id', '#controllers/OrdersController.linkDriver')
          .as('link-driver')
        router
          .post('/orders/kitchen-notes/:id', '#controllers/OrdersController.saveKitchenNotes')
          .as('kitchen-notes')
        router
          .post('/orders/order-notes/:id', '#controllers/OrdersController.saveOrderNotes')
          .as('order-notes')
        router
          .post('/orders/change-order-type/:id', '#controllers/OrdersController.changeOrderType')
          .as('change-order-type')
        router.post('/expenses', '#controllers/ExpensesController.store').as('expenses.store')
        router.put('/expenses/:id', '#controllers/ExpensesController.update').as('expenses.update')
        router
          .delete('/expenses/:id', '#controllers/ExpensesController.destroy')
          .as('expenses.destroy')
        router
          .post('/fetch-customer-info', '#controllers/CustomersController.fetchCustomerInfo')
          .as('fetch-customer-info')
        router
          .post('/quick-customer', '#controllers/CustomersController.storeQuick')
          .as('quick-customer')
        router
          .post('/fetch-driver-info', '#controllers/DriversController.fetchDriverInfo')
          .as('fetch-driver-info')
        router.post('/quick-driver', '#controllers/DriversController.storeQuick').as('quick-driver')

        // web orders

        router.post('/web-orders/accept-order/:id', '#controllers/WebOrdersController.acceptOrder')
        router.post('/web-orders/reject-order/:id', '#controllers/WebOrdersController.rejectOrder')
        router.post('/web-orders/cancel-order/:id', '#controllers/WebOrdersController.cancelOrder')
        router.post(
          '/web-orders/complete-order/:id',
          '#controllers/WebOrdersController.completeOrder'
        )
        router.post(
          '/web-orders/out-for-delivery/:id',
          '#controllers/WebOrdersController.outForDelivery'
        )
        router.post(
          '/web-orders/make-discount/:id',
          '#controllers/WebOrdersController.applyDiscount'
        )
        router.post('/web-orders/save-order/:id', '#controllers/WebOrdersController.saveOrder')
      })
      .middleware(middleware.inShift())
  })
  .middleware([middleware.auth(), middleware.notViewer()])

router
  .group(() => {
    router
      .get('/reports/clients-report', '#controllers/ReportsController.clientsReport')
      .as('reports.clients-report')
    router
      .get('/reports/products-report', '#controllers/ReportsController.productsReport')
      .as('reports.products-report')
    router
      .get('/reports/detailed-report', '#controllers/ReportsController.detailedReport')
      .as('reports.detailed-report')
    router
      .get('/reports/shifts-report', '#controllers/ReportsController.shiftsReport')
      .as('reports.shifts-report')

    router
      .get('/reports/shift-report/:id', '#controllers/ReportsController.shiftReport')
      .as('reports.shift-report')
    router
      .get('/reports/current-shift-report', '#controllers/ReportsController.currentShiftReport')
      .as('reports.current-shift-report')
    router
      .get('/reports/full-shifts-report', '#controllers/ReportsController.fullShiftsReport')
      .as('reports.full-shifts-report')
    router
      .get('/reports/stock-report', '#controllers/ReportsController.stockReport')
      .as('reports.stock-report')
    router
      .get('/reports/expenses-report', '#controllers/ReportsController.expensesReport')
      .as('reports.expenses-report')
    router
      .get('/reports/drivers-report', '#controllers/ReportsController.driversReport')
      .as('reports.drivers-report')
  })
  .middleware([middleware.auth(), middleware.adminOrViewer()])

router
  .group(() => {
    router
      .get('/reports/shifts-logs', '#controllers/ReportsController.shiftsLogs')
      .as('reports.shifts-logs')
    router.get('/reports/logs-report/:id', '#controllers/WatchersController.show')
  })
  .middleware([middleware.auth(), middleware.watcher()])
