import { PaymentMethod } from '#enums/PaymentEnums'
import { BaseModel, belongsTo, column } from '@adonisjs/lucid/orm'
import type { BelongsTo } from "@adonisjs/lucid/types/relations"
import { DateTime } from 'luxon'
import Order from './Order.js'
import Shift from './Shift.js'

export default class Payment extends BaseModel {
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare shiftId: number

  @column()
  declare orderId: number

  @column()
  declare method: PaymentMethod

  @column()
  declare paid: number

  @column.dateTime({ autoCreate: true, autoUpdate: true })
  declare createdAt: DateTime

  @column.dateTime({ autoCreate: true, autoUpdate: true })
  declare updatedAt: DateTime

  @belongsTo(() => Order)
  declare order: BelongsTo<typeof Order>

  @belongsTo(() => Shift)
  declare shift: BelongsTo<typeof Shift>
}
