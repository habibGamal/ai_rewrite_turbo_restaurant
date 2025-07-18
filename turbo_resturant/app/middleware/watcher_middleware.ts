import { UserRole } from '#enums/UserEnums'
import ErrorMsgException from '#exceptions/error_msg_exception'
import type { HttpContext } from '@adonisjs/core/http'
import type { NextFn } from '@adonisjs/core/types/http'

export default class WatcherMiddleware {
  async handle(ctx: HttpContext, next: NextFn) {
    /**
     * Middleware logic goes here (before the next call)
     */
    if(ctx.auth.user?.role !== UserRole.Watcher) {
      throw new ErrorMsgException('ليس لديك صلاحية للقيام بهذا الإجراء')
    }

    /**
     * Call next method in the pipeline and return its output
     */
    const output = await next()
    return output
  }
}
