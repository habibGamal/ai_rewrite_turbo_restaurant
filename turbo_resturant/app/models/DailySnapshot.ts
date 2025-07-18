import { DateTime } from 'luxon'
import { BaseModel, column } from '@adonisjs/lucid/orm'

type DailySnapshotData = {
  product_id: number
  start_quantity: number
  end_quantity: number
  cost: number
}[]

export default class DailySnapshot extends BaseModel {
  @column({ isPrimary: true })
  declare id: number

  @column.date({ autoCreate: true })
  declare day: DateTime

  @column({
    prepare: (value) => JSON.stringify(value),
    consume: (value) => JSON.parse(value) as DailySnapshotData,
  })
  declare data: DailySnapshotData

  @column()
  declare closed: boolean

  @column.dateTime({ autoCreate: true })
  declare createdAt: DateTime

  @column.dateTime({ autoCreate: true, autoUpdate: true })
  declare updatedAt: DateTime
}
