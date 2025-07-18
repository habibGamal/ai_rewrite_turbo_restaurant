import { OrderStatus, OrderType } from '#enums/OrderEnums'
import { PaymentMethod, PaymentStatus } from '#enums/PaymentEnums'
import ErrorMsgException from '#exceptions/error_msg_exception'
import vineExists from '#helpers/vineExists'
import DineTable from '#models/DineTable'
import Order from '#models/Order'
import OrderManagerService from '#services/OrdersManageService'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'
import locks from '@adonisjs/lock/services/main'
import watcher from '#helpers/watcher'
import { DateTime } from 'luxon'

export default class OrdersController {
  public async index({ inertia, session }: HttpContext) {
    const data = await OrderManagerService.getCurrentShiftOrders(session.get('shiftId'))
    return inertia.render('Orders/Index', data)
  }

  public async show({ inertia, params }: HttpContext) {
    const data = await OrderManagerService.showOrderDetailsForAdmin(params.id)
    return inertia.render('Orders/Show', data)
  }

  public async loadOrderToPrint({ response, params }: HttpContext) {
    const data = await OrderManagerService.loadOrderToPrint(params.id)
    return response.json(data)
  }

  public async makeOrder({ request, response, session, auth }: HttpContext) {
    const { type, tableNumber } = await request.validateUsing(
      vine.compile(
        vine.object({
          type: vine.enum(Object.values(OrderType)),
          tableNumber: vine.string().optional().requiredWhen('type', '=', OrderType.DineIn),
        })
      )
    )
    const user = auth.user
    const data = await OrderManagerService.createOrder(
      type,
      session.get('shiftId'),
      user?.id,
      tableNumber
    )

    watcher(session.get('shiftId')).info({
      time: DateTime.now().toString(),
      user: auth.user?.email,
      action: `انشاء الطلب رقم ${data.id}`,
    })
    return response.redirect().toRoute('manage-order', data)
  }

  public async changeOrderType({ request, response, params }: HttpContext) {
    const { type, tableNumber } = await request.validateUsing(
      vine.compile(
        vine.object({
          type: vine.enum(Object.values(OrderType)),
          tableNumber: vine.string().optional().requiredWhen('type', '=', OrderType.DineIn),
        })
      )
    )
    await OrderManagerService.changeOrderType(params.id, type, tableNumber)
    return response.redirect().back()
  }

  public async manageOrder({ inertia, params }: HttpContext) {
    const data = await OrderManagerService.showOrderToCashier(params.id)
    return inertia.render('Orders/ManageOrder', data)
  }

  public async linkCustomer({ request, response, message, params }: HttpContext) {
    const { customerId } = await request.validateUsing(
      vine.compile(
        vine.object({
          customerId: vine.number().exists(vineExists('customers')),
        })
      )
    )
    // add customer to order
    const order = await Order.findOrFail(params.id)
    order.customerId = customerId
    await order.save()
    message.success('تم ربط العميل بنجاح')
    return response.redirect().back()
  }

  public async linkDriver({ request, response, message, params }: HttpContext) {
    // validation
    const { driverId } = await request.validateUsing(
      vine.compile(
        vine.object({
          driverId: vine.number().exists(vineExists('drivers')),
        })
      )
    )
    // add driver to order
    const order = await Order.findOrFail(params.id)
    order.driverId = driverId
    await order.save()
    message.success('تم ربط السائق بنجاح')
    return response.redirect().back()
  }

  public async saveKitchenNotes({ request, response, message, params }: HttpContext) {
    const { notes } = await request.validateUsing(
      vine.compile(
        vine.object({
          notes: vine.string().optional(),
        })
      )
    )
    const order = await Order.findOrFail(params.id)
    order.kitchenNotes = notes || ''
    await order.save()
    message.success('تم حفظ الملاحظات')
    return response.redirect().back()
  }

  public async saveOrderNotes({ request, response, message, params }: HttpContext) {
    const { notes } = await request.validateUsing(
      vine.compile(
        vine.object({
          notes: vine.string().optional(),
        })
      )
    )
    const order = await Order.findOrFail(params.id)
    order.orderNotes = notes || ''
    await order.save()
    message.success('تم حفظ الملاحظات')
    return response.redirect().back()
  }

