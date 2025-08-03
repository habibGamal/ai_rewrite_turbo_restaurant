import { ProductType, ProductUnit } from '#enums/ProductEnums'
import vineExists from '#helpers/vineExists'
import Product from '#models/Product'
import RawProductRender from '#render/Product/RawProductRender'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'

const productSchema = vine.compile(
  vine.object({
    name: vine.string(),
    cost: vine.number(),
    unit: vine.enum(Object.values(ProductUnit)),
    categoryId: vine.number().exists(vineExists('categories')),
  })
)

export default class RawProductsController {
  public async index({ inertia }: HttpContext) {
    const render = await new RawProductRender().render()
    return inertia.render('RenderModel', render)
  }

  public async store({ response, request, message }: HttpContext) {
    const data = await request.validateUsing(productSchema)
    const price = data.cost
    await Product.create({ ...data, price, type: ProductType.RawMaterial })
    message.success('تم اضافة المنتج بنجاح')
    return response.redirect().back()
  }

  public async update({ response, request, params, message }: HttpContext) {
    const data = await request.validateUsing(productSchema)
    const product = await Product.findOrFail(params.id)
    product.merge(data)
    product.price = data.cost
    await product.save()
    message.success('تم تعديل المنتج بنجاح')
    return response.redirect().back()
  }

  public async destroy({ response, params, message }: HttpContext) {
    const product = await Product.findOrFail(params.id)
    // check if product is used as a component
    await product.loadCount('componentOf')
    if (product.$extras.componentOf_count) {
      message.error('هذا المنتج مستخدم كمكون لا يمكن مسحه')
      return response.redirect().back()
    }
    await product.delete()
    return response.redirect().back()
  }
}
