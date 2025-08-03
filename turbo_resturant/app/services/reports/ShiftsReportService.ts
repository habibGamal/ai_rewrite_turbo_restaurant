import Expense from '#models/Expense'
import Order from '#models/Order'
import Payment from '#models/Payment'
import Shift from '#models/Shift'
import { PaginatorService } from '#services/PaginatorService'
import db from '@adonisjs/lucid/services/db'
import { HttpContext } from '@adonisjs/core/http'
import { OrderStatus } from '#enums/OrderEnums'
import { exportToCSV } from '#services/ExportCSV'

export class ShiftsReportService {
  private shifts: Shift[]

  constructor(shifts: Shift[]) {
    this.shifts = shifts
  }

  async expenses() {
    const paginator = new PaginatorService()
    return await paginator.paginate(
      Expense.query()
        .select('expenses.*', 'expense_types.name as type')
        .whereIn(
          'shift_id',
          this.shifts.map((shift) => shift.id)
        )
        .leftJoin('expense_types', 'expenses.expense_type_id', 'expense_types.id')
    )
  }

  async statistics() {
    const orders = await Order.query()
      .select('status', 'type')
      .count('*', 'count')
      .sum('total', 'total')
      .sum('profit', 'profit')
      .sum('discount', 'discount')
      .whereIn(
        'shift_id',
        this.shifts.map((shift) => shift.id)
      )
      .groupBy('status', 'type')
    const payments = await Payment.query()
      .select('method')
      .sum('paid', 'total')
      .whereIn(
        'shift_id',
        this.shifts.map((shift) => shift.id)
      )
      .groupBy('method')
    const expenses = await Expense.query()
      .select('expenseTypeId')
      .sum('amount', 'total')
      .whereIn(
        'shift_id',
        this.shifts.map((shift) => shift.id)
      )
      .groupBy('expenseTypeId')
      .preload('expenseType')
    const result = {
      orders: orders.map((item) => ({
        status: item.status,
        type: item.type,
        count: item.$extras.count,
        total: item.total,
        profit: item.profit,
        discount: item.discount,
      })),
      payments: payments.map((item) => ({
        method: item.method,
        total: item.$extras.total,
      })),
      expenses: expenses.map((item) => ({
        type: item.expenseType.name,
        total: item.$extras.total,
      })),
      startCash: 0,
      numberOfShifts: this.shifts.length,
    }
    if (this.shifts.length === 1) result.startCash = this.shifts[0].startCash
    return result
  }

  async statisticsByStatusOrType() {
    const { request } = HttpContext.getOrFail()
    const queries = request.qs()
    const ordersBykey = queries.ordersByKey
    const ordersByValue = queries.ordersByValue
    const query = db
      .query()
      .select(
        db.raw(`COUNT(*) AS count`),
        db.raw(`SUM(paidCash) AS paidCash`),
        db.raw(`SUM(paidCard) AS paidCard`),
        db.raw(`SUM(paidTalabatCard) AS paidTalabatCard`),
        db.raw(`SUM(total) AS total`),
        db.raw(`SUM(profit) AS profit`),
        db.raw(`SUM(discount) AS discount`)
      )
      .from((subquery) => {
        subquery
          .select(
            'orders.id',
            'orders.shift_id',
            'orders.total',
            'orders.profit',
            'orders.discount',
            db.raw(
              `SUM(CASE WHEN payments.method = 'cash' THEN payments.paid ELSE 0 END) AS paidCash`
            ),
            db.raw(
              `SUM(CASE WHEN payments.method = 'card' THEN payments.paid ELSE 0 END) AS paidCard`
            ),
            db.raw(
              `SUM(CASE WHEN payments.method = 'talabat_card' THEN payments.paid ELSE 0 END) AS paidTalabatCard`
            )
          )
          .from('orders')
          .joinRaw(
            'LEFT JOIN payments ON orders.id = payments.order_id AND payments.shift_id = orders.shift_id'
          )
          .whereIn(
            'orders.shift_id',
            this.shifts.map((shift) => shift.id)
          )
          .where(`orders.${ordersBykey}`, ordersByValue)
        if (ordersBykey === 'type') subquery.where('orders.status', OrderStatus.Completed)
        subquery.groupBy('orders.id').as('orders')
      })

    const result = await query

    if (result.length === 0) {
      return {
        count: 0,
        paidCash: 0,
        paidCard: 0,
        paidTalabatCard: 0,
        total: 0,
        profit: 0,
        discount: 0,
      }
    }
    return result[0]
  }

  private baseQuery() {
    return Order.query()
      .select(
        'orders.*',
        db.raw(`SUM(CASE WHEN payments.method = 'cash' THEN payments.paid ELSE 0 END) AS paidCash`),
        db.raw(`SUM(CASE WHEN payments.method = 'card' THEN payments.paid ELSE 0 END) AS paidCard`),
        db.raw(
          `SUM(CASE WHEN payments.method = 'talabat_card' THEN payments.paid ELSE 0 END) AS paidTalabatCard`
        ),
        db.raw(`SUM(CASE WHEN payments.paid > 0 THEN payments.paid ELSE 0 END) AS paid`),
        db.raw(`orders.total - SUM(payments.paid) AS remaining`)
      )
      .joinRaw(
        'LEFT JOIN payments ON orders.id = payments.order_id AND payments.shift_id = orders.shift_id'
      )
      .whereIn(
        'orders.shift_id',
        this.shifts.map((shift) => shift.id)
      )
      .groupBy('orders.id')
  }

  async orders() {
    const paginator = new PaginatorService()
    return await paginator.paginate(this.baseQuery())
  }

  async ordersByPaymentMethod() {
    const paginator = new PaginatorService()
    const { request } = HttpContext.getOrFail()
    const queries = request.qs()
    const method = queries.method
    return await paginator.paginate(
      this.baseQuery().havingRaw(
        `SUM(CASE WHEN payments.method = '${method}' THEN payments.paid ELSE 0 END) > 0`
      )
    )
  }

  async ordersByStatusOrType() {
    const paginator = new PaginatorService()
    const { request } = HttpContext.getOrFail()
    const queries = request.qs()
    const ordersBykey = queries.ordersByKey
    const ordersByValue = queries.ordersByValue
    console.log(ordersBykey, ordersByValue)
    return await paginator.paginate(this.baseQuery().where(`orders.${ordersBykey}`, ordersByValue))
  }

  async ordersHasDiscounts() {
    const paginator = new PaginatorService()
    return await paginator.paginate(this.baseQuery().where(`orders.discount`, '>', 0))
  }
}
