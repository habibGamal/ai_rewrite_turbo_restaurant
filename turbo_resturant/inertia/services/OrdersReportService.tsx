import { Order } from '../types/Models.js'
export default class OrdersReportService {
  public static mappingToTableData(orders: Order[]) {
    return orders.map((order) => {
      return {
        id: order.id,
        orderNumber: order.orderNumber,
        typeString: order.typeString,
        orderStatus: order.statusString,
        discount: order.discount.toFixed(2),
        total: order.total,
        paid: order.meta.paid,
        paidCard: order.meta.paidCard,
        paidCash: order.meta.paidCash,
        paidTalabatCard: order.meta.paidTalabatCard,
        remaining: order.meta.remaining,
        profit: order.profit,
      }
    })
  }
}
