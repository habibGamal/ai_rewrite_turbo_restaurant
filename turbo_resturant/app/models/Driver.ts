import { BaseModel, column, hasMany } from '@adonisjs/lucid/orm'
import Order from './Order.js'
import type { HasMany } from "@adonisjs/lucid/types/relations";

export default class Driver extends BaseModel {
  @column({ isPrimary: true })
  declare id: number


  @column()
  declare name: string

  @column()
  declare phone: string

  @hasMany(() => Order)
  declare orders: HasMany<typeof Order>
}
