import db from '@adonisjs/lucid/services/db'
import { OrderStatus, OrderType } from '#enums/OrderEnums'
import { PaymentMethod, PaymentStatus } from '#enums/PaymentEnums'
import { ProductType } from '#enums/ProductEnums'
import Category from '#models/Category'
import DineTable from '#models/DineTable'
import Driver from '#models/Driver'
import ExpenseType from '#models/ExpenceType'
import Expense from '#models/Expense'
import Order from '#models/Order'
import OrderItem from '#models/OrderItem'
import Product from '#models/Product'
import Region from '#models/Region'
import ErrorMsgException from '#exceptions/error_msg_exception'
import Setting from '#models/Setting'
import logger from '@adonisjs/core/services/logger'
import { DateTime } from 'luxon'
import { destination } from '@adonisjs/core/logger'
import watcher, { orderItemsCompare } from '#helpers/watcher'

export default class OrderManagerService {
  public static async getCurrentShiftOrders(shiftId: number) {
    // get orders in this shift
    const orders = await Order.query()
      .where('shiftId', shiftId)
      .preload('customer')
      .preload('driver')
      .preload('dineTable')

    // get old orders that its payments is not full paid
    const previousPartialPaidOrders = await Order.query()
      .where('type', OrderType.Companies)
      .where('payment_status', PaymentStatus.PartialPaid)
      .preload('payments')
      .orderBy('created_at', 'desc')

    // get expenses in this shift
    const expenses = await Expense.query().preload('expenseType').where('shiftId', shiftId)
    const expenseTypes = await ExpenseType.all()

    return { orders, previousPartialPaidOrders, expenses, expenseTypes }
  }

  public static async showOrderDetailsForAdmin(orderId: number) {
    const order = await Order.findOrFail(orderId)
    await order.load('items', (query) => {
      query.preload('product')
    })
    await order.load('customer')
    await order.load('driver')
    return { order }
  }

  public static async createOrder(
    type: OrderType,
    shiftId: number,
    userId: number | undefined,
    tableNumber: string | undefined
  ) {
    // check if table is reserved if order type is dine in
    if (type === OrderType.DineIn && !(await OrderManagerUtils.isTableAvailable(tableNumber!)))
      throw new ErrorMsgException('هذه الطاولة محجوزة')
    // create order within shift
    const orderNumber = (await OrderManagerUtils.getShiftOrdersCount(shiftId)) + 1
    // create order within shift
    const order = await Order.create({ type, shiftId, orderNumber, userId })
    // reserve table if order type is dine in
    if (type === OrderType.DineIn) {
      await new OrderInternalServices(order).reserveTable(tableNumber!)
    }
    return { id: order.id }
  }

  public static async changeOrderType(
    orderId: number,
    type: OrderType,
    tableNumber: string | undefined
  ) {
    const order = await Order.findOrFail(orderId)
    const orderServices = new OrderInternalServices(order)
    const originalTypeIsDineIn = order.type === OrderType.DineIn
    // check if target table is reserved
    if (tableNumber && type === OrderType.DineIn) {
      const isTableAvailable = await OrderManagerUtils.isTableAvailable(tableNumber!)
      if (!isTableAvailable) throw new ErrorMsgException('هذه الطاولة محجوزة')
    }

    if (originalTypeIsDineIn) await orderServices.freeTable()

    if (type !== OrderType.DineIn) {
      order.type = type
      await order.save()
      return
    }

    await orderServices.reserveTable(tableNumber!)
    order.type = type
    await order.save()
  }

  public static async loadOrderToPrint(orderId: number) {
    const order = await Order.findOrFail(orderId)
    await order.load('items', (query) => {
      query.preload('product')
    })
    await order.load('customer')
    await order.load('driver')
    await order.load('user')
    await order.load('dineTable')
    const receiptFooter = await Setting.query().select('value').where('key', 'receiptFooter')
    return { order, receiptFooter }
  }

  public static async showOrderToCashier(orderId: number) {
    const order = await Order.findOrFail(orderId)
    await order.load('items')
    await order.load('customer')
    await order.load('driver')
    await order.load('user')
    await order.load('dineTable')
    const receiptFooter = await Setting.query().select('value').where('key', 'receiptFooter')
    const categories = await Category.query().preload('products', (query) => {
      query
        .where('legacy', false)
        .whereIn('type', [ProductType.Consumable, ProductType.Manifactured])
    })
    const drivers = await Driver.all()
    const regions = await Region.all()

    return { order, categories, drivers, regions, receiptFooter }
  }

  public static async saveOrderState(
    orderId: number,
    items: {
      productId: number
      quantity: number
      notes: string | undefined
    }[]
  ) {
    const order = await Order.findOrFail(orderId)
    if (order.status !== OrderStatus.Processing) {
      throw new ErrorMsgException('لا يمكنك تعديل الطلب')
    }
    const originalItems = await order.related('items').query()
    // delete old order items
    await order.related('items').query().delete()
    // get products
    const productIds = items.map((item) => item.productId)
    const products = await Product.query().select(['id', 'cost', 'price']).whereIn('id', productIds)
    // add order items
    const orderItems = products.map((product) => {
      const item = items.find((item) => item.productId === product.id)
      return {
        productId: product.id,
        quantity: item!.quantity,
        price: product.price,
        cost: product.cost,
        total: product.price * item!.quantity,
        notes: item ? item.notes : null,
      }
    })
    for (const item of orderItems) {
      await db.table('order_items').insert({
        order_id: order.id,
        product_id: item.productId,
        quantity: item.quantity,
        price: item.price,
        cost: item.cost,
        total: item.total,
        notes: item.notes,
      })
    }
    await order.load('items')
    await new OrderInternalServices(order).updateOrderTotal(order.items, 0)
    const differences = await orderItemsCompare(originalItems, orderItems)
    console.log('differences', differences)
    watcher(order.shiftId).info({
      time: DateTime.now().toString(),
      actions: differences.map((item) => {
        if (item.diff > 0) return `اضافة ${Math.abs(item.diff)} ${item.productName}`
        else if (item.diff < 0) return `حذف ${Math.abs(item.diff)} ${item.productName}`
      }),
    })
  }

