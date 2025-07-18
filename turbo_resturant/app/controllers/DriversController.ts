import Driver from '#models/Driver'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'

export default class DriversController {
  public async fetchDriverInfo({ request, response }: HttpContext) {
    const { phone } = await request.validateUsing(
      vine.compile(vine.object({ phone: vine.string() }))
    )
    const driver = await Driver.findBy('phone', phone)
    if (!driver) {
      return response.status(404).json({ message: 'Driver not found' })
    }
    return response.json(driver)
  }

  public async storeQuick({ response, request, session }: HttpContext) {
    try {
      const data = await request.validateUsing(
        vine.compile(vine.object({ phone: vine.string(), name: vine.string() }))
      )
      const driver = await Driver.updateOrCreate({ phone: data.phone }, data)
      return response.json(driver)
    } catch (error) {
      console.log(error)
      session.flash({ errors: 'تأكد من ادخال البيانات بشكل صحيح' })
      return response.status(400).json({ message: 'Bad request' })
    }
  }
}
