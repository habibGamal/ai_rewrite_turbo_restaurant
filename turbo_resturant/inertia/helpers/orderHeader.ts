import { OrderType } from '#enums/OrderEnums'
import { Order } from '../types/Models.js'

export const orderHeader = (order: Order) => {
  switch (order.type) {
    case OrderType.DineIn:
      return [
        {
          title: 'الصالة',
        },
        {
          title: `طاولة ${order.dineTableNumber ? order.dineTableNumber : '-'}`,
        },
        {
          title: `طلب رقم ${order.orderNumber}`,
        },
      ]
    case OrderType.Delivery:
      return [
        {
          title: 'الديلفري',
        },
        {
          title: `طلب رقم ${order.orderNumber}`,
        },
        {
          title: `رقم الهاتف ${order.customer?.phone || '-'}`,
        },
      ]
    case OrderType.Takeaway:
      return [
        {
          title: 'التيك اواي',
        },
        {
          title: `طلب رقم ${order.orderNumber}`,
        },
      ]
    case OrderType.Talabat:
      return [
        {
          title: 'طلبات',
        },
        {
          title: `طلب رقم ${order.orderNumber}`,
        },
      ]
    case OrderType.Companies:
      return [
        {
          title: 'شركات',
        },
        {
          title: `طلب رقم ${order.orderNumber}`,
        },
      ]
    default:
      return [
        {
          title: 'خطاء',
        },
      ]
  }
}
