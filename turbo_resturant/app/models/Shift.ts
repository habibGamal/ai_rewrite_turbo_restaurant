import {
  BaseModel,
  belongsTo,
  column,
  computed,
  hasMany
} from '@adonisjs/lucid/orm'
import type { BelongsTo, HasMany } from "@adonisjs/lucid/types/relations"
import { DateTime } from 'luxon'
import Expense from './Expense.js'
import Order from './Order.js'
import Payment from './Payment.js'
import User from './User.js'

export default class Shift extends BaseModel {
  @column({ isPrimary: true })
  declare id: number

  @column.dateTime({
    autoCreate: true,
    serialize: (value: DateTime) => {
      return value.toFormat('yyyy-MM-dd HH:mm:ss')
    },
  })
  declare startAt: DateTime

  @column.dateTime({
    serialize: (value: DateTime | null) => {
      return value ? value.toFormat('yyyy-MM-dd HH:mm:ss') : null
    },
  })
  declare endAt: DateTime

  @column()
  declare userId: number

  @column()
  declare startCash: number

  @column()
  declare endCash: number

  @column()
  declare lossesAmount: number

  @column()
  declare realCash: number

  @column()
  declare hasDeficit: boolean

  @column()
  declare closed: boolean

  @computed()
  public get closedString() {
    return this.closed ? 'مغلق' : 'مفتوح'
  }

  @belongsTo(() => User)
  declare user: BelongsTo<typeof User>

  @hasMany(() => Expense)
  declare expenses: HasMany<typeof Expense>

  @hasMany(() => Order)
  declare orders: HasMany<typeof Order>

  @hasMany(() => Payment)
  declare payments: HasMany<typeof Payment>
}
