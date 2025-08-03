import { BaseSchema } from '@adonisjs/lucid/schema'

export default class extends BaseSchema {
  protected tableName = 'products'

  async up() {
    this.schema.alterTable(this.tableName, (table) => {
      table.string('product_ref').nullable()
    })

    this.defer(async (db) => {
      await db.rawQuery(
        `update ${this.tableName} set product_ref = concat("R", id) where type = "raw_material"`
      )
      await db.rawQuery(
        `update ${this.tableName} set product_ref = concat("M", id) where type = "manifactured"`
      )
      await db.rawQuery(
        `update ${this.tableName} set product_ref = concat("C", id) where type = "consumable"`
      )
    })
  }

  async down() {
    this.schema.alterTable(this.tableName, (table) => {
      table.dropColumn('product_ref')
    })
  }
}
