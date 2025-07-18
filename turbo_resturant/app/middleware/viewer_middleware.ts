import type { HttpContext } from '@adonisjs/core/http'
import { UserRole } from '#enums/UserEnums'

export default class Viewer {
  public async handle({response,auth}: HttpContext, next: () => Promise<void>) {
    if(auth.user?.role !== UserRole.Viewer && auth.user?.role !== UserRole.Admin ) {
      return response.redirect().toRoute('cashier-screen')
    }
    await next()
  }
}
