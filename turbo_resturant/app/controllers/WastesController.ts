import { ProductType } from '#enums/ProductEnums'
import InventoryItem from '#models/InventoryItem'
import Product from '#models/Product'
import Waste from '#models/Waste'
import WasteRender from '#render/WasteRender'
import DailySnapshotService from '#services/DailySnapshotService'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'
export default class WastesController {
  public async index({ inertia }: HttpContext) {
    return inertia.render('RenderModel', await new WasteRender().render())
  }

  public async show({ inertia, params }: HttpContext) {
    const waste = await Waste.query()
      .preload('items', (query) => {
        query.preload('product')
      })
      .where('id', params.id)
      .firstOrFail()
    return inertia.render('Wastes/Show', { waste })
  }

  public async create({ response, inertia }: HttpContext) {
    const lastWaste = await Waste.query().orderBy('id', 'desc').first()
    if (lastWaste && !lastWaste?.closed)
      return response.redirect().toRoute('wastes.edit', { id: lastWaste!.id })
    const wasteNumber = lastWaste ? lastWaste.id + 1 : 1
    const products = await Product.query().where('type', '!=', ProductType.Manifactured)
    return inertia.render('Wastes/Form', {
      wasteNumber,
      products,
    })
  }

  public async store({ request, response, message, auth }: HttpContext) {
    await DailySnapshotService.exitIfDayClosed()
    // validation
    const { items } = await request.validateUsing(
      vine.compile(
        vine.object({
          items: vine.array(
            vine.object({
              productId: vine.number(),
              quantity: vine.number(),
            })
          ),
        })
      )
    )
    // get products
    const products = await Product.query()
      .select('id', 'cost')
      .whereIn(
        'id',
        items.map((item) => item.productId)
      )
    // waste items data
    const wasteItems = items.map((item) => {
      const product = products.find((prod) => prod.id === item.productId)
      return {
        productId: item.productId,
        quantity: item.quantity,
        cost: product!.cost,
        total: item.quantity * product!.cost,
      }
    })
    // create waste
    const waste = await auth.user!.related('wastes').create({
      total: wasteItems.reduce((acc, item) => acc + item.total, 0),
    })
    // create waste items
    await waste.related('items').createMany(wasteItems)

    message.success('تم تسجيل الهالك بنجاح')
    return response.redirect().back()
  }

  public async edit({ response, inertia, params }: HttpContext) {
    const waste = await Waste.query()
      .preload('items', (query) => {
        query.preload('product')
      })
      .where('id', params.id)
      .firstOrFail()

    if (waste.closed) return response.redirect().toRoute('wastes.show', { id: waste.id })
    const products = await Product.query().where('type', '!=', ProductType.Manifactured)
    return inertia.render('Wastes/Form', { waste, products, wasteNumber: waste.id })
  }

  public async update({ request, response, message, params }: HttpContext) {
    const waste = await Waste.findOrFail(params.id)
    if (waste.closed) return response.redirect().toRoute('wastes.show', { id: waste.id })
    // validation
    const { items, close } = await request.validateUsing(
      vine.compile(
        vine.object({
          close: vine.boolean().optional(),
          items: vine.array(
            vine.object({
              productId: vine.number(),
              quantity: vine.number(),
            })
          ),
        })
      )
    )
    // get products
    const products = await Product.query()
      .select('id', 'cost')
      .whereIn(
        'id',
        items.map((item) => item.productId)
      )
    // waste items data
    const wasteItems = items.map((item) => {
      const product = products.find((prod) => prod.id === item.productId)
      return {
        productId: item.productId,
        quantity: item.quantity,
        cost: product!.cost,
        total: item.quantity * product!.cost,
      }
    })
    // update waste items
    await waste.related('items').query().delete()
    await waste.related('items').createMany(wasteItems)
    // update waste
    waste.total = wasteItems.reduce((acc, item) => acc + item.total, 0)
    await waste.save()
    if (close) return response.redirect().toRoute('wastes.close', { id: waste.id })
    message.success('تم تعديل الهالك بنجاح')
    return response.redirect().back()
  }

  public async close({ response, message, params }: HttpContext) {
    const waste = await Waste.query().where('id', params.id).preload('items').firstOrFail()
    waste.closed = true
    await waste.save()
    // update inventory
    const inventoryItems = await InventoryItem.query().whereIn(
      'product_id',
      waste.items.map((item) => item.productId)
    )
    inventoryItems.forEach(async (invItem) => {
      const item = waste.items.find((item) => item.productId === invItem.productId)
      invItem.quantity -= item!.quantity
      await invItem.save()
    })
    message.success('تم إغلاق الهالك بنجاح')
    return response.redirect().toRoute('wastes.create')
  }
}
