import InventoryItem from '#models/InventoryItem'
import Product from '#models/Product'
import Stocktaking from '#models/Stocktaking'
import StocktakingRender from '#render/StocktakingRender'
import DailySnapshotService from '#services/DailySnapshotService'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'
import ErrorMsgException from '#exceptions/error_msg_exception'
export default class StocktakingsController {
  public async index({ inertia }: HttpContext) {
    return inertia.render('RenderModel', await new StocktakingRender().render())
  }

  public async show({ inertia, params }: HttpContext) {
    const stocktaking = await Stocktaking.query()
      .where('id', params.id)
      .preload('items', (query) => {
        query.preload('product')
      })
      .firstOrFail()
    return inertia.render('Stocktaking/Show', {
      stocktaking,
    })
  }

  public async create({ response, inertia }: HttpContext) {
    const lastStocktaking = await Stocktaking.query().orderBy('id', 'desc').first()
    if (lastStocktaking && !lastStocktaking?.closed)
      return response.redirect().toRoute('stocktaking.edit', { id: lastStocktaking!.id })
    const stocktakingNumber = lastStocktaking ? lastStocktaking.id + 1 : 1
    const inventoryItems = await InventoryItem.query().preload('product')
    const snapshot = await DailySnapshotService.getTodaySnapshot()

    return inertia.render('Stocktaking/Form', {
      stocktakingNumber,
      inventoryItems,
      snapshot,
    })
  }

  public async store({ request, response, auth, message }: HttpContext) {
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
      .select(['id', 'cost'])
      .whereIn(
        'id',
        items.map((item) => item.productId)
      )
    // get inventory items
    const inventoryItems = await InventoryItem.query()
      .select(['id', 'quantity', 'product_id'])
      .whereIn(
        'product_id',
        items.map((item) => item.productId)
      )
    // create stocktaking items data
    const stocktakingItems = items.map((item) => {
      const product = products.find((product) => product.id === item.productId)
      const inventoryItem = inventoryItems.find((invItem) => invItem.productId === item.productId)
      return {
        productId: item.productId,
        quantity: item.quantity - inventoryItem!.quantity,
        cost: product!.cost,
        total: (item.quantity - inventoryItem!.quantity) * product!.cost,
      }
    })
    // calculate total
    const total = stocktakingItems.reduce((acc, item) => acc + item.total, 0)
    // create stocktaking
    const stocktaking = await auth.user!.related('stocktaking').create({
      balance: total,
    })
    // create stocktaking items
    await stocktaking.related('items').createMany(stocktakingItems)

    message.success('تم إضافة جرد جديد')
    return response.redirect().back()
  }

  public async edit({ response, inertia, params }: HttpContext) {
    const stocktaking = await Stocktaking.query()
      .where('id', params.id)
      .preload('items', (query) => {
        query.preload('product')
      })
      .firstOrFail()
    if (stocktaking.closed)
      return response.redirect().toRoute('stocktaking.show', { id: stocktaking.id })
    const inventoryItems = await InventoryItem.query().preload('product')
    const snapshot = await DailySnapshotService.getTodaySnapshot()
    return inertia.render('Stocktaking/Form', {
      stocktaking,
      stocktakingNumber: stocktaking.id,
      inventoryItems,
      snapshot,
    })
  }

  public async update({ request, response, message, params }: HttpContext) {
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
      .select(['id', 'cost'])
      .whereIn(
        'id',
        items.map((item) => item.productId)
      )
    // get stocktaking
    const stocktaking = await Stocktaking.query()
      .where('id', params.id)
      .preload('items')
      .firstOrFail()
    if (stocktaking.closed) throw new ErrorMsgException('لا يمكن تعديل الجرد بعد إغلاقه')
    // get inventory items
    const inventoryItems = await InventoryItem.query()
      .select(['id', 'quantity', 'product_id'])
      .whereIn(
        'product_id',
        items.map((item) => item.productId)
      )
    // create stocktaking items data
    const stocktakingItems = items.map((item) => {
      const inventoryItem = inventoryItems.find((invItem) => invItem.productId === item.productId)
      const product = products.find((product) => product.id === item.productId)
      return {
        productId: item.productId,
        quantity: item.quantity - inventoryItem!.quantity,
        cost: product!.cost,
        total: (item.quantity - inventoryItem!.quantity) * product!.cost,
      }
    })
    // calculate total
    const total = stocktakingItems.reduce((acc, item) => acc + item.total, 0)
    // update stocktaking
    stocktaking.balance = total
    await stocktaking.save()
    // update stocktaking items
    await stocktaking.related('items').query().delete()
    await stocktaking.related('items').createMany(stocktakingItems)
    if (close === true)
      return response.redirect().toRoute('stocktaking.close', { id: stocktaking.id })
    message.success('تم حفظ الجرد')
    return response.redirect().back()
  }

  public async close({ response, message, params }: HttpContext) {
    const stocktaking = await Stocktaking.query()
      .where('id', params.id)
      .preload('items')
      .firstOrFail()
    stocktaking.closed = true
    await stocktaking.save()
    const inventoryItems = await InventoryItem.query()
      .select(['id', 'quantity', 'product_id'])
      .whereIn(
        'product_id',
        stocktaking.items.map((item) => item.productId)
      )
    // update inventory
    inventoryItems.forEach(async (invItem) => {
      const item = stocktaking.items.find((item) => item.productId === invItem.productId)
      invItem.quantity = invItem.quantity + item!.quantity
      await invItem.save()
    })
    message.success('تم إغلاق الجرد')
    return response.redirect().toRoute('stocktaking.create')
  }
}
