import { BaseSchema } from "@adonisjs/lucid/schema";

export default class extends BaseSchema {
  protected tableName = 'settings'

  public async up () {
    this.schema.createTable(this.tableName, (table) => {
      table.increments('id')
      table.string('key').notNullable()
      table.text('value').notNullable()
    })
  }

  public async down () {
    this.schema.dropTable(this.tableName)
  }
}
