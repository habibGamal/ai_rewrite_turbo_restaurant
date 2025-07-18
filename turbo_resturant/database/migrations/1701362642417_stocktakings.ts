import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'stocktakings'

  public async up() {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table.integer('user_id').unsigned().references('id').inTable('users')
      table.float('balance')
      table.boolean('closed').defaultTo(false)
      table.timestamp('created_at')
    })
  }

  public async down() {
    this.schema.dropTable(this.tableName)
  }
}
