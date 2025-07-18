import { BaseModel, belongsTo, column } from '@adonisjs/lucid/orm';
import type { BelongsTo } from "@adonisjs/lucid/types/relations";
import Product from './Product.js';
import Waste from './Waste.js';

export default class WastedItem extends BaseModel {
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare wasteId: number

  @column()
  declare productId: number

  @column()
  declare quantity: number

  @column()
  declare cost: number

  @column()
  declare total: number

  @belongsTo(() => Waste)
  declare waste: BelongsTo<typeof Waste>

  @belongsTo(() => Product)
  declare product: BelongsTo<typeof Product>
}
