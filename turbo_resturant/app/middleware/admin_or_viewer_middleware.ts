import { UserRole } from '#enums/UserEnums'
import type { HttpContext } from '@adonisjs/core/http'
import type { NextFn } from '@adonisjs/core/types/http'

export default class AdminOrViewerMiddleware {
  async handle(ctx: HttpContext, next: NextFn) {
    /**
     * Middleware logic goes here (before the next call)
     */

    // Check if the user is an admin or a viewer
    if (![UserRole.Admin, UserRole.Viewer].includes(ctx.auth.user!.role)) {
      return ctx.response.redirect().back()
    }
    /**
     * Call next method in the pipeline and return its output
     */
    const output = await next()
    return output
  }
}
