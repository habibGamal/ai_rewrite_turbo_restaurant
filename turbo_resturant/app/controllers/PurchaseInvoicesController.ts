import DailySnapshotService from '#services/DailySnapshotService'
import { PaymentStatus } from '#enums/InvoicePaymentEnums'
import { ProductType } from '#enums/ProductEnums'
import vineExists from '#helpers/vineExists'
import InventoryItem from '#models/InventoryItem'
import Product from '#models/Product'
import PurchaseInvoice from '#models/PurchaseInvoice'
import Supplier from '#models/Supplier'
import PurchaseInvoiceRender from '#render/PurchaseInvoiceRender'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'
import ErrorMsgException from '#exceptions/error_msg_exception'

export default class PurchaseInvoicesController {
  public async index({ inertia }: HttpContext) {
    return inertia.render('RenderModel', await new PurchaseInvoiceRender().render())
  }

  public async show({ inertia, params }: HttpContext) {
    const invoice = await PurchaseInvoice.query()
      .where('id', params.id)
      .preload('supplier')
      .preload('items', (query) => {
        query.preload('product')
      })
      .firstOrFail()
    return inertia.render('Invoices/Purchase/Show', {
      invoice,
    })
  }

  public async create({ inertia }: HttpContext) {
    const lastInvoice = await PurchaseInvoice.query().orderBy('id', 'desc').first()
    const invoiceNumber = lastInvoice ? lastInvoice.id + 1 : 1
    const products = await Product.query()
      .select(['id', 'name', 'cost'])
      .whereNot('type', ProductType.Manifactured)
    const suppliers = await Supplier.query().select(['id', 'name'])
    return inertia.render('Invoices/Purchase/Create', {
      invoiceNumber,
      products,
      suppliers,
    })
  }

  public async edit({ inertia, params }: HttpContext) {
    const invoice = await PurchaseInvoice.query()
      .where('id', params.id)
      .preload('items', (query) => {
        query.preload('product')
      })
      .preload('supplier')
      .firstOrFail()
    if (invoice.closed) throw new ErrorMsgException('لا يمكن تعديل فاتورة مغلقة')
    const products = await Product.query()
      .select(['id', 'name', 'cost'])
      .whereNot('type', ProductType.Manifactured)
    const suppliers = await Supplier.query().select(['id', 'name'])
    return inertia.render('Invoices/Purchase/Edit', {
      invoice,
      products,
      suppliers,
    })
  }

  public async payOldInvoice({ request, response, message }: HttpContext) {
    const { invoiceId } = await request.validateUsing(
      vine.compile(
        vine.object({
          invoiceId: vine.number(),
        })
      )
    )
    const invoice = await PurchaseInvoice.query().where('id', invoiceId).firstOrFail()
    invoice.paid = invoice.total
    invoice.status = PaymentStatus.FullPaid
    await invoice.save()
    message.success('تمت عملية الدفع بنجاح')
    return response.redirect().back()
  }

  public async store({ request, response, message, auth }: HttpContext) {
    await DailySnapshotService.exitIfDayClosed()
    const { supplierId, paid, items } = await request.validateUsing(
      vine.compile(
        vine.object({
          supplierId: vine.number(),
          paid: vine.number(),
          items: vine.array(
            vine.object({
              productId: vine.number(),
              quantity: vine.number(),
              cost: vine.number(),
            })
          ),
        })
      )
    )
    const invoiceItems = items.map((item) => ({
      productId: item.productId,
      quantity: item.quantity,
      cost: item.cost,
      total: item.quantity * item.cost,
    }))
    const total = invoiceItems.reduce((acc, item) => acc + item.total, 0)
    const invoice = await auth.user!.related('purchaseInvoices').create({
      supplierId,
      total,
      paid,
      status: PaymentStatus.PartialPaid,
    })
    await invoice!.related('items').createMany(invoiceItems)
    message.success('تم انشاء الفاتورة')
    return response.redirect().toRoute('purchase_invoices.edit', { id: invoice.id })
  }

  public async update({ request, response, params, message }: HttpContext) {
    const { items, supplierId, paid, close } = await request.validateUsing(
      vine.compile(
        vine.object({
          close: vine.boolean().optional(),
          paid: vine.number().optional(),
          supplierId: vine.number().exists(vineExists('suppliers')),
          items: vine.array(
            vine.object({
              productId: vine.number(),
              quantity: vine.number(),
              cost: vine.number(),
            })
          ),
        })
      )
    )
    const invoice = await PurchaseInvoice.query().where('id', params.id).firstOrFail()
    if (invoice.closed) throw new ErrorMsgException('لا يمكن تعديل فاتورة مغلقة')
    invoice.supplierId = supplierId
    const invoiceItems = items.map((item) => ({
      productId: item.productId,
      quantity: item.quantity,
      cost: item.cost,
      total: item.quantity * item.cost,
    }))
    const total = invoiceItems.reduce((acc, item) => acc + item.total, 0)
    invoice.total = total
    if (paid !== undefined) {
      invoice.paid = paid
      invoice.status = paid < total ? PaymentStatus.PartialPaid : PaymentStatus.FullPaid
    }
    await invoice.save()
    await invoice.related('items').query().delete()
    await invoice.related('items').createMany(invoiceItems)
    if (close) {
      return response.redirect().toRoute('purchase_invoices.close', { id: invoice.id })
    }
    message.success('تمت عملية التعديل بنجاح')
    return response.redirect().back()
  }

  public async closeInvoice({ response, params, message }: HttpContext) {
    const invoice = await PurchaseInvoice.query().where('id', params.id).firstOrFail()
    if (invoice.closed) throw new ErrorMsgException('الفاتورة مغلقة بالفعل')
    // close invoice
    invoice.closed = true
    await invoice.save()
    await invoice.load('items')
    const items = invoice.items
    // update inventory
    const inventoryItems = await InventoryItem.query().whereIn(
      'product_id',
      items.map((item) => item.productId)
    )
    inventoryItems.forEach(async (invItem) => {
      const invoiceItem = items.find((item) => item.productId === invItem.productId)
      invItem.quantity += invoiceItem!.quantity
      await invItem.save()
    })
    // update product cost
    const products = await Product.query().whereIn(
      'id',
      items.map((item) => item.productId)
    )
    products.forEach(async (product) => {
      const item = items.find((item) => item.productId === product.id)
      product.cost = item!.cost
      if (product.type === ProductType.RawMaterial) {
        product.price = item!.cost
      }
      await product.save()
    })
    message.success('تمت عملية الاغلاق بنجاح')
    return response.redirect().toRoute('purchase_invoices.show', { id: invoice.id })
  }

  public async destroy({ response, message, params }: HttpContext) {
    const invoice = await PurchaseInvoice.query().where('id', params.id).firstOrFail()
    if (invoice.closed) throw new ErrorMsgException('لا يمكن حذف فاتورة مغلقة')
    await invoice.related('items').query().delete()
    await invoice.delete()
    message.success('تمت عملية الحذف بنجاح')
    return response.redirect().back()
  }
}
