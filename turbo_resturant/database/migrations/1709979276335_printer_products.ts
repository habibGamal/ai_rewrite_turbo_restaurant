import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'printer_product'

  public async up () {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table.integer('product_id').unsigned().references('id').inTable('products').onDelete('CASCADE')
      table.integer('printer_id').unsigned().references('id').inTable('printers').onDelete('CASCADE')
      table.unique(['product_id', 'printer_id']);
    })
  }

  public async down () {
    this.schema.dropTable(this.tableName)
  }
}
