import { serviceChanrge } from '#constants/constants'
import { OrderType } from '#enums/OrderEnums'
import { UserRole } from '#enums/UserEnums'
import { Order } from '../types/Models.js'
import { OrderItemT, OrderItemsReducerActions } from '../types/Types.js'

export const orderItemsReducer = (state: OrderItemT[], action: OrderItemsReducerActions) => {
  let canChange = true
  let limit = 0
  if (action.type !== 'add' && action.type !== 'init') {
    const isAdmin = action.user.role === UserRole.Admin
    const orderItem = action.id
      ? state.find((orderItem) => orderItem.productId === action.id!)
      : null
    const itemSavedBefore = orderItem.initialQuantity !== null
    if (!isAdmin && itemSavedBefore) {
      canChange = false
      limit = orderItem.initialQuantity
    }
  }

  switch (action.type) {
    case 'add': {
      // Check if the order item already exists
      const orderItem = state.find(
        (orderItem) => orderItem.productId === action.orderItem.productId
      )
      if (orderItem) {
        // If it exists, increment the quantity
        return state.map((orderItem) =>
          orderItem.productId === action.orderItem.productId
            ? { ...orderItem, quantity: orderItem.quantity + 1 }
            : orderItem
        )
      }
      return [...state, action.orderItem]
    }
    case 'remove':
      return canChange ? state.filter((orderItem) => orderItem.productId !== action.id) : state
    case 'increment':
      return state.map((orderItem) =>
        orderItem.productId === action.id
          ? { ...orderItem, quantity: orderItem.quantity + 1 }
          : orderItem
      )
    case 'decrement': {
      const orderItem = state.find((orderItem) => orderItem.productId === action.id)
      if (!canChange && orderItem.quantity === limit) return state
      return state.map((orderItem) => {
        if (orderItem.productId !== action.id) return orderItem
        if (orderItem.quantity === 1) {
          return orderItem
        }
        return { ...orderItem, quantity: orderItem.quantity - 1 }
      })
    }
    case 'changeQuantity': {
      // const orderItem = state.find((orderItem) => orderItem.productId === action.id)
      if (!canChange && action.quantity < limit) {
        action.quantity = limit
      }
      return state.map((orderItem) => {
        if (orderItem.productId !== action.id) return orderItem
        if (action.quantity !== null) {
          action.quantity = Math.floor(action.quantity)
        }
        return { ...orderItem, quantity: action.quantity }
      })
    }
    case 'changeNotes': {
      return state.map((orderItem) => {
        if (orderItem.productId !== action.id) return orderItem
        return { ...orderItem, notes: action.notes }
      })
    }
    case 'delete':
      return canChange ? state.filter((orderItem) => orderItem.productId !== action.id) : state
    case 'init':
      return action.orderItems
    default:
      throw new Error('Action not found')
  }
}

export const orderPaymentValues = (
  order: Order,
  orderItems: OrderItemT[],
  discount = 0.0,
  discountType: 'percent' | 'value'
) => {
  const subTotal = orderItems.reduce(
    (acc, orderItem) => acc + orderItem.price * orderItem.quantity,
    0
  )
  const tax = subTotal * 0
  let serviceCharge = 0
  if (order.type === OrderType.Delivery) {
    order.customer?.deliveryCost
      ? (serviceCharge = order.customer.deliveryCost)
      : (serviceCharge = 0)
  } else if (order.type === OrderType.DineIn) {
    serviceCharge = subTotal * serviceChanrge
  }
  const discountValue = discountType === 'percent' ? subTotal * (discount / 100) : discount
  const total = Math.ceil(subTotal + tax + serviceCharge - discountValue)
  return {
    subTotal,
    tax,
    serviceCharge,
    discount: discountValue,
    total,
  }
}

export const orderPaymentItems = ({
  subTotal,
  tax,
  serviceCharge,
  discount,
  total,
}: ReturnType<typeof orderPaymentValues>) => {
  return [
    {
      key: '1',
      label: 'المجموع',
      children: subTotal.toFixed(1),
    },
    {
      key: '2',
      label: 'الضريبة',
      children: tax.toFixed(1),
    },
    {
      key: '3',
      label: 'الخدمة',
      children: serviceCharge.toFixed(1),
    },
    {
      key: '4',
      label: 'الخصم',
      children: discount.toFixed(1),
    },
    {
      key: '5',
      label: 'الاجمالي',
      children: total.toFixed(1),
    },
  ]
}
