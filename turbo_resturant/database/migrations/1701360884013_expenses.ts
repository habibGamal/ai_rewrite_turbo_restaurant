import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'expenses'

  public async up() {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table.float('amount').notNullable()
      table.string('description').notNullable()
      table.integer('shift_id').unsigned().references('id').inTable('shifts')
      table.integer('expense_type_id').unsigned().references('id').inTable('expense_types').onDelete('CASCADE')
      table.timestamps(true, true)
    })
  }

  public async down() {
    this.schema.dropTable(this.tableName)
  }
}
