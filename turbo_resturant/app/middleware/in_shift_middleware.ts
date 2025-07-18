import type { HttpContext } from '@adonisjs/core/http'
import Shift from '#models/Shift'

export default class InShift {
  public async handle({ session, response }: HttpContext, next: () => Promise<void>) {
    // get last shift
    const shift = await Shift.query().orderBy('id', 'desc').first()
    const isShiftOpen = shift?.endAt === null
    if (!isShiftOpen) {
      return response.redirect().toRoute('start-shift')
    }
    session.put('shiftId', shift.id)
    await next()
  }
}
