import type { HttpContext } from '@adonisjs/core/http'
import DailySnapshot from '#models/DailySnapshot'
import DailySnapshotService from '#services/DailySnapshotService'
import ErrorMsgException from '#exceptions/error_msg_exception'

export default class DailySnapshotsController {
  public async openDay({ response, message }: HttpContext) {
    const lastDay = await DailySnapshot.query().orderBy('id', 'desc').first()
    // if no day make the first day
    if (lastDay === null) {
      await DailySnapshotService.initializeFirstDay()
      message.success('تم فتح اليوم')
      return response.redirect().back()
    }
    // if already opened
    if (!lastDay.closed) throw new ErrorMsgException('اليوم مفتوح')

    // ensure that the last day is not current day
    // if it is, then open it again
    const currentDate = new Date().toISOString().split('T')[0]
    if (lastDay.day.toISODate() === currentDate) {
      lastDay.closed = false
      await lastDay.save()
      message.success('تم اعادة فتح اليوم')
      return response.redirect().back()
    }
    // create new day
    await DailySnapshotService.openNewDay()
    message.success('تم فتح اليوم')
    return response.redirect().back()
  }

  public async startAccounting({ response, message }: HttpContext) {
    await DailySnapshotService.startAcounting()
    message.success('تم حفظ مستويات المخزون كرصيد افتتاحي')
    return response.redirect().back()
  }

  public async closeDay({ response, message }: HttpContext) {
    await DailySnapshotService.closeDay()
    message.success('تم اغلاق اليوم')
    return response.redirect().back()
  }
}
