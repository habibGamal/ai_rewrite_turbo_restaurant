import db from '@adonisjs/lucid/services/db'
import { OrderStatus } from '#enums/OrderEnums'
import { ProductType } from '#enums/ProductEnums'
import Customer from '#models/Customer'
import DailySnapshot from '#models/DailySnapshot'
import Driver from '#models/Driver'
import Expense from '#models/Expense'
import Order from '#models/Order'
import Product from '#models/Product'
import PurchaseInvoice from '#models/PurchaseInvoice'
import ReturnPurchaseInvoice from '#models/ReturnPurchaseInvoice'
import Shift from '#models/Shift'
import Stocktaking from '#models/Stocktaking'
import Waste from '#models/Waste'
import ErrorMsgException from '#exceptions/error_msg_exception'
import logger from '@adonisjs/core/services/logger'
import OrderItem from '#models/OrderItem'

export default class ReportsService {
  public static async clientsReport(fromDt: string, toDt: string) {
    const customers = await Customer.query()
      .withAggregate('orders', (query) => {
        query.sum('total').as('ordersTotal').whereBetween('orders.created_at', [fromDt, toDt])
      })
      .withAggregate('orders', (query) => {
        query.sum('profit').as('ordersProfit').whereBetween('orders.created_at', [fromDt, toDt])
      })

    const filteredCustomers = customers.filter(
      (customer) => customer.ordersTotal !== null && customer.ordersProfit !== null
    )
    return filteredCustomers
  }

  public static async productsReport(fromDt: string, toDt: string) {
    const ordersInPeriod = await Order.query()
      .select('id')
      .whereBetween('created_at', [fromDt, toDt])
    const orderIds = ordersInPeriod.map((order) => order.id)
    const products = await Product.query()
      .whereNot('type', ProductType.RawMaterial)
      .withAggregate('orderItems', (query) => {
        query.sum('total').as('salesTotal').whereIn('order_id', orderIds)
        // where the order of this orderItems is between the period
      })
      .withAggregate('orderItems', (query) => {
        query.sum('quantity').as('salesQuantity').whereIn('order_id', orderIds)
      })
      .withAggregate('orderItems', (query) => {
        // sum of (total - cost * quantity)
        query
          .sum(
            db.knexRawQuery('?? - ?? * ??', [
              'order_items.total',
              'order_items.cost',
              'order_items.quantity',
            ])
          )
          .as('salesProfit')
          .whereIn('order_id', orderIds)
      })

    const filteredProducts = products.filter(
      (product) =>
        product.salesTotal !== null &&
        product.salesProfit !== null &&
        product.salesQuantity !== null
    )
    return filteredProducts
  }

  public static async detailedReport(fromDt: string, toDt: string) {
    const stocktakings = await Stocktaking.query().whereBetween('created_at', [fromDt, toDt])
    const purchaseInvoices = await PurchaseInvoice.query().whereBetween('created_at', [
      fromDt,
      toDt,
    ])
    const returnPurchaseInvoices = await ReturnPurchaseInvoice.query().whereBetween('created_at', [
      fromDt,
      toDt,
    ])
    const orders = await Order.query()
      .where('status', OrderStatus.Completed)
      .whereBetween('created_at', [fromDt, toDt])
    const expenses = await Expense.query().whereBetween('created_at', [fromDt, toDt])

    return {
      stocktakings,
      purchaseInvoices,
      returnPurchaseInvoices,
      orders,
      expenses,
    }
  }

  public static async shiftsReport(fromDt: string, toDt: string) {
    const shifts = await Shift.query()
      .whereBetween('start_at', [fromDt, toDt])
      .whereNotNull('end_at')
      .preload('user')
      .orderBy('start_at', 'desc')
    return shifts
  }

  public static async shiftReport(shiftId: number) {
    const shift = await Shift.query()
      .where('id', shiftId)
      .preload('user')
      .preload('expenses')
      .preload('orders', (query) => {
        query.preload('payments')
      })
      .preload('payments')
      .firstOrFail()
    return shift
  }

  public static async fullShiftsReport(fromDt: string, toDt: string) {
    const shifts = await Shift.query()
      .whereBetween('start_at', [fromDt, toDt])
      .preload('user')
      .preload('expenses', (query) => {
        query.preload('expenseType')
      })
      .preload('orders', (query) => {
        query.preload('payments')
      })
      .preload('payments', (query) => {
        query.preload('order')
      })
    return shifts
  }

