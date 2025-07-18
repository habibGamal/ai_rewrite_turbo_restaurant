import { OrderStatus } from '#enums/OrderEnums'
import { PaymentMethod } from '#enums/PaymentEnums'
import Product from '#models/Product'
import Shift from '#models/Shift'
import DailySnapshotService from '#services/DailySnapshotService'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'
import { DateTime } from 'luxon'
import ErrorMsgException from '#exceptions/error_msg_exception'
import logger from '@adonisjs/core/services/logger'
export default class ShiftsController {
  public async startShift({ inertia }: HttpContext) {
    return inertia.render('Shifts/StartShift')
  }

  // if electric power is off, we can continue shift
  public async continueShift({ response, session }: HttpContext) {
    const shift = await Shift.query().orderBy('id', 'desc').first()
    const isShiftInProgress = shift?.endAt === null
    if (isShiftInProgress) {
      session.put('shiftId', shift.id)
      return response.redirect().toRoute('cashier-screen')
    } else {
      return response.redirect().toRoute('start-shift')
    }
  }

  public async createShift({ request, auth, response, session }: HttpContext) {
    await DailySnapshotService.exitIfDayClosed()
    const data = await request.validateUsing(
      vine.compile(
        vine.object({
          startCash: vine.number(),
        })
      )
    )
    const user = auth.user!
    const shift = await user.related('shifts').create({ ...data, startAt: DateTime.now() })
    session.put('shiftId', shift.id)
    return response.redirect().toRoute('cashier-screen')
  }

  public async endShift({ response, session, request }: HttpContext) {
    // get realEndCash from request
    const { realEndCash } = await request.validateUsing(
      vine.compile(
        vine.object({
          realEndCash: vine.number(),
        })
      )
    )
    // get current shift
    const shiftId = session.get('shiftId')
    const shift = await Shift.findOrFail(shiftId)
    // verify that there are no orders in progress
    const orders = await shift
      .related('orders')
      .query()
      .preload('items', (query) => {
        query.preload('product')
      })
    const inProgressOrders = orders.filter((order) => order.status === OrderStatus.Processing)
    if (inProgressOrders.length > 0) throw new ErrorMsgException('هناك طلبات تحت التشغيل')
    // calculate all done orders
    const doneOrders = orders.filter((order) => order.status === OrderStatus.Completed)
    const payments = await shift.related('payments').query()
    const paidDoneOrders = payments.reduce(
      (acc, payment) => acc + (payment.method === PaymentMethod.Cash ? payment.paid : 0),
      0
    )
    /**
     * calculate delivery orders cost
     * as it doesn't enter the treasury
     */
    // const deliveryOrders = doneOrders.filter((order) => order.type === OrderType.Delivery)
    // const deliveryOrdersCost = deliveryOrders.reduce((acc, order) => acc + order.service, 0)
    // calculate all expenses
    const expenses = await shift.related('expenses').query()
    const expensesTotal = expenses.reduce((acc, expense) => acc + expense.amount, 0)
    // calculate deficit
    const endCash = shift.startCash + paidDoneOrders - expensesTotal
    //  - deliveryOrdersCost
    const deficit = realEndCash - endCash
    // save shift
    await shift
      .merge({
        realCash: realEndCash,
        endCash: endCash,
        lossesAmount: deficit,
        hasDeficit: deficit < 0,
        endAt: DateTime.now(),
        closed: true,
      })
      .save()
    // forget shiftId from session
    session.forget('shiftId')
    // remove quantity from inventory
    const orderItems = doneOrders.flatMap((order) => order.items)
    const products = await Product.query().whereIn(
      'id',
      orderItems.map((item) => item.productId)
    )
    // group orderItems by product id
    const orderItemsGrouped = orderItems.reduce(
      (acc, item) => {
        if (!acc[item.productId]) {
          acc[item.productId] = 0
        }
        acc[item.productId] += item.quantity
        return acc
      },
      {} as Record<number, number>
    )

    for (const product of products) {
      const quantity = orderItemsGrouped[product.id]
      await Product.updateInventoryLevel(product, quantity)
    }
    return response.redirect().toRoute('start-shift')
  }
}
