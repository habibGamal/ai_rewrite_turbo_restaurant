import ExpenceType from '#models/ExpenceType'
import { ExpenseTypeRender } from '#render/ExpenseTypeRender'
import { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'

const expenseTypeSchema = vine.compile(
  vine.object({
    name: vine.string(),
  })
)

export default class ExpenseTypesController {
  public async index({ inertia }: HttpContext) {
    return inertia.render('RenderModel', await new ExpenseTypeRender().render())
  }

  public async store({ response, request, message }: HttpContext) {
    const data = await request.validateUsing(expenseTypeSchema)
    await ExpenceType.create(data)
    message.success('تم اضافة البند بنجاح')
    return response.redirect().back()
  }

  public async update({ response, request, message }: HttpContext) {
    const data = await request.validateUsing(expenseTypeSchema)
    const expenseType = await ExpenceType.findOrFail(request.param('id'))
    expenseType.name = data.name
    await expenseType.save()
    message.success('تم تعديل البند بنجاح')
    return response.redirect().back()
  }

  public async destroy({ response, message, request }: HttpContext) {
    const expenseType = await ExpenceType.findOrFail(request.param('id'))
    const [{ total }] = await expenseType
      .related('expenses')
      .query()
      .pojo<{ total: number }>()
      .count('* as total')
    if (total > 0) await expenseType.delete()
    else message.success('تم حذف البند بنجاح')
    return response.redirect().back()
  }
}