  public static async applyDiscount(orderId: number, discount: number, discountType: string) {
    const order = await Order.findOrFail(orderId)
    await new OrderInternalServices(order).applyDiscount(discount, discountType)
  }

  public static async completeOrder(
    orderId: number,
    shiftId: number,
    payments: {
      [PaymentMethod.Card]: number
      [PaymentMethod.Cash]: number
      [PaymentMethod.TalabatCard]: number
    }
  ) {
    const order = await Order.findOrFail(orderId)
    const orderServices = new OrderInternalServices(order)
    if (order.status !== OrderStatus.Processing)
      throw new ErrorMsgException('هذا الطلب لم يعد قيد التشغيل')
    await order.load('items')
    const paid =
      payments[PaymentMethod.Card] +
      payments[PaymentMethod.Cash] +
      payments[PaymentMethod.TalabatCard]
    await orderServices.updateOrderTotal(order.items, paid)
    await orderServices.saveOrderPayments(shiftId, payments)
    if (order.type === OrderType.DineIn) await orderServices.freeTable()
    order.status = OrderStatus.Completed
    await order.save()
  }
}

class OrderInternalServices {
  constructor(private order: Order) {}

  public async reserveTable(tableNumber: string) {
    const dineTable = await DineTable.firstOrCreate({ tableNumber })
    dineTable.orderId = this.order.id
    await dineTable.save()
    this.order.dineTableNumber = dineTable!.tableNumber
    await this.order.save()
  }

  public async freeTable() {
    const dineTable = await DineTable.findByOrFail('orderId', this.order.id)
    dineTable.orderId = null
    await dineTable.save()
  }

  public async saveOrderPayments(
    shiftId: number,
    payments: {
      [PaymentMethod.Card]: number
      [PaymentMethod.Cash]: number
      [PaymentMethod.TalabatCard]: number
    }
  ) {
    const order = this.order
    let totalAmount = 0
    if (payments[PaymentMethod.Card] > 0) {
      await order.related('payments').create({
        shiftId: shiftId,
        paid: payments[PaymentMethod.Card],
        method: PaymentMethod.Card,
      })
      totalAmount += payments[PaymentMethod.Card]
    }
    if (payments[PaymentMethod.TalabatCard] > 0) {
      await order.related('payments').create({
        shiftId: shiftId,
        paid: payments[PaymentMethod.TalabatCard],
        method: PaymentMethod.TalabatCard,
      })
      totalAmount += payments[PaymentMethod.TalabatCard]
    }
    if (payments[PaymentMethod.Cash] > 0) {
      const remaining = order.total - totalAmount
      await order.related('payments').create({
        shiftId: shiftId,
        paid: payments[PaymentMethod.Cash] > remaining ? remaining : payments[PaymentMethod.Cash],
        method: PaymentMethod.Cash,
      })
    }
  }

  public async updateOrderTotal(items: OrderItem[], paid: number) {
    const order = this.order
    const cost = items.reduce((total, item) => total + item.cost * item.quantity, 0)
    order.subTotal = items.reduce((total, item) => total + item.total, 0)
    order.service = 0
    await order.load('customer')
    if (order.type === OrderType.DineIn) {
      order.service = Order.serviceCharge * order.subTotal
    } else if (order.type === OrderType.Delivery && order.customer) {
      order.service = order.customer.deliveryCost
    }
    order.tax = Order.taxRate * order.subTotal

    if (order.tempDiscountPercent !== 0) {
      order.discount = (order.tempDiscountPercent / 100) * order.subTotal
    }

    order.total = Math.ceil(order.subTotal + order.service + order.tax - order.discount)
    order.profit = order.total - cost
    order.paymentStatus = paid < order.total ? PaymentStatus.PartialPaid : PaymentStatus.FullPaid
    await order.save()
  }

  public async applyDiscount(discount: number, discountType: string) {
    const order = this.order
    // reset discounts
    order.discount = 0
    order.tempDiscountPercent = 0

    // apply new discount
    if (discountType === 'percent') {
      order.tempDiscountPercent = discount
    } else {
      order.discount = discount
    }
    await order.load('items')
    await this.updateOrderTotal(order.items, 0)
  }
}

class OrderManagerUtils {
  public static async isTableAvailable(tableNumber: string) {
    const dineTable = await DineTable.firstOrCreate({ tableNumber })
    return dineTable.orderId ? false : true
  }

  public static async getShiftOrdersCount(shiftId: number) {
    const shiftOrdersCount = await Order.query()
      .where('shiftId', shiftId)
      .pojo<{ totalCount: number }>()
      .count('id', 'totalCount')
    return shiftOrdersCount[0].totalCount
  }
}
