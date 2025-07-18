import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'wasted_items'

  public async up() {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table
        .integer('waste_id')
        .unsigned()
        .references('id')
        .inTable('wastes')
        .onDelete('CASCADE')
      table
        .integer('product_id')
        .unsigned()
        .references('id')
        .inTable('products')
        .onDelete('CASCADE')
      table.float('quantity').notNullable()
      table.float('cost').notNullable()
      table.float('total').notNullable()
    })
  }

  public async down() {
    this.schema.dropTable(this.tableName)
  }
}
