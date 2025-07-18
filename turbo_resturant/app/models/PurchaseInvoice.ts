import { PaymentStatus } from '#enums/InvoicePaymentEnums'
import User from '#models/originaluser'
import Supplier from '#models/Supplier'
import {
  BaseModel,
  belongsTo,
  column,
  computed,
  hasMany,
} from '@adonisjs/lucid/orm'
import type { BelongsTo, HasMany } from "@adonisjs/lucid/types/relations"
import { DateTime } from 'luxon'
import PurchaseInvoiceItem from './PurchaseInvoiceItem.js'

export default class PurchaseInvoice extends BaseModel {
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare total: number

  @column()
  declare paid: number

  @column()
  declare status: PaymentStatus

  @column()
  declare closed: boolean

  @computed()
  public get statusString() {
    switch (this.status) {
      case PaymentStatus.FullPaid:
        return 'مدفوع كاملا'
      case PaymentStatus.PartialPaid:
        return 'غير مدفوع كاملا'
    }
  }

  @computed()
  public get closedString() {
    return this.closed ? 'مغلق' : 'مفتوح'
  }

  @column()
  declare supplierId: number

  @column()
  declare userId: number

  @column.dateTime({
    autoCreate: true,
    autoUpdate: true,
    serialize: (value: DateTime) => {
      return value.toFormat('HH:mm:ss yyyy-MM-dd')
    },
  })
  declare createdAt: DateTime

  @column.dateTime({ autoCreate: true, autoUpdate: true })
  declare updatedAt: DateTime

  @belongsTo(() => Supplier)
  declare supplier: BelongsTo<typeof Supplier>

  @belongsTo(() => User)
  declare user: BelongsTo<typeof User>

  @hasMany(() => PurchaseInvoiceItem)
  declare items: HasMany<typeof PurchaseInvoiceItem>
}
