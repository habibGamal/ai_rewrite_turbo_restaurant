import Product from '#models/Product'
import logger from '@adonisjs/core/services/logger'
import fs from 'fs'
type OrderItem = {
  productId: number
  quantity: number
  price: number
  cost: number
  total: number
  notes: string | null | undefined
}

export default function watcher(shiftId: number) {
  return logger.create({
    enabled: true,
    name: 'watcher',
    level: 'info',
    transport: {
      targets: [
        {
          target: 'pino/file',
          level: 'info',
          options: {
            destination: `./logs/shift.${shiftId}.log`,
          },
        },
      ],
    },
  })
}

export async function orderItemsCompare(originalOrderItems: OrderItem[], newOrderItems: OrderItem[]) {
  const productIds = [
    ...new Set([
      ...originalOrderItems.map((item) => item.productId),
      ...newOrderItems.map((item) => item.productId),
    ]),
  ]

  const differences: Record<number, number> = {}
  console.log("productIds", productIds)

  for (const productId of productIds) {
    const originalItem = originalOrderItems.find((item) => item.productId === productId)
    const newItem = newOrderItems.find((item) => item.productId === productId)
    const diff = itemCompare(originalItem, newItem)
    if (diff !== 0) {
      differences[productId] = diff
    }
  }

  const products = await Product.query()
    .whereIn('id', Object.keys(differences))
    .select('id', 'name')

  return products.map((product) => {
    return {
      productName: product.name,
      diff: differences[product.id],
    }
  })
}

function itemCompare(originalItem?: OrderItem, newItem?: OrderItem) {
  const originalQuantity = originalItem?.quantity ?? 0
  const newQuantity = newItem?.quantity ?? 0
  /**
   * +ve added , -ve removed
   */
  console.log("newQuantity", newQuantity)
  console.log("originalQuantity", originalQuantity)
  return newQuantity - originalQuantity
}


export async function readLogFile(shiftId: number) {
  const logFile = `./logs/shift.${shiftId}.log`
  return new Promise<string>((resolve, reject) => {
    fs.readFile(logFile, 'utf8', (err, data) => {
      if (err) {
        reject(err)
      } else {
        resolve(data)
      }
    })
  })
}
