import { User } from './Models.js'

export type OrderItemT = {
  productId: number
  name: string
  quantity: number
  price: number
  notes?: string
  initialQuantity: number | null
}

export type OrderItemsReducerActions =
  | {
      type: 'add'
      orderItem: OrderItemT
      user: User
    }
  | {
      type: 'remove'
      id: number
      user: User
    }
  | {
      type: 'increment'
      id: number
      user: User
    }
  | {
      type: 'decrement'
      id: number
      user: User
    }
  | {
      type: 'changeQuantity'
      id: number
      quantity: number
      user: User
    }
  | {
      type: 'delete'
      id: number
      user: User
    }
  | {
      type: 'changeNotes'
      id: number
      notes: string
      user: User
    }
  | {
      type: 'init'
      orderItems: OrderItemT[]
      user: User
    }
