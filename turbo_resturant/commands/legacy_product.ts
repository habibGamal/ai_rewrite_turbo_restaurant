import { BaseCommand } from '@adonisjs/core/ace'
import type { CommandOptions } from '@adonisjs/core/types/ace'
import db from '@adonisjs/lucid/services/db'

export default class LegacyProduct extends BaseCommand {
  static commandName = 'legacy:product'
  static description = ''

  static options: CommandOptions = {
    startApp: true,
    staysAlive: false,
  }

  async run() {
    try{
      this.logger.info('Legacy product')
      await db.rawQuery(
        `update products set name = concat(name,"-legacy") , legacy = 1`
      )
    }catch(error){
      this.logger.error(error.message)
    }
  }
}
