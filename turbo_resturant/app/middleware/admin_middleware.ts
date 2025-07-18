import type { HttpContext } from '@adonisjs/core/http'
import { UserRole } from '#enums/UserEnums'

export default class Admin {
  public async handle({ auth, response }: HttpContext, next: () => Promise<void>) {
    if (auth.user?.role !== UserRole.Admin) {
      return response.redirect().toRoute('continue-shift')
    }

    await next()
  }
}
