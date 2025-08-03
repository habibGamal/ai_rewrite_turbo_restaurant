import app from '@adonisjs/core/services/app'
import { HttpContext, ExceptionHandler } from '@adonisjs/core/http'
import type { StatusPageRange, StatusPageRenderer } from '@adonisjs/core/types/http'
import { errors } from '@adonisjs/auth'
import { errors as vineErrors } from '@vinejs/vine'
import { errors as sessionErrors } from '@adonisjs/session'

import ErrorMsgException from './error_msg_exception.js'
import PartialReloadException from './partial_reload_exception.js'
import logger from '@adonisjs/core/services/logger'
import WebApiException from './web_api_exception.js'

export default class HttpExceptionHandler extends ExceptionHandler {
  /**
   * In debug mode, the exception handler will display verbose errors
   * with pretty printed stack traces.
   */
  protected debug = !app.inProduction

  /**
   * Status pages are used to display a custom HTML pages for certain error
   * codes. You might want to enable them in production only, but feel
   * free to enable them in development as well.
   */
  protected renderStatusPages = app.inProduction

  /**
   * Status pages is a collection of error code range and a callback
   * to return the HTML contents to send as a response.
   */
  protected statusPages: Record<StatusPageRange, StatusPageRenderer> = {
    '404': (error, { inertia }) => inertia.render('Errors/NotFound', { error }),
    '500..599': (error, { inertia }) => inertia.render('Errors/ServerError', { error }),
  }

  /**
   * The method is used for handling errors and returning
   * response to the client
   */
  async handle(error: unknown, ctx: HttpContext) {
    if (error instanceof PartialReloadException) {
      ctx.message.error(error.message)
      return ctx.inertia.location(ctx.request.url())
    }

    if (ctx.request.url().includes('/api/')) {
      if (error instanceof vineErrors.E_VALIDATION_ERROR) {
        ctx.response.status(422).json(error.messages)
        return
      }
      if (error instanceof WebApiException) {
        ctx.response.status(400).json(JSON.parse(error.message))
        return
      }
      // error as ValidationError
      console.log('api error', error)
      return super.handle(error, ctx)
    }

    console.log('error ', error)
    logger.use('error').error({
      error,
      user: ctx.auth?.user?.email,
      url: ctx.request.url(),
      method: ctx.request.method(),
      body: ctx.request.body(),
    })
    if (error instanceof ErrorMsgException) {
      ctx.message.error(error.message)
      return ctx.response.redirect().back()
    }

    if (error instanceof errors.E_INVALID_CREDENTIALS) {
      ctx.message.error('اسم المستخدم او كلمة المرور غير صحيحة')
      return ctx.response.redirect().back()
    }

    if (error instanceof errors.E_UNAUTHORIZED_ACCESS) {
      ctx.message.error('غير مصرح لك بالدخول الى هذه الصفحة')
      return super.handle(error, ctx)
    }

    if (error instanceof vineErrors.E_VALIDATION_ERROR) {
      ctx.session?.flash({ errors: error.messages })
      return ctx.response.redirect().back()
    }

    if (error instanceof Error) {
      ctx.session?.flash({
        exception: {
          name: error.name,
          message: error.message,
          stack: error.stack?.substring(0, 2000),
        },
      })

      return super.handle(error, ctx)
      // return ctx.response.redirect().back()
    }

    return super.handle(error, ctx)
  }

  /**
   * The method is used to report error to the logging service or
   * the a third party error monitoring service.
   *
   * @note You should not attempt to send a response from this method.
   */
  async report(error: unknown, ctx: HttpContext) {
    return super.report(error, ctx)
  }
}
