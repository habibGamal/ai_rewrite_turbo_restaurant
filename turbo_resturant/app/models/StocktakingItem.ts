import Product from '#models/Product';
import Stocktaking from '#models/Stocktaking';
import { BaseModel, belongsTo, column } from '@adonisjs/lucid/orm';
import type { BelongsTo } from "@adonisjs/lucid/types/relations";

export default class StocktakingItem extends BaseModel {
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare stocktakingId: number

  @column()
  declare productId: number

  @column()
  declare quantity: number

  @column()
  declare cost: number

  @column()
  declare total: number

  @belongsTo(() => Stocktaking)
  declare stocktaking: BelongsTo<typeof Stocktaking>

  @belongsTo(() => Product)
  declare product: BelongsTo<typeof Product>
}
