import { Button, TableColumnsType } from 'antd'
import {
  Order,
  Product,
  PurchaseInvoice,
  Recipe,
  ReturnPurchaseInvoice,
  Snapshot,
  Waste,
} from '~/types/Models'
type Actions = {
  showDetails: (product: Product) => void
}

export type Data = {
  notes: string
  startDate: string
  endDate: string
  startSnapshot: Snapshot
  endSnapshot: Snapshot
  purchaseInvoices: PurchaseInvoice[]
  returnPurchaseInvoices: ReturnPurchaseInvoice[]
  orders: Order[]
  wastes: Waste[]
  recipes: Recipe[]
  products: Product[]
  orderItems: {
    productId: number
    quantity: number
  }[]
}

export default class ResturantRService {
  private purchaseItems: PurchaseInvoice['items']
  private returnItems: ReturnPurchaseInvoice['items']
  private wasteItems: Waste['items']
  private orderItems: {
    productId: number
    quantity: number
  }[]
  private productsSales: Record<number, number>
  public sales: number
  constructor(private data: Data) {
    this.purchaseItems = this.data.purchaseInvoices.flatMap((invoice) => invoice.items)
    this.wasteItems = this.data.wastes.flatMap((waste) => waste.items)
    this.returnItems = this.data.returnPurchaseInvoices.flatMap((invoice) => invoice.items)
    this.orderItems = this.data.orderItems
    this.productsSales = this.calcProductsSales()
    this.sales = this.data.orders.reduce((acc, item) => acc + item.total, 0)
  }

  public get startBalance() {
    return this.data.startSnapshot.data.reduce(
      (acc, item) => acc + item.start_quantity * item.cost,
      0
    )
  }

  public get endBalance() {
    return this.data.endSnapshot.data.reduce((acc, item) => acc + item.end_quantity * item.cost, 0)
  }

  public get wastes() {
    return this.data.wastes.reduce((acc, item) => acc + item.total, 0)
  }

  public get returns() {
    return this.data.returnPurchaseInvoices.reduce((acc, item) => acc + item.total, 0)
  }

  public get purchases() {
    return this.data.purchaseInvoices.reduce((acc, item) => acc + item.total, 0)
  }

  public get dataSource() {
    return this.data.products.map((product) => this.productAnalysis(product))
  }

  public productAnalysis(product: Product) {
    const startQuantity = this.startQuantity(product)
    const incomeQuanitity = this.incomeQuanitity(product)
    const totalIncome = startQuantity + incomeQuanitity
    const salesQuantity = this.productsSales[product.id] || 0
    const wasteAndReturnQuantity = this.wasteQuantity(product) + this.returnQuantity(product)
    const totalOut = salesQuantity + wasteAndReturnQuantity
    const realRemainQuantity = this.realRemainQuantity(product)
    const idealRemainQuantity = totalIncome - totalOut
    const incomeCost = this.incomeCost(product)
    const avgCost = incomeQuanitity > 0 ? incomeCost / incomeQuanitity : product.cost
    const deviation = realRemainQuantity - idealRemainQuantity
    const deviationValue = deviation * avgCost
    const deviationPercentage = this.sales > 0 ? (deviationValue / this.sales) * 100 : 0
    return {
      product,
      name: product.name,
      startQuantity,
      incomeQuanitity,
      totalIncome,
      salesQuantity,
      wasteAndReturnQuantity,
      totalOut,
      realRemainQuantity,
      idealRemainQuantity,
      incomeCost,
      avgCost,
      deviation,
      deviationValue,
      deviationPercentage,
    }
  }

  public productDetails(product: Product) {
    const recipies = this.data.recipes.filter((recipe) =>
      recipe.recipe.find((recipe) => recipe.product_id === product.id)
    )
    const saledItems: Record<number, { quantity: number; recipeQuantity: number }> =
      this.orderItems!.filter((item) =>
        // item should be in recipies
        recipies.find((recipe) => recipe.product_id === item.productId)
      )
        // group order items by product id
        .reduce(
          (acc, item) => {
            if (!acc[item.productId]) {
              acc[item.productId] = {
                quantity: 0,
                recipeQuantity: recipies
                  .find((recipe) => recipe.product_id === item.productId)!
                  .recipe.filter((recipe) => recipe.product_id === product.id)
                  .reduce((acc, recipe) => acc + recipe.quantity, 0),
              }
            }
            acc[item.productId].quantity += item.quantity
            return acc
          },
          {} as Record<number, { quantity: number; recipeQuantity: number }>
        )
    return Object.entries(saledItems).map(([key, value]) => ({
      name: this.data.recipes.find((recipe) => recipe.product_id === Number(key))!.product_name,
      productId: key,
      quantity: value.quantity,
      recipeQuantity: value.recipeQuantity,
    }))
  }

  public serviceUi(actions: Actions) {
    return new ServiceUi(actions, this)
  }

  private calcProductsSales() {
    const orderItemsGrouped = this.orderItems.reduce(
      (acc, item) => {
        if (!acc[item!.productId]) {
          acc[item!.productId] = {
            quantity: 0,
            recipe: this.data.recipes.find((recipe) => recipe.product_id === item!.productId)!,
          }
        }
        acc[item!.productId].quantity += item!.quantity
        return acc
      },
      {} as Record<number, { quantity: number; recipe: Recipe }>
    )
    const productsSales: Record<number, number> = {}

    for (const [, value] of Object.entries(orderItemsGrouped)) {
      const notManifactured = !value.recipe
      if (notManifactured) continue
      for (const recipe of value.recipe.recipe) {
        if (!productsSales[recipe.product_id]) productsSales[recipe.product_id] = 0
        productsSales[recipe.product_id] += value.quantity * recipe.quantity
      }
    }
    return productsSales
  }

