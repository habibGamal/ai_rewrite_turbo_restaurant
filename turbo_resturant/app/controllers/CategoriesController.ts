import Category from '#models/Category'
import { CategoryRender } from '#render/category_render'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'

const categorySchema = vine.compile(
  vine.object({
    name: vine.string(),
  })
)

export default class CategoriesController {
  public async index({ inertia }: HttpContext) {
    const data = await new CategoryRender().render()
    return inertia.render('RenderModel', data)
  }

  public async store({ response, request, message }: HttpContext) {
    const data = await request.validateUsing(categorySchema)
    await Category.create(data)
    message.success('تم اضافة الفئة بنجاح')
    return response.redirect().back()
  }

  public async update({ response, request, params, message }: HttpContext) {
    const data = await request.validateUsing(categorySchema)
    const category = await Category.findOrFail(params.id)
    category.merge(data)
    await category.save()
    message.success('تم تعديل الفئة بنجاح')
    return response.redirect().back()
  }

  public async destroy({ response, params, message }: HttpContext) {
    const category = await Category.findOrFail(params.id)
    await category.loadCount('products')
    if (category.$extras.products_count) {
      message.error('لا يمكن حذف هذه الفئة لوجود منتجات مرتبطة بها')
      return response.redirect().back()
    }
    await category.delete()
    message.success('تم حذف الفئة بنجاح')
    return response.redirect().back()
  }
}
