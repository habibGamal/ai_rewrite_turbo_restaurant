import { BaseModel, column, hasMany } from '@adonisjs/lucid/orm';
import type { HasMany } from "@adonisjs/lucid/types/relations";
import { DateTime } from 'luxon';
import PurchaseInvoice from './PurchaseInvoice.js';
import ReturnPurchaseInvoice from './ReturnPurchaseInvoice.js';

export default class Supplier extends BaseModel {
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare name: string

  @column()
  declare phone: string

  @hasMany(() => PurchaseInvoice)
  declare purchaseInvoices: HasMany<typeof PurchaseInvoice>

  @hasMany(() => ReturnPurchaseInvoice)
  declare returnPurchaseInvoices: HasMany<typeof ReturnPurchaseInvoice>

  @column.dateTime({ autoCreate: true })
  declare createdAt: DateTime

  @column.dateTime({ autoCreate: true, autoUpdate: true })
  declare updatedAt: DateTime
}
