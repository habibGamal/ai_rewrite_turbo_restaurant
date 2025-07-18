import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'shifts'

  public async up() {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table.dateTime('start_at').notNullable()
      table.dateTime('end_at').nullable()
      table.integer('user_id').unsigned().references('id').inTable('users').onDelete('cascade')
      table.float('start_cash').notNullable()
      table.float('end_cash').nullable()
      table.float('losses_amount').nullable()
      table.float('real_cash').nullable()
      table.boolean('has_deficit').defaultTo(false)
      table.boolean('closed').defaultTo(false)
    })
  }

  public async down() {
    this.schema.dropTable(this.tableName)
  }
}
