import { PaymentMethod } from '#enums/PaymentEnums'
import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'payments'

  public async up() {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table.integer('shift_id').unsigned().references('id').inTable('shifts').onDelete('cascade')
      table.integer('order_id').unsigned().references('id').inTable('orders').onDelete('cascade')
      table.enum('method', Object.values(PaymentMethod)).defaultTo(PaymentMethod.Cash)

      table.float('paid').notNullable()

      table.timestamps(true, true)
    })
  }

  public async down() {
    this.schema.dropTable(this.tableName)
  }
}
