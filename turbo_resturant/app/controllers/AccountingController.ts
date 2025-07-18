import type { HttpContext } from '@adonisjs/core/http'
import { PaymentStatus } from '#enums/PaymentEnums'
import Customer from '#models/Customer'
import Supplier from '#models/Supplier'

export default class AccountingController {
  public async suppliersAccounting({ inertia }: HttpContext) {
    const suppliers = await Supplier.query()
      .preload('purchaseInvoices', (query) => {
        query.where('status', '=', PaymentStatus.PartialPaid)
      })
      .preload('returnPurchaseInvoices', (query) => {
        query.where('status', '=', PaymentStatus.PartialPaid)
      })

    return inertia.render('Accounting/SuppliersAccounting', {
      suppliers,
    })
  }

  public async customersAccounting({ inertia }: HttpContext) {
    const customers = await Customer.query()
      .preload('orders', (query) => {
        query.where('payment_status', '=', PaymentStatus.PartialPaid).preload('payments')
      })

    return inertia.render('Accounting/CompaniesAccounting', {
      customers,
    })
  }
}
