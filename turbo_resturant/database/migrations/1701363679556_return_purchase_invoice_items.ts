import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'return_purchase_invoice_items'

  public async up() {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table
        .integer('return_purchase_invoice_id')
        .unsigned()
        .references('id')
        .inTable('return_purchase_invoices')
        .onDelete('CASCADE')
      table
        .integer('product_id')
        .unsigned()
        .references('id')
        .inTable('products')
        .onDelete('CASCADE')
      table.float('quantity').notNullable()
      table.float('price').notNullable()
      table.float('total').notNullable()
    })
  }

  public async down() {
    this.schema.dropTable(this.tableName)
  }
}
