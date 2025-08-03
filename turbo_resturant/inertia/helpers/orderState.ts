import { OrderStatus } from '#enums/OrderEnums'

export const orderStatus = (status: OrderStatus) => {
  if (status === OrderStatus.Cancelled) return { text: 'ملغي', color: 'red' }
  if (status === OrderStatus.Completed) return { text: 'منتهي', color: 'purple' }
  if (status === OrderStatus.Pending) return { text: 'قيد الانتظار', color: 'blue' }
  if (status === OrderStatus.OutForDelivery) return { text: 'في الطريق', color: 'orange' }
  return { text: 'تحت التشغيل', color: 'green' }
}
