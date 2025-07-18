import { DateTime } from 'luxon'
import { BaseModel, column, computed, hasMany } from '@adonisjs/lucid/orm'
import Order from './Order.js'
import type { HasMany } from "@adonisjs/lucid/types/relations";

export default class Customer extends BaseModel {
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare name: string

  @column()
  declare phone: string

  @column()
  declare hasWhatsapp: boolean

  @column()
  declare address: string

  @column()
  declare region: string

  @column()
  declare deliveryCost: number

  @hasMany(() => Order)
  declare orders: HasMany<typeof Order>

  @column.dateTime({ autoCreate: true })
  declare createdAt: DateTime

  @column.dateTime({ autoCreate: true, autoUpdate: true })
  declare updatedAt: DateTime

  @computed()
  public get ordersTotal(): number | undefined {
    return this.$extras.ordersTotal
  }

  @computed()
  public get ordersProfit(): number | undefined {
    return this.$extras.ordersProfit
  }
}
