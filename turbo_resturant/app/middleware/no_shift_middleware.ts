import type { HttpContext } from '@adonisjs/core/http'
import Shift from '#models/Shift'

export default class NoShift {
  public async handle({ session, response }: HttpContext, next: () => Promise<void>) {
    // get last shift
    const shift = await Shift.query().orderBy('id', 'desc').first()
    const isShiftClosed = shift?.endAt !== null
    if (isShiftClosed) {
      return await next()
    }
    session.put('shiftId', shift.id)
    session.flash('errors', 'انت بالفعل في وردية')
    return response.redirect().toRoute('cashier-screen')
  }
}
