import { ProductType, ProductUnit } from '#enums/ProductEnums'
import Product from '#models/Product'
import type { HttpContext } from '@adonisjs/core/http'
import vineExists from '#helpers/vineExists'
import ConsumableProductRender from '#render/Product/ConsumableProductRender'
import vine from '@vinejs/vine'

const productSchema = vine.compile(
  vine.object({
    name: vine.string(),
    price: vine.number(),
    cost: vine.number(),
    unit: vine.enum(Object.values(ProductUnit)),
    categoryId: vine.number().exists(vineExists('categories')),
    printers: vine.array(vine.number().exists(vineExists('printers')).optional()),
  })
)

export default class ConsumableProductsController {
  public async index({ inertia }: HttpContext) {
    return inertia.render('RenderModel', await new ConsumableProductRender().render())
  }

  public async store({ response, request, message }: HttpContext) {
    const data = await request.validateUsing(productSchema)
    const product = await Product.create({ ...data, type: ProductType.Consumable })
    if (data.printers) await product.related('printers').sync(data.printers)
    message.success('تم اضافة المنتج بنجاح')
    return response.redirect().back()
  }

  public async update({ response, request, params, message }: HttpContext) {
    const data = await request.validateUsing(productSchema)
    const product = await Product.findOrFail(params.id)
    product.merge(data)
    if (data.printers) await product.related('printers').sync(data.printers)
    await product.save()
    message.success('تم تعديل المنتج بنجاح')
    return response.redirect().back()
  }

  public async destroy({ response, params, message }: HttpContext) {
    const product = await Product.findOrFail(params.id)
    // check if product is used as a component
    const componentOf = await product.related('componentOf').query().count('* as count').pojo<{
      count: number
    }>()
    if (componentOf[0].count) {
      message.error('هذا المنتج مستخدم كمكون لا يمكن مسحه')
      return response.redirect().back()
    }
    // check if product is used in any order
    const orderProducts = await product.related('orderItems').query().count('* as count').pojo<{
      count: number
    }>()
    if (orderProducts[0].count) {
      message.error('هذا المنتج مستخدم في طلبات لا يمكن مسحه')
      return response.redirect().back()
    }
    await product.delete()
    return response.redirect().back()
  }
}
