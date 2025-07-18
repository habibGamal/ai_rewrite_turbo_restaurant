import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'order_items'

  public async up() {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table.integer('order_id').unsigned().references('id').inTable('orders').onDelete('CASCADE')
      table
        .integer('product_id')
        .unsigned()
        .references('id')
        .inTable('products')
        .onDelete('CASCADE')
      table.integer('quantity').notNullable()
      table.float('price').notNullable()
      table.float('cost').notNullable()
      table.float('total').notNullable()
      table.string('notes').nullable()
    })
  }

  public async down() {
    this.schema.dropTable(this.tableName)
  }
}
