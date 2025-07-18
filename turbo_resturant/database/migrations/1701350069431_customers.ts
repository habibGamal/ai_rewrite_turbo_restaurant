import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'customers'

  public async up() {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table.string('name')
      table.string('phone').notNullable()
      table.text('address')
      table.boolean('has_whatsapp').defaultTo(false)
      table.string('region').notNullable()
      table.integer('delivery_cost').defaultTo(0)
      table.timestamps(true, true)
    })
  }

  public async down() {
    this.schema.dropTable(this.tableName)
  }
}
