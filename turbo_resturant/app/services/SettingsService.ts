import { SettingKeys } from '#enums/SettingsEnums'
import ErrorMsgException from '#exceptions/error_msg_exception'
import Setting from '#models/Setting'
import { inject } from '@adonisjs/core'
import { HttpContext } from '@adonisjs/core/http'

@inject()
export default class SettingsService {
  constructor(protected ctx: HttpContext) {}
  static hookedInCookies = [SettingKeys.NodeType]
  updateCookies(setting: Setting) {
    if (SettingsService.hookedInCookies.includes(setting.key as SettingKeys)) {
      this.ctx.response.plainCookie(setting.key, setting.value, {
        encode: false,
        httpOnly: false,
      })
    }
  }

  async getMasterLink() {
    const setting = await Setting.findBy('key', SettingKeys.MasterLink)
    if (!setting) throw new ErrorMsgException('برجاء ادخال رابط النقطة الرئيسية')
    return setting?.value
  }


  async getWebsiteLink() {
    const setting = await Setting.findBy('key', SettingKeys.WebsiteLink)
    if (!setting) throw new ErrorMsgException('برجاء ادخال رابط الموقع')
    return setting?.value
  }
}
