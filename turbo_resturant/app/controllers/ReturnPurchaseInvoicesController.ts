import { PaymentStatus } from '#enums/InvoicePaymentEnums'
import { ProductType } from '#enums/ProductEnums'
import ErrorMsgException from '#exceptions/error_msg_exception'
import vineExists from '#helpers/vineExists'
import InventoryItem from '#models/InventoryItem'
import Product from '#models/Product'
import ReturnPurchaseInvoice from '#models/ReturnPurchaseInvoice'
import Supplier from '#models/Supplier'
import ReturnPurchaseInvoiceRender from '#render/ReturnPurchaseInvoiceRender'
import DailySnapshotService from '#services/DailySnapshotService'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'
export default class ReturnPurchaseInvoicesController {
  public async index({ inertia }: HttpContext) {
    return inertia.render('RenderModel', await new ReturnPurchaseInvoiceRender().render())
  }

  public async show({ inertia, params }: HttpContext) {
    const invoice = await ReturnPurchaseInvoice.query()
      .where('id', params.id)
      .preload('supplier')
      .preload('items', (query) => {
        query.preload('product')
      })
      .firstOrFail()
    return inertia.render('Invoices/ReturnPurchase/Show', {
      invoice,
    })
  }

  public async payOldReturnInvoice({ request, response, message }: HttpContext) {
    const { invoiceId } = await request.validateUsing(
      vine.compile(
        vine.object({
          invoiceId: vine.number(),
        })
      )
    )
    const invoice = await ReturnPurchaseInvoice.query().where('id', invoiceId).firstOrFail()
    invoice.received = invoice.total
    invoice.status = PaymentStatus.FullPaid
    await invoice.save()
    message.success('تمت عملية الدفع بنجاح')
    return response.redirect().back()
  }

  public async create({ inertia }: HttpContext) {
    const lastInvoice = await ReturnPurchaseInvoice.query().orderBy('id', 'desc').first()
    const invoiceNumber = lastInvoice ? lastInvoice.id + 1 : 1
    const products = await Product.query()
      .select(['id', 'name', 'cost'])
      .whereNot('type', ProductType.Manifactured)
    const suppliers = await Supplier.query().select(['id', 'name'])
    return inertia.render('Invoices/ReturnPurchase/Create', {
      invoiceNumber,
      products,
      suppliers,
    })
  }

  public async edit({ response, inertia, params, message }: HttpContext) {
    const invoice = await ReturnPurchaseInvoice.query()
      .where('id', params.id)
      .preload('items', (query) => {
        query.preload('product')
      })
      .preload('supplier')
      .firstOrFail()
    if (invoice.closed) {
      message.error('لا يمكن تعديل فاتورة مغلقة')
      return response.redirect().back()
    }
    const products = await Product.query()
      .select(['id', 'name', 'cost'])
      .whereNot('type', ProductType.Manifactured)
    const suppliers = await Supplier.query().select(['id', 'name'])
    return inertia.render('Invoices/ReturnPurchase/Edit', {
      invoice,
      products,
      suppliers,
    })
  }

  public async store({ request, response, auth, message }: HttpContext) {
    await DailySnapshotService.exitIfDayClosed()
    // validation
    const { supplierId, received, items } = await request.validateUsing(
      vine.compile(
        vine.object({
          supplierId: vine.number(),
          received: vine.number(),
          items: vine.array(
            vine.object({
              productId: vine.number(),
              quantity: vine.number(),
              price: vine.number(),
            })
          ),
        })
      )
    )
    // calculate total
    const total = items.reduce((acc, item) => acc + item.quantity * item.price, 0)
    // create invoice
    const invoice = await auth.user!.related('returnPurchaseInvoices').create({
      supplierId,
      total,
      received,
      status: received < total ? PaymentStatus.PartialPaid : PaymentStatus.FullPaid,
    })
    // create invoice items
    const returnInvoiceItems = items.map((item) => {
      return {
        productId: item.productId,
        quantity: item.quantity,
        price: item.price,
        total: item.quantity * item.price,
      }
    })
    await invoice!.related('items').createMany(returnInvoiceItems)

    message.success('تمت عملية الارتجاع بنجاح')
    return response.redirect().toRoute('return_purchase_invoices.edit', { id: invoice.id })
  }

  public async update({ request, response, message, params }: HttpContext) {
    const { supplierId, close, received, items } = await request.validateUsing(
      vine.compile(
        vine.object({
          close: vine.boolean(),
          received: vine.number(),
          supplierId: vine.number().exists(vineExists('suppliers')),
          items: vine.array(
            vine.object({
              productId: vine.number(),
              quantity: vine.number(),
              price: vine.number(),
            })
          ),
        })
      )
    )
    const invoice = await ReturnPurchaseInvoice.query().where('id', params.id).firstOrFail()
    if (invoice.closed) {
      message.error('لا يمكن تعديل فاتورة مغلقة')
      return response.redirect().back()
    }
    invoice.supplierId = supplierId
    const invoiceItems = items.map((item) => ({
      productId: item.productId,
      quantity: item.quantity,
      price: item.price,
      total: item.quantity * item.price,
    }))
    const total = invoiceItems.reduce((acc, item) => acc + item.total, 0)
    invoice.total = total
    if (received !== undefined) {
      invoice.received = received
      invoice.status = received < total ? PaymentStatus.PartialPaid : PaymentStatus.FullPaid
    }
    await invoice.save()
    await invoice.related('items').query().delete()
    await invoice.related('items').createMany(invoiceItems)
    if (close) {
      return response.redirect().toRoute('return_purchase_invoices.close', { id: invoice.id })
    }
    message.success('تم تعديل الفاتورة بنجاح')
    return response.redirect().back()
  }

  public async closeInvoice({ response, message, params }: HttpContext) {
    const invoice = await ReturnPurchaseInvoice.query().where('id', params.id).firstOrFail()
    if (invoice.closed) {
      message.error('الفاتورة مغلقة')
      return response.redirect().back()
    }
    invoice.closed = true
    await invoice.save()
    await invoice.load('items')
    const items = invoice.items
    const inventoryItems = await InventoryItem.query().whereIn(
      'product_id',
      items.map((item) => item.productId)
    )
    inventoryItems.forEach(async (invItem) => {
      const invoiceItem = items.find((item) => item.productId === invItem.productId)
      invItem.quantity -= invoiceItem!.quantity
      await invItem.save()
    })
    message.success('تم اغلاق الفاتورة بنجاح')
    return response.redirect().toRoute('return_purchase_invoices.show', { id: invoice.id })
  }

  public async destroy({ response, message, params }: HttpContext) {
    const invoice = await ReturnPurchaseInvoice.query().where('id', params.id).firstOrFail()
    if (invoice.closed) throw new ErrorMsgException('لا يمكن حذف فاتورة مغلقة')
    await invoice.delete()
    message.success('تم حذف الفاتورة بنجاح')
    return response.redirect().back()
  }
}
