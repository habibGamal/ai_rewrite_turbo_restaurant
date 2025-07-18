import type { HttpContext } from '@adonisjs/core/http'
import { UserRole } from '#enums/UserEnums'

export default class NotViewer {
  public async handle({auth,response}: HttpContext, next: () => Promise<void>) {
    if(auth.user?.role === UserRole.Viewer) {
      return response.redirect().toRoute('reports.current-shift-report')
    }

    await next()
  }
}
