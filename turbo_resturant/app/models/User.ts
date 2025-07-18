import { UserRole } from '#enums/UserEnums'
import { BaseModel, beforeSave, column, computed, hasMany } from '@adonisjs/lucid/orm'
import type { HasMany } from '@adonisjs/lucid/types/relations'
import { DateTime } from 'luxon'
import PurchaseInvoice from './PurchaseInvoice.js'
import ReturnPurchaseInvoice from './ReturnPurchaseInvoice.js'
import Shift from './Shift.js'
import Stocktaking from './Stocktaking.js'
import Waste from './Waste.js'
import { withAuthFinder } from '@adonisjs/auth'
import { compose } from '@adonisjs/core/helpers'
import hash from '@adonisjs/core/services/hash'

const AuthFinder = withAuthFinder(() => hash.use('scrypt'), {
  uids: ['email'],
  passwordColumnName: 'password',
})

export default class User extends compose(BaseModel, AuthFinder) {
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare email: string

  @column({ serializeAs: null })
  declare password: string

  @column()
  declare rememberMeToken: string | null

  @column()
  declare role: UserRole

  @column.dateTime({ autoCreate: true })
  declare createdAt: DateTime

  @column.dateTime({ autoCreate: true, autoUpdate: true })
  declare updatedAt: DateTime

  // @beforeSave()
  // public static async hashPassword(user: User) {
  //   console.log(user)
  //   if (user.$dirty.password) {
  //     user.password = await hash.make(user.password)
  //   }
  // }

  @hasMany(() => Shift)
  declare shifts: HasMany<typeof Shift>

  @hasMany(() => PurchaseInvoice)
  declare purchaseInvoices: HasMany<typeof PurchaseInvoice>

  @hasMany(() => ReturnPurchaseInvoice)
  declare returnPurchaseInvoices: HasMany<typeof ReturnPurchaseInvoice>

  @hasMany(() => Waste)
  declare wastes: HasMany<typeof Waste>

  @hasMany(() => Stocktaking)
  declare stocktaking: HasMany<typeof Stocktaking>

  @computed()
  public get roleString() {
    switch (this.role) {
      case UserRole.Admin:
        return 'مدير'
      case UserRole.Cashier:
        return 'كاشير'
      case UserRole.Viewer:
        return 'متابع'
      case UserRole.Watcher:
        return 'مراقب'
      default:
        return 'غير معروف'
    }
  }
}