  public async saveOrder({ request, params, response, message, session, auth }: HttpContext) {
    // validation
    const { items } = await request.validateUsing(
      vine.compile(
        vine.object({
          items: vine.array(
            vine.object({
              productId: vine.number(),
              quantity: vine.number(),
              notes: vine.string().optional(),
            })
          ),
        })
      )
    )
    watcher(session.get('shiftId')).info({
      time: DateTime.now().toString(),
      user: auth.user?.email,
      action: `تعديل الطلب رقم ${params.id}`,
    })
    await OrderManagerService.saveOrderState(params.id, items)
    message.success('تم حفظ الطلب')
    return response.redirect().back()
  }

  public async makeDiscount({ request, response, message, params, session, auth }: HttpContext) {
    const { discount, discountType } = await request.validateUsing(
      vine.compile(
        vine.object({
          discount: vine.number(),
          discountType: vine.string(),
        })
      )
    )
    await OrderManagerService.applyDiscount(params.id, discount, discountType)
    message.success('تم تطبيق الخصم')
    watcher(session.get('shiftId')).info({
      time: DateTime.now().toString(),
      user: auth.user?.email,
      action:
        discountType === 'percent'
          ? `خصم ${discount} % على الطلب رقم ${params.id}`
          : `خصم ${discount} جنية على الطلب رقم ${params.id}`,
    })
    return response.redirect().back()
  }

  public async completeOrder({ request, response, session, params, message, auth }: HttpContext) {
    const data = await request.validateUsing(
      vine.compile(
        vine.object({
          [PaymentMethod.Card]: vine.number(),
          [PaymentMethod.Cash]: vine.number(),
          [PaymentMethod.TalabatCard]: vine.number(),
          print: vine.boolean().optional(),
        })
      )
    )
    const [executed] = await locks
      .createLock(`order.processing.${params.id}`)
      .runImmediately(async () => {
        await OrderManagerService.completeOrder(params.id, session.get('shiftId'), data)
        return
      })
    if (!executed) message.error('الطلب قيد التنفيذ')
    if (data.print) {
      return response.redirect().toRoute('print-receipt', { id: params.id })
    }
    watcher(session.get('shiftId')).info({
      time: DateTime.now().toString(),
      user: auth.user?.email,
      action: `انهاء الطلب رقم ${params.id}`,
    })
    return response.redirect().back()
  }

  public async payOldOrder({ request, response, session, params }: HttpContext) {
    const { paid, method } = await request.validateUsing(
      vine.compile(
        vine.object({
          paid: vine.number(),
          method: vine.enum(Object.values(PaymentMethod)),
        })
      )
    )
    // get order
    const order = await Order.findOrFail(params.id)
    // add payment
    await order.related('payments').create({
      shiftId: session.get('shiftId'),
      paid,
      method,
    })
    // load order payments paid
    await order.loadAggregate('payments', (query) => {
      query.sum('paid').as('totalPaid')
    })
    const sumPaid = order.$extras.totalPaid as number
    // update order payment status
    if (sumPaid < order.total) {
      order.paymentStatus = PaymentStatus.PartialPaid
    } else {
      order.paymentStatus = PaymentStatus.FullPaid
    }
    await order.save()
    return response.redirect().back()
  }

  public async cancelOrder({ params, response }: HttpContext) {
    // find order
    const order = await Order.findOrFail(params.id)
    // confirm order is processing
    if (order.status !== OrderStatus.Processing) throw new ErrorMsgException('لا يمكن إلغاء الطلب')
    order.status = OrderStatus.Cancelled
    // calculate total
    await order.load('items')
    const subTotal = order.items.reduce((total, item) => total + item.total, 0)
    order.subTotal = subTotal
    order.total = subTotal + order.service + order.tax - order.discount
    order.profit = 0
    await order.save()
    // free table
    if (order.type === OrderType.DineIn) {
      const dineTable = await DineTable.findByOrFail('orderId', order.id)
      dineTable.orderId = null
      await dineTable.save()
    }
    return response.redirect().back()
  }

  public async cancelCompletedOrder({ params, response, session, auth }: HttpContext) {
    const order = await Order.findOrFail(params.id)
    order.status = OrderStatus.Cancelled
    await order.save()
    // delete all its payments
    await order.related('payments').query().delete()

    watcher(session.get('shiftId')).info({
      time: DateTime.now().toString(),
      user: auth.user?.email,
      action: `الغاء الطلب رقم ${params.id}`,
    })
    return response.redirect().back()
  }
}
