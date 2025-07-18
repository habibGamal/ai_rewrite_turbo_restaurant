import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'product_components'

  public async up () {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table.integer('product_id').unsigned().references('products.id')
      table.integer('component_id').unsigned().references('products.id')
      table.double('quantity').unsigned()
    })
  }

  public async down () {
    this.schema.dropTable(this.tableName)
  }
}
