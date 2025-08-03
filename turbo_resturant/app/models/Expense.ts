import { BaseModel, belongsTo, column } from '@adonisjs/lucid/orm';
import type { BelongsTo } from "@adonisjs/lucid/types/relations";
import { DateTime } from 'luxon';
import ExpenseType from './ExpenceType.js';
import Shift from './Shift.js';

export default class Expense extends BaseModel {
  serializeExtras = true
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare amount: number

  @column()
  declare description: string

  @column()
  declare shiftId: number

  @column()
  declare expenseTypeId: number

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

  @belongsTo(() => Shift)
  declare shift: BelongsTo<typeof Shift>

  @belongsTo(() => ExpenseType)
  declare expenseType: BelongsTo<typeof ExpenseType>
}
