import { ProductType, ProductUnit } from '#enums/ProductEnums'
import { NodeType, SettingKeys } from '#enums/SettingsEnums'
import ErrorMsgException from '#exceptions/error_msg_exception'
import {
  BaseModel,
  afterCreate,
  afterUpdate,
  beforeCreate,
  belongsTo,
  column,
  computed,
  hasMany,
  hasOne,
  manyToMany,
} from '@adonisjs/lucid/orm'
import type { BelongsTo, HasMany, HasOne, ManyToMany } from '@adonisjs/lucid/types/relations'
import { DateTime } from 'luxon'
import Category from './Category.js'
import InventoryItem from './InventoryItem.js'
import OrderItem from './OrderItem.js'
import Printer from './Printer.js'
import Setting from './Setting.js'
import WastedItem from './WastedItem.js'

export default class Product extends BaseModel {
  serializeExtras = true
  @column({ isPrimary: true })
  declare id: number

  @column()
  declare categoryId: number

  @column()
  declare name: string

  @column()
  declare price: number

  @column()
  declare cost: number

  @column()
  declare type: ProductType

  @column()
  declare unit: ProductUnit

  @column()
  declare printerId: number

  @column()
  declare productRef: string

  @column()
  declare legacy: boolean

  @column.dateTime({ autoCreate: true })
  declare createdAt: DateTime

  @column.dateTime({ autoCreate: true, autoUpdate: true })
  declare updatedAt: DateTime

  @belongsTo(() => Printer)
  declare printer: BelongsTo<typeof Printer>

  @hasOne(() => InventoryItem)
  declare inventoryItem: HasOne<typeof InventoryItem>

  @manyToMany(() => Printer)
  declare printers: ManyToMany<typeof Printer>

  @afterCreate()
  public static async createInvetoryItem(product: Product) {
    if (product.type !== ProductType.Manifactured)
      await product.related('inventoryItem').create({
        quantity: 0,
      })
  }

  @beforeCreate()
  public static async checkNodeType(product: Product) {
    const nodeType = await Setting.query().where({ key: SettingKeys.NodeType }).first()
    const isImportedProduct = product.productRef !== undefined
    if (nodeType?.value === NodeType.Slave && !isImportedProduct)
      throw new ErrorMsgException(
        'غير مسموح للفرع اضافة منتجات يمكنك اضافة منتجات من الفرع الرئيسي'
      )
  }

  @afterCreate()
  public static async createProductRef(product: Product) {
    const isImportedProduct = product.productRef !== undefined
    if (isImportedProduct) return
    let productRef = ''
    switch (product.type) {
      case ProductType.RawMaterial:
        productRef = 'R'
        break
      case ProductType.Manifactured:
        productRef = 'M'
        break
      case ProductType.Consumable:
        productRef = 'C'
        break
    }
    productRef += product.id
    product.productRef = productRef
    await product.save()
  }

  @afterUpdate()
  public static async updateCosts(product: Product) {
    await product.load('componentOf')
    // console.log('recalculate costs')
    for (const component of product.componentOf) {
      // console.log(product.name, ' is component of ', component.name)
      await Product.recalculateCost(component)
    }
  }

  @belongsTo(() => Category)
  declare category: BelongsTo<typeof Category>

  @hasMany(() => OrderItem)
  declare orderItems: HasMany<typeof OrderItem>

  @hasMany(() => WastedItem)
  declare wastedItems: HasMany<typeof WastedItem>

  @manyToMany(() => Product, {
    pivotTable: 'product_components',
    pivotForeignKey: 'product_id',
    pivotRelatedForeignKey: 'component_id',
    pivotColumns: ['quantity'],
  })
  declare components: ManyToMany<typeof Product>

  @manyToMany(() => Product, {
    pivotTable: 'product_components',
    pivotForeignKey: 'component_id',
    pivotRelatedForeignKey: 'product_id',
    pivotColumns: ['quantity'],
  })
  declare componentOf: ManyToMany<typeof Product>

  @computed()
  public get ingredients() {
    return this.components?.map((component) => {
      return {
        productId: {
          value: component.id,
          label: component.name,
        },
        quantity: component.$extras.pivot_quantity,
        cost: component.cost,
        type: component.type,
      }
    })
  }

  public static async fullRecipe(product: Product) {
    await product.load('components')
    const recipe: { product_id: number; quantity: number }[] = []
    for (const component of product.components) {
      if (component.type === ProductType.Manifactured) {
        recipe.push(...(await this.fullRecipe(component)))
        continue
      }
      recipe.push({
        product_id: component.id,
        quantity: component.$extras.pivot_quantity,
      })
    }
    return recipe
  }

  @computed()
  public get categorySelect() {
    return {
      value: this.category?.id,
      label: this.category?.name,
    }
  }

  @computed()
  public get printerSelect() {
    return {
      value: this.printer?.id,
      label: this.printer?.name,
    }
  }

  @computed()
  public get salesTotal() {
    return this.$extras.salesTotal
  }

  @computed()
  public get salesProfit() {
    return this.$extras.salesProfit
  }

  @computed()
  public get salesQuantity() {
    return this.$extras.salesQuantity
  }

  @computed()
  public get componentsHash() {
    return this.components
      ?.map(
        (component) => [component.productRef, component.$extras.pivot_quantity] as [string, number]
      )
      .sort()
      .flat()
      .join('')
  }

  public static async updateInventoryLevel(product: Product, quantity: number) {
    if (product.type === ProductType.Manifactured) {
      const recipe = await this.fullRecipe(product)
      for (const item of recipe) {
        await InventoryItem.query()
          .where('product_id', item.product_id)
          .decrement('quantity', item.quantity * quantity)
      }
    } else {
      await InventoryItem.query().where('product_id', product.id).decrement('quantity', quantity)
    }
  }

  public static async recalculateCost(product: Product) {
    if (product.type === ProductType.Manifactured) {
      await product.load('components')
      let cost = 0
      product.components.forEach((component) => {
        cost += component.cost * component.$extras.pivot_quantity
      })
      // console.log(product.components)
      product.cost = cost
      await product.save()
    }
  }

  public async updateComponents(data: { components: { productId: number; quantity: number }[] }) {
    const products = await Product.query()
      .select(['id', 'cost'])
      .whereIn(
        'id',
        data.components!.map((c) => c.productId)
      )
    let cost = 0
    products.forEach((component) => {
      const quantity = data.components!.find((c) => c.productId === component.id)!.quantity
      cost += component.cost * quantity
    })
    // sync components
    const componentsSyncData: Record<number, { quantity: number }> = {}
    data.components.forEach((c) => {
      componentsSyncData[c.productId] = { quantity: c.quantity }
    })
    const product = this as Product
    product.related('components').sync(componentsSyncData)
    product.cost = cost
    await product.save()
  }
}