  private startQuantity(product: Product) {
    return (
      this.data.startSnapshot.data.find((item) => item.product_id === product.id)?.start_quantity ||
      0
    )
  }

  private incomeQuanitity(product: Product) {
    return this.purchaseItems.reduce((acc, item) => {
      if (item.productId === product.id) acc += item.quantity
      return acc
    }, 0)
  }

  private wasteQuantity(product: Product) {
    return this.wasteItems.reduce((acc, item) => {
      if (item.productId === product.id) acc += item.quantity
      return acc
    }, 0)
  }

  private returnQuantity(product: Product) {
    return this.returnItems.reduce((acc, item) => {
      if (item.productId === product.id) acc += item.quantity
      return acc
    }, 0)
  }

  private realRemainQuantity(product: Product) {
    return (
      this.data.endSnapshot.data.find((item) => item.product_id === product.id)?.end_quantity ?? 0
    )
  }

  private incomeCost(product: Product) {
    return this.purchaseItems.reduce((acc, item) => {
      if (item.productId === product.id) acc += item.total
      return acc
    }, 0)
  }
}

class ServiceUi {
  constructor(
    private actions: Actions,
    private service: ResturantRService
  ) {}

  public get columns(): TableColumnsType<{
    product: Product
    name: string
    startQuantity: number
    incomeQuanitity: number
    totalIncome: number
    salesQuantity: number
    wasteAndReturnQuantity: number
    totalOut: number
    idealRemainingQuantity: number
    realRemainingQuantity: number
    avgCost: number
    deviation: number
    deviationValue: number
    deviationPercentage: number
  }> {
    return [
      {
        title: 'المنتج',
        dataIndex: 'name',
        key: 'name',
      },
      {
        title: 'الكمية البدائية',
        dataIndex: 'startQuantity',
        key: 'startQuantity',
        render: (value) => value.toFixed(2),
        sorter: (a, b) => a.startQuantity - b.startQuantity,
      },
      {
        title: 'كمية الوارد',
        dataIndex: 'incomeQuanitity',
        key: 'incomeQuanitity',
        render: (value) => value.toFixed(2),
        sorter: (a, b) => a.incomeQuanitity - b.incomeQuanitity,
      },
      {
        title: 'الكمية الكلية',
        dataIndex: 'totalIncome',
        key: 'totalIncome',
        render: (value) => value.toFixed(2),
        sorter: (a, b) => a.totalIncome - b.totalIncome,
      },
      {
        title: 'كمية المبيعات',
        dataIndex: 'salesQuantity',
        key: 'salesQuantity',
        render: (value) => value.toFixed(2),
        sorter: (a, b) => a.salesQuantity - b.salesQuantity,
      },
      {
        title: 'كمية الفاقد والمرتجع',
        dataIndex: 'wasteAndReturnQuantity',
        key: 'wasteAndReturnQuantity',
        render: (value) => value.toFixed(2),
        sorter: (a, b) => a.wasteAndReturnQuantity - b.wasteAndReturnQuantity,
      },
      {
        title: 'الكمية الكلية المنصرفة',
        dataIndex: 'totalOut',
        key: 'totalOut',
        render: (value) => value.toFixed(2),
        sorter: (a, b) => a.totalOut - b.totalOut,
      },
      {
        title: 'الكمية المتبقية المثالية',
        dataIndex: 'idealRemainQuantity',
        key: 'idealRemainQuantity',
        render: (value) => value.toFixed(2),
        sorter: (a, b) => a.idealRemainingQuantity - b.idealRemainingQuantity,
      },
      {
        title: 'الكمية المتبقية الفعلية',
        dataIndex: 'realRemainQuantity',
        key: 'realRemainQuantity',
        render: (value) => value.toFixed(2),
        sorter: (a, b) => a.realRemainingQuantity - b.realRemainingQuantity,
      },
      {
        title: 'متوسط التكلفة',
        dataIndex: 'avgCost',
        key: 'avgCost',
        render: (value) => value.toFixed(2),
        sorter: (a, b) => a.avgCost - b.avgCost,
      },
      {
        title: 'الانحراف',
        dataIndex: 'deviation',
        key: 'deviation',
        render: (value) => value.toFixed(2),
        sorter: (a, b) => a.deviation - b.deviation,
      },
      {
        title: 'قيمة الانحراف',
        dataIndex: 'deviationValue',
        key: 'deviationValue',
        render: (value) => value.toFixed(2),
        sorter: (a, b) => a.deviationValue - b.deviationValue,
      },
      {
        title: 'نسبة الانحراف',
        dataIndex: 'deviationPercentage',
        key: 'deviationPercentage',
        render: (value) => value.toFixed(2),
        sorter: (a, b) => a.deviationPercentage - b.deviationPercentage,
      },
      {
        title: 'التفاصيل',
        key: 'showDetails',
        render: (value, record) => (
          <Button type="primary" onClick={() => this.actions.showDetails(record.product)}>
            عرض
          </Button>
        ),
      },
    ]
  }

  public get detailsColumns(): TableColumnsType<{
    name: string
    quantity: number
    recipeQuantity: number
  }> {
    return [
      {
        title: 'المنتج',
        dataIndex: 'name',
        key: 'name',
      },
      {
        title: 'الكمية',
        dataIndex: 'quantity',
        key: 'quantity',
        render: (value) => value.toFixed(2),
      },
      {
        title: 'كمية الوصفة',
        dataIndex: 'recipeQuantity',
        key: 'recipeQuantity',
        render: (value) => value.toFixed(2),
      },
      {
        title: 'الكمية الكلية',
        key: 'totalQuantity',
        render: (value, record) => (record.quantity * record.recipeQuantity).toFixed(2),
      },
    ]
  }
}
