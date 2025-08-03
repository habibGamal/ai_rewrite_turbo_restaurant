import { PaymentStatus } from '#enums/InvoicePaymentEnums'
import { OrderStatus, OrderType } from '#enums/OrderEnums'
import { UserRole } from '#enums/UserEnums'

export type User = {
  id: number
  email: string
  role: UserRole
}

export type Shift = {
  id: number
  startAt: string
  endAt: string
  userId: number
  startCash: number
  endCash: number
  lossesAmount: number
  realCash: number
  hasDeficit: number
  expenses?: Expense[]
  orders?: Order[]
  payments?: Payment[]
  user?: User
}

export type ExpenseType = {
  id: number
  name: string
  expenses?: Expense[]
}

export type Expense = {
  id: number
  amount: number
  description: string
  shiftId: number
  createdAt: string
  updatedAt: string
  expenseTypeId: number
  expenseType?: ExpenseType
  meta: {
    type: string
  }
}

export type InventoryItem = {
  id: number
  productId: number
  quantity: number
  product: Product
}

export type Order = {
  id: number
  customerId: number | null
  shiftId: number
  status: OrderStatus
  type: OrderType
  items?: OrderItem[]
  createdAt: string
  customer?: Customer
  driver?: Driver
  user?: User
  dineTable?: DineTable
  discount: number
  profit: number
  total: number
  payments?: Payment[]
  payemntStatus: string
  typeString: string
  statusString: string
  dineTableNumber: number | null
  kitchenNotes: string | null
  orderNotes: string | null
  subTotal: number
  service: number
  tax: number
  orderNumber: number
  tempDiscountPercent: number
  webPosDiff: number
  meta: {
    paidCash: number
    paidCard: number
    paidTalabatCard: number
    paid: number
    remaining: number
  }
}

export type Payment = {
  id: number
  shiftId: number
  orderId: number
  method: string
  paid: number
  createdAt: string
  updatedAt: string
  order?: Order
}

export type OrderItem = {
  id: number
  orderId: number
  productId: number
  quantity: number
  price: string
  cost: string
  total: string
  product?: Product
  notes: string | null
}

export type DineTable = {
  id: number
  table_number: number
  orderId: number | null
}

export type Customer = {
  id: number
  name: string
  phone: string
  address: string
  hasWhatsapp: boolean
  region: string
  deliveryCost: number
  ordersProfit?: number
  ordersTotal?: number
  orders?: Order[]
}

export type Driver = {
  id: number
  name: string
  phone: string
  orders?: Order[]
}

export type Region = {
  id: number
  name: string
  deliveryCost: number
}

export type Supplier = {
  id: number
  name: string
  phone: string
  purchaseInvoices?: PurchaseInvoice[]
  returnPurchaseInvoices?: ReturnPurchaseInvoice[]
  createdAt: string
  updatedAt: string
}

export type Printer = {
  id: number
  name: string
  ipAddress: string
  createdAt: string
  updatedAt: string
}

export type Product = {
  id: number
  name: string
  price: number
  cost: number
  type: string
  unit: string
  printerId: number
  categoryId: number
  createdAt: string
  updatedAt: string
  salesTotal?: number
  salesProfit?: number
  salesQuantity?: number
  ingredients?: {
    productId: {
      value: number
      label: string
    }
    type: string
    quantity: number
    cost: number
  }[]
  printers?: Printer[]
}

export type Category = {
  id: number
  name: string
  createdAt: string
  updatedAt: string
  products: Product[]
}

export type PurchaseInvoiceItem = {
  id: number
  purchaseInvoiceId: number
  productId: number
  quantity: number
  cost: number
  total: number
  product: Product
}

export type PurchaseInvoice = {
  id: number
  total: number
  paid: number
  status: PaymentStatus
  statusString: string
  supplierId: number
  userId: number
  createdAt: string
  updatedAt: string
  supplier: Supplier
  items: PurchaseInvoiceItem[]
}

type ReturnPurchaseInvoiceItem = {
  id: number
  returnPurchaseInvoiceId: number
  productId: number
  quantity: number
  price: number
  total: number
  product: Product
}

export type ReturnPurchaseInvoice = {
  id: number
  total: number
  received: number
  status: string
  statusString: string
  supplierId: number
  userId: number
  createdAt: string
  updatedAt: string
  supplier: Supplier
  items: ReturnPurchaseInvoiceItem[]
}

export type Snapshot = {
  id: number
  day: string
  data: {
    product_id: number
    start_quantity: number
    end_quantity: number
    cost: number
  }[]
}

export type Recipe = {
  product_id: number
  product_name: string
  recipe: {
    product_id: number
    quantity: number
  }[]
}

export type StocktakingItem = {
  id: number
  stocktakingId: number
  productId: number
  quantity: number
  cost: number
  total: number
  product: Product
}

export type Stocktaking = {
  id: number
  userId: number
  balance: number
  createdAt: string
  items: StocktakingItem[]
}

export type WasteItem = {
  id: number
  wasteId: number
  productId: number
  quantity: number
  cost: number
  total: number
  product: Product
}

export type Waste = {
  id: number
  total: number
  userId: number
  createdAt: string
  updatedAt: string
  items: WasteItem[]
}
