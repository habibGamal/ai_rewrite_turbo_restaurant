import { BaseModel, belongsTo, column } from '@adonisjs/lucid/orm'
import Order from './Order.js'
import type { BelongsTo } from "@adonisjs/lucid/types/relations";

export default class DineTable extends BaseModel {
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare tableNumber: string

  @column()
  declare orderId: number | null

  @belongsTo(() => Order)
  declare order: BelongsTo<typeof Order>
}
