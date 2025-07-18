import { ProductUnit, ProductType } from '#enums/ProductEnums'
import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'products'

  public async up() {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table.string('name').notNullable()
      table.float('price').notNullable()
      table.float('cost').notNullable()
      table.enum('type', Object.values(ProductType)).defaultTo(ProductType.Manifactured)
      table.enum('unit', Object.values(ProductUnit)).defaultTo(ProductUnit.Packet)
      table.integer('printer_id').nullable().unsigned().references('id').inTable('printers').onDelete('set null');
      table.integer('category_id').nullable().unsigned().references('id').inTable('categories').onDelete('set null');
      table.timestamps(true, true)
    })
  }

  public async down() {
    this.schema.dropTable(this.tableName)
  }
}
