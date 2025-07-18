import ErrorMsgException from '#exceptions/error_msg_exception'
import layout from '#helpers/layout'
import watcher, { readLogFile } from '#helpers/watcher'
import Shift from '#models/Shift'
import type { HttpContext } from '@adonisjs/core/http'
import fs from 'fs'

export default class WatchersController {
  public async show({ inertia, params }: HttpContext) {
    try{

      const shiftId = params.id
      const shift = await Shift.findOrFail(shiftId)
      const logs = await readLogFile(shiftId)
      return inertia.render('Reports/LogsReport'+layout(), {
        shift:shift,
        logs: logs
       })
    }catch(error){
      throw new ErrorMsgException('هذه الوردية ليس لها سجل')
    }
  }
}
