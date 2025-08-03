import { SettingKeys } from '#enums/SettingsEnums'
import Setting from '#models/Setting'
import SettingsService from '#services/SettingsService'
import { inject } from '@adonisjs/core'
import type { HttpContext } from '@adonisjs/core/http'
import { PrinterTypes, ThermalPrinter } from 'node-thermal-printer'
import os from 'os'

@inject()
export default class SettingsController {
  constructor(protected settingsService:SettingsService){}

  public async index({ inertia }: HttpContext) {
    const settings = await Setting.all()
    const settingsPairs: { [key: string]: string } = {}
    settings.forEach((setting) => {
      settingsPairs[setting.key] = setting.value
    })
    return inertia.render('Settings', {
      settings: settingsPairs,
    })
  }

  public async scanForPrinters({ response }: HttpContext) {
    const interfaces = os.networkInterfaces()
    console.log(interfaces)
    const activePrinters: string[] = []
    // scan all open port 9100 hosts
    for (let i = 1; i < 255; i++) {
      const printer = 'tcp://192.168.88.' + i
      const alive = await new ThermalPrinter({
        type: PrinterTypes.EPSON,
        interface: printer,
      }).isPrinterConnected()
      if (alive) activePrinters.push(printer)
    }
    console.log(activePrinters)
    return response.redirect().back()
  }

  public async setCasheirPrinter({ response, session, message, request }: HttpContext) {
    const printer = request.input('cashierPrinter')
    await Setting.updateOrCreate(
      { key: 'cashierPrinter' },
      { key: 'cashierPrinter', value: printer }
    )
    session.put('cashierPrinter', printer)
    message.success('تم تغيير الطابعة بنجاح')
    return response.redirect().back()
  }

  public async setSetting(ctx: HttpContext) {
    const key = ctx.params.key
    const value = ctx.request.input(key)
    const setting = await Setting.updateOrCreate({ key }, { key, value })
     this.settingsService.updateCookies(setting)
    ctx.message.success('تم التحديث بنجاح')
    return ctx.response.redirect().back()
  }

  public async importDataFromExcel({}: HttpContext) {
    // TODO: Implement this method
  }
}
