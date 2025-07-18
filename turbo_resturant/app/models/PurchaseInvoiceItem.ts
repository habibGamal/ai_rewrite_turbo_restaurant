import Product from '#models/Product'
import PurchaseInvoice from '#models/PurchaseInvoice'
import { BaseModel, belongsTo, column } from '@adonisjs/lucid/orm'
import type { BelongsTo } from "@adonisjs/lucid/types/relations"

export default class PurchaseInvoiceItem extends BaseModel {
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare purchaseInvoiceId: number

  @column()
  declare productId: number

  @column()
  declare quantity: number

  @column()
  declare cost: number

  @column()
  declare total: number

  @belongsTo(() => PurchaseInvoice)
  declare purchaseInvoice: BelongsTo<typeof PurchaseInvoice>

  @belongsTo(() => Product)
  declare product: BelongsTo<typeof Product>
}
