import { BaseModel, column, hasMany } from '@adonisjs/lucid/orm';
import type { HasMany } from "@adonisjs/lucid/types/relations";
import Expense from './Expense.js';

export default class ExpenseType extends BaseModel {
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare name: string

  @hasMany(() => Expense)
  declare expenses: HasMany<typeof Expense>
}
