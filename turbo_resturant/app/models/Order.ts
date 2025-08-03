import { serviceChanrge } from '#constants/constants'
import { OrderStatus, OrderType } from '#enums/OrderEnums'
import { PaymentStatus } from '#enums/PaymentEnums'
import { BaseModel, belongsTo, column, computed, hasMany, hasOne } from '@adonisjs/lucid/orm'
import type { BelongsTo, HasMany, HasOne } from '@adonisjs/lucid/types/relations'
import { DateTime } from 'luxon'
import Customer from './Customer.js'
import DineTable from './DineTable.js'
import Driver from './Driver.js'
import OrderItem from './OrderItem.js'
import Payment from './Payment.js'
import Shift from './Shift.js'
import User from './User.js'

export default class Order extends BaseModel {
  serializeExtras = true
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare customerId: number | null

  @column()
  declare driverId: number | null

  @column()
  declare userId: number | null

  @column()
  declare shiftId: number

  @column()
  declare status: OrderStatus

  @column()
  declare type: OrderType

  @column()
  declare subTotal: number

  @column()
  declare tax: number

  @column()
  declare service: number

  @column()
  declare discount: number

  @column()
  declare tempDiscountPercent: number

  @column()
  declare total: number

  @column()
  declare profit: number

  @column()
  declare paymentStatus: PaymentStatus

  @column()
  declare dineTableNumber: string | null

  @column()
  declare kitchenNotes: string | null

  @column()
  declare orderNotes: string | null

  @column()
  declare orderNumber: string

  /**
   * The difference between the web order total and the POS order subTotal
   * +ve value means the web order prices is more than the POS order prices
   * -ve value means the web order prices is less than the POS order prices
   */
  @column()
  declare webPosDiff: number

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

  @belongsTo(() => Customer)
  declare customer: BelongsTo<typeof Customer>

  @belongsTo(() => Driver)
  declare driver: BelongsTo<typeof Driver>

  @belongsTo(() => Shift)
  declare shift: BelongsTo<typeof Shift>

  @hasOne(() => DineTable)
  declare dineTable: HasOne<typeof DineTable>

  @hasMany(() => Payment)
  declare payments: HasMany<typeof Payment>

  @hasMany(() => OrderItem)
  declare items: HasMany<typeof OrderItem>

  @belongsTo(() => User)
  declare user: BelongsTo<typeof User>

  @computed()
  public get typeString() {
    switch (this.type) {
      case OrderType.Delivery:
        return 'دليفري'
      case OrderType.DineIn:
        return 'صالة'
      case OrderType.Takeaway:
        return 'تيك اواي'
      case OrderType.Companies:
        return 'شركات'
      case OrderType.Talabat:
        return 'طلبات'
      case OrderType.Gazzer:
        return 'جازر'
      case OrderType.WebDelivery:
        return 'دليفري اونلاين'
      case OrderType.WebTakeaway:
        return 'تيك اواي اونلاين'
    }
  }

  @computed()
  public get statusString() {
    switch (this.status) {
      case OrderStatus.Pending:
        return 'قيد الانتظار'
      case OrderStatus.OutForDelivery:
        return 'في الطريق'
      case OrderStatus.Completed:
        return 'مكتمل'
      case OrderStatus.Processing:
        return 'تحت التشغيل'
      case OrderStatus.Cancelled:
        return 'ملغي'
    }
  }

  public static serviceCharge = serviceChanrge
  public static taxRate = 0.0
}
