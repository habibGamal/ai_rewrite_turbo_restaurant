import vineExists from '#helpers/vineExists'
import Expense from '#models/Expense'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'
const schema = vine.compile(
  vine.object({
    amount: vine.number().range([0, 10_000_000]),
    expenseTypeId: vine.number().exists(vineExists('expense_types')),
    description: vine.string(),
  })
)
export default class ExpensesController {
  public async store({ request, response, session, message }: HttpContext) {
    const { amount, expenseTypeId: expense_type_id, description } = await request.validateUsing(schema)
    await Expense.create({
      amount,
      expenseTypeId: expense_type_id,
      description,
      shiftId: session.get('shiftId'),
    })
    message.success('تم اضافة المصروف بنجاح')
    return response.redirect().back()
  }

  public async update({ request, response, message, params }: HttpContext) {
    const { amount, expenseTypeId: expense_type_id, description } = await request.validateUsing(schema)
    const expense = await Expense.findOrFail(params.id)
    expense.amount = amount
    expense.expenseTypeId = expense_type_id
    expense.description = description
    await expense.save()
    message.success('تم تعديل المصروف بنجاح')
    return response.redirect().back()
  }

  public async destroy({ response, message, params }: HttpContext) {
    const expense = await Expense.findOrFail(params.id)
    await expense.delete()
    message.success('تم حذف المصروف بنجاح')
    return response.redirect().back()
  }
}