  public static async currentShiftReport() {
    const shift = await Shift.query()
      .orderBy('id', 'desc')
      .preload('user')
      .preload('expenses', (query) => {
        query.preload('expenseType')
      })
      .preload('orders', (query) => {
        query.preload('payments')
      })
      .preload('payments', (query) => {
        query.preload('order')
      })
      .first()
    return shift
  }

  public static async expensesReport(fromDt: string, toDt: string) {
    const expenses = await Expense.query()
      .preload('expenseType')
      .whereBetween('created_at', [fromDt, toDt])
    return expenses
  }

  public static async stockReport(fromDt: string, toDt: string) {
    let notes = ''
    let startSnapshot = await DailySnapshot.query().where('day', fromDt).first()
    if (!startSnapshot) {
      // choose the first snapshot after the fromDt
      startSnapshot = await DailySnapshot.query()
        .orderBy('day', 'asc')
        .where('day', '>', fromDt)
        .first()
    }
    let endSnapshot = await DailySnapshot.query().where('day', toDt).first()
    if (!endSnapshot) {
      // choose the first snapshot before the toDt
      endSnapshot = await DailySnapshot.query()
        .orderBy('day', 'desc')
        .where('day', '<', toDt)
        .first()
    }
    if (!startSnapshot || !endSnapshot) {
      // if there is no snapshot in the period choose the last snapshot
      startSnapshot = endSnapshot = await DailySnapshot.query().orderBy('day', 'desc').first()
      notes = `لا يوجد سجل مخزون في الفترة من ${fromDt} الى ${toDt} تم اختيار اخر سجل مخزون`
    }
    if (!startSnapshot || !endSnapshot) throw new ErrorMsgException(`لا يوجد سجل مخزون حتى الان`)
    const [startDate, endDate] = [
      startSnapshot.createdAt.toFormat('yyyy-MM-dd HH:mm:ss'),
      endSnapshot.updatedAt.toFormat('yyyy-MM-dd HH:mm:ss'),
    ]

    const purchaseInvoices = await PurchaseInvoice.query()
      .preload('items')
      .whereBetween('created_at', [startDate, endDate])

    const returnPurchaseInvoices = await ReturnPurchaseInvoice.query()
      .preload('items')
      .whereBetween('created_at', [startDate, endDate])
    const orders = await Order.query()
      .where('status', OrderStatus.Completed)
      .whereBetween('created_at', [startDate, endDate])

    // merge order items quantities with the same product id
    const orderItems = await OrderItem.query()
      .select('product_id')
      .sum('quantity as total_quantity')
      .groupBy('product_id')
      .whereIn(
        'order_id',
        orders.map((order) => order.id)
      )

    const manifactured = await Product.query().where('type', ProductType.Manifactured)
    const recipes: {
      product_id: number
      product_name: string
      recipe: { product_id: number; quantity: number }[]
    }[] = []

    for (const product of manifactured) {
      const recipe = await Product.fullRecipe(product)
      recipes.push({ product_id: product.id, product_name: product.name, recipe })
    }

    const wastes = await Waste.query()
      .preload('items')
      .whereBetween('created_at', [startDate, endDate])

    const products = await Product.query()
      .select('id', 'name', 'cost')
      .whereNot('type', ProductType.Manifactured)
    return {
      notes,
      startDate: startSnapshot.createdAt.toFormat('yyyy-MM-dd'),
      endDate: endSnapshot.updatedAt.toFormat('yyyy-MM-dd'),
      startSnapshot,
      endSnapshot,
      purchaseInvoices,
      returnPurchaseInvoices,
      orders,
      wastes,
      recipes,
      products,
      orderItems: orderItems.map((item) => ({
        productId: item.productId,
        quantity: Number(item.$extras.total_quantity),
      })),
    }
  }

  public static async driversReport(fromDt: string, toDt: string) {
    const shifts = await Shift.query().select('id').whereBetween('start_at', [fromDt, toDt])
    const shiftsIds = shifts.map((shift) => shift.id)
    const drivers = await Driver.query().preload('orders', (query) => {
      query.whereIn('shift_id', shiftsIds).preload('driver')
      query.preload('payments')
    })
    return drivers
  }
}
