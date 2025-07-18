import ImportFromExcel from '#services/ImportFromExcel'
import { args, BaseCommand } from '@adonisjs/core/ace'
import type { CommandOptions } from '@adonisjs/core/types/ace'

export default class ImportExcel extends BaseCommand {
  static commandName = 'import:excel'
  static description = ''

  static options: CommandOptions = {
    startApp: true,
    staysAlive: false,
  }

  @args.string({ description: 'File name' })
  declare file: string
  async run() {
    try {
      this.logger.info('start importing excel data...')
      const importFromExcel = new ImportFromExcel()
      await importFromExcel.importData(this.file)
    } catch (error) {
      this.logger.error(error.message)
    }
  }
}
