import ReturnPurchaseInvoice from '#models/ReturnPurchaseInvoice';
import { BaseModel, belongsTo, column } from '@adonisjs/lucid/orm';
import type { BelongsTo } from "@adonisjs/lucid/types/relations";
import Product from './Product.js';

export default class ReturnPurchaseInvoiceItem extends BaseModel {
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare returnPurchaseInvoiceId: number

  @column()
  declare productId: number

  @column()
  declare quantity: number

  @column()
  declare price: number

  @column()
  declare total: number

  @belongsTo(() => ReturnPurchaseInvoice)
  declare returnPurchaseInvoice: BelongsTo<typeof ReturnPurchaseInvoice>

  @belongsTo(() => Product)
  declare product: BelongsTo<typeof Product>
}
