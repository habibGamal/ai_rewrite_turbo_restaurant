import { PaymentStatus } from '#enums/InvoicePaymentEnums'
import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'return_purchase_invoices'

  public async up() {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table.float('total').notNullable()
      table.float('received').notNullable()
      table.enum('status', Object.values(PaymentStatus)).defaultTo(PaymentStatus.FullPaid)
      table.integer('supplier_id').unsigned().nullable().references('id').inTable('suppliers')
      table.integer('user_id').unsigned().references('id').inTable('users')
      table.boolean('closed').defaultTo(false)
      table.timestamps(true, true)
    })
  }

  public async down() {
    this.schema.dropTable(this.tableName)
  }
}
