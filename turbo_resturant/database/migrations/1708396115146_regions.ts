import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'regions'

  public async up () {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table.string('name').notNullable()
      table.float('delivery_cost').notNullable()
    })
  }

  public async down () {
    this.schema.dropTable(this.tableName)
  }
}
