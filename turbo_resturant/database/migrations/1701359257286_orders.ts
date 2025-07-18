import { OrderStatus, OrderType } from '#enums/OrderEnums'
import { PaymentStatus } from '#enums/PaymentEnums'
import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'orders'

  public async up() {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table
        .integer('customer_id')
        .unsigned()
        .nullable()
        .references('id')
        .inTable('customers')
        .onDelete('set null')
      table
        .integer('driver_id')
        .unsigned()
        .nullable()
        .references('id')
        .inTable('drivers')
        .onDelete('set null')
      table.integer('shift_id').unsigned().references('id').inTable('shifts').onDelete('cascade')
      table.enum('status', Object.values(OrderStatus)).defaultTo(OrderStatus.Processing)
      table.enum('type', Object.values(OrderType))
      table.float('sub_total').defaultTo(0)
      table.float('tax').defaultTo(0)
      table.float('service').defaultTo(0)
      table.float('discount').defaultTo(0)
      table.float('total').defaultTo(0)
      table.float('profit').defaultTo(0)
      table.enum('payment_status', Object.values(PaymentStatus)).defaultTo(PaymentStatus.FullPaid)
      table.string('dine_table_number').nullable()
      table.text('kitchen_notes').nullable()
      table.text('order_notes').nullable()
      table.float('temp_discount_percent').defaultTo(0)
      table.integer('order_number').defaultTo(0)
      table.timestamps(true, true)
    })
  }

  public async down() {
    this.schema.dropTable(this.tableName)
  }
}
