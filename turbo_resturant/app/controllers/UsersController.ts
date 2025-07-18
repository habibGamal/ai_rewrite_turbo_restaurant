import type { HttpContext } from '@adonisjs/core/http'
import { UserRole } from '#enums/UserEnums'
import User from '#models/User'
import vine from '@vinejs/vine'
import { UserRender } from '#render/UserRender'

const userSchema = vine.compile(
  vine.object({
    email: vine.string(),
    role: vine.enum(UserRole).optional(),
    password: vine.string(),
  })
)

export default class UsersController {
  public async index({ inertia }: HttpContext) {
    return inertia.render('RenderModel', await new UserRender().render())
  }

  public async store({ response, request, message }: HttpContext) {
    const data = await request.validateUsing(userSchema)
    if (data.role === UserRole.Watcher) {
      const watcherExist = await User.query().where('role', UserRole.Watcher).first()
      if (watcherExist) {
        message.error('لا يمكن اضافة مراقب')
        return response.redirect().back()
      }
    }
    await User.create(data)
    message.success('تم اضافة المستخدم بنجاح')
    return response.redirect().back()
  }

  public async update({ response, request, params, message }: HttpContext) {
    const data = await request.validateUsing(userSchema)
    const user = await User.findOrFail(params.id)
    if (data.role === UserRole.Watcher && user.role !== UserRole.Watcher) {
      const watcherExist = await User.query().where('role', UserRole.Watcher).first()
      if (watcherExist) {
        message.error('لا يمكن تحويل المستخدم الى مراقب')
        return response.redirect().back()
      }
    }
    user.merge(data)
    await user.save()
    message.success('تم اضافة المستخدم بنجاح')
    return response.redirect().back()
  }

  public async destroy({ response, params, message }: HttpContext) {
    const user = await User.findOrFail(params.id)
    // ensure that user doesn't have any shifts or invoices or stocktaking
    await user.loadCount('shifts')
    await user.loadCount('purchaseInvoices')
    await user.loadCount('returnPurchaseInvoices')
    await user.loadCount('stocktaking')
    const userHasActions =
      user.$extras.shifts_count > 0 ||
      user.$extras.purchase_invoices_count > 0 ||
      user.$extras.return_purchase_invoices_count > 0 ||
      user.$extras.stocktaking_count > 0

    if (userHasActions) {
      message.error('لا يمكن حذف المستخدم لانه لديه عمليات')
      return response.redirect().back()
    }
    await user.delete()
    message.success('تم حذف المستخدم بنجاح')
    return response.redirect().back()
  }

  public async loginScreen({ inertia }: HttpContext) {
    return inertia.render('Auth/Login')
  }

  public async login({ auth, request, response }: HttpContext) {
    const email = request.input('email')
    const password = request.input('password')
    const user = await User.verifyCredentials(email, password)
    await auth.use('web').login(user)
    if (user.role === UserRole.Watcher) return response.redirect().toRoute('reports.shifts-logs')
    return response.redirect().toRoute('categories.index')
  }

  public async logout({ auth, response }: HttpContext) {
    await auth.use('web').logout()
    return response.redirect().toRoute('login.screen')
  }
}
