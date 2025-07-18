import { ProductType, ProductUnit } from '#enums/ProductEnums'
import vineExists from '#helpers/vineExists'
import Product from '#models/Product'
import ManifacturedProductRender from '#render/Product/ManifacturedProductRender'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'

const productSchema = vine.compile(
  vine.object({
    name: vine.string(),
    price: vine.number(),
    unit: vine.enum(Object.values(ProductUnit)),
    categoryId: vine.number().exists(vineExists('categories')),
    printers: vine.array(vine.number().exists(vineExists('printers'))).optional(),
    legacy: vine.boolean().optional(),
  })
)

export default class ManifacturedProductsController {
  public async index({ inertia }: HttpContext) {
    const render = new ManifacturedProductRender()
    const data = await render.render()
    return inertia.render('RenderModel', data)
  }

  public async store({ response, request, message }: HttpContext) {
    const data = await request.validateUsing(productSchema)
    const product = await Product.create({
      ...data,
      cost: 0,
      type: ProductType.Manifactured,
    })
    if (data.printers) await product.related('printers').sync(data.printers)
    message.success('تم اضافة المنتج بنجاح')
    return response.redirect().back()
  }

  public async edit({ inertia, params }: HttpContext) {
    const product = await Product.query()
      .where('type', ProductType.Manifactured)
      .preload('components')
      .where('id', params.id)
      .firstOrFail()
    const products = await Product.query().where('legacy', false)
    return inertia.render('Recipe', { product, products })
  }

  public async update({ response, request, params, message }: HttpContext) {
    const data = await request.validateUsing(productSchema)
    const product = await Product.findOrFail(params.id)
    product.merge({ ...data })
    if (data.printers) await product.related('printers').sync(data.printers)
    await product.save()
    message.success('تم تعديل المنتج بنجاح')
    return response.redirect().back()
  }

  public async updateComponents({ response, request, params, message }: HttpContext) {
    const schema = vine.compile(
      vine.object({
        components: vine.array(
          vine.object({
            productId: vine.number(),
            quantity: vine.number(),
          })
        ),
      })
    )
    const data = await request.validateUsing(schema)
    const product = await Product.findOrFail(params.id)
    // calculate costs from components
    const components = await Product.query()
      .select(['id', 'cost'])
      .whereIn(
        'id',
        data.components!.map((c) => c.productId)
      )
    let cost = 0
    components.forEach((component) => {
      const quantity = data.components!.find((c) => c.productId === component.id)!.quantity
      cost += component.cost * quantity
    })
    // sync components
    const componentsSyncData: Record<number, { quantity: number }> = {}
    data.components.forEach((c) => {
      componentsSyncData[c.productId] = { quantity: c.quantity }
    })
    product.related('components').sync(componentsSyncData)
    product.cost = cost
    await product.save()
    message.success('تم تعديل معياري المنتج بنجاح')
    return response.redirect().back()
  }

  public async destroy({ response, params, message }: HttpContext) {
    const product = await Product.findOrFail(params.id)
    // check if product has any orders
    await product.loadCount('orderItems')
    if (product.$extras.orderItems_count) {
      message.error('هذا المنتج مستخدم في اوردرات لا يمكن مسحه')
      return response.redirect().back()
    }
    await product.related('components').detach()
    await product.delete()
    return response.redirect().back()
  }
}
