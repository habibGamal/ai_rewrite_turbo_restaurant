import { OrderStatus } from "#enums/OrderEnums"

export const orderStatus = (status: OrderStatus) => {
  if (status === OrderStatus.Cancelled) return { text: 'ملغي', color: 'red' }
  if (status === OrderStatus.Completed) return { text: 'منتهي', color: 'purple' }
  return { text: 'تحت التشغيل', color: 'green' }
}
