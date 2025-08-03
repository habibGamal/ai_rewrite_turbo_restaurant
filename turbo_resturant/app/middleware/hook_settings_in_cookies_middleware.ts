import { NodeType, SettingKeys } from '#enums/SettingsEnums'
import Setting from '#models/Setting'
import type { HttpContext } from '@adonisjs/core/http'
import type { NextFn } from '@adonisjs/core/types/http'

export default class HookSettingsInCookiesMiddleware {
  async handle(ctx: HttpContext, next: NextFn) {
    /**
     * Middleware logic goes here (before the next call)
     */
    if (!ctx.request.plainCookie('nodeType')) {
      const nodeType = await Setting.firstOrCreate(
        {
          key: SettingKeys.NodeType,
        },
        {
          key: SettingKeys.NodeType,
          value: NodeType.Standalone,
        }
      )
      ctx.response.plainCookie('nodeType', nodeType.value, {
        encode: false,
        httpOnly: false,
      })
    }

    /**
     * Call next method in the pipeline and return its output
     */
    const output = await next()
    return output
  }
}
