import { BaseSchema } from '@adonisjs/lucid/schema'

export default class extends BaseSchema {
  protected tableName = 'orders'

  async up() {
    this.schema.alterTable(this.tableName, (table) => {
      table.string('status').notNullable().alter()
      table.string('type').notNullable().alter()
      table.string('order_number').defaultTo('0').alter()
      table.float('web_pos_diff').defaultTo(0)
    })
  }

  async down() {
    this.schema.alterTable(this.tableName, (table) => {
      table.string('status').alter()
      table.string('type').alter()
      table.string('order_number').alter()
      table.dropColumn('web_pos_diff')
    })
  }
}
