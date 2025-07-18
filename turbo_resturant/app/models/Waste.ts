import {
  BaseModel,
  belongsTo,
  column,
  computed,
  hasMany,
} from '@adonisjs/lucid/orm';
import type { BelongsTo, HasMany } from "@adonisjs/lucid/types/relations";
import { DateTime } from 'luxon';
import WastedItem from './WastedItem.js';
import User from './originaluser.js';

export default class Waste extends BaseModel {
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare total: number

  @column()
  declare userId: number

  @column()
  declare closed: boolean

  @computed()
  public get closedString() {
    return this.closed ? 'مغلق' : 'مفتوح'
  }

  @column.dateTime({
    autoCreate: true,
    serialize: (value?: DateTime) => {
      return value ? value.toFormat('HH:mm:ss yyyy-MM-dd') : value
    },
  })
  declare createdAt: DateTime

  @column.dateTime({ autoCreate: true, autoUpdate: true })
  declare updatedAt: DateTime

  @belongsTo(() => User)
  declare user: BelongsTo<typeof User>

  @hasMany(() => WastedItem)
  declare items: HasMany<typeof WastedItem>
}
