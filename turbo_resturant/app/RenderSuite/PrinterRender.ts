import Printer from '#models/Printer'
import { RenderSuiteTemplate } from './RenderSuiteTemplate.js'
import { PaginatorService } from '../services/PaginatorService.js'

export default class PrinterRender {
  public async render() {
    const pagination = new PaginatorService()
    const data = await pagination.paginate(Printer.query())
    const template = new RenderSuiteTemplate<typeof data>()

    template
      .title('الطابعات')
      .slug('printer')
      .data(data)
      .searchable([template.searchWith('name', 'الاسم')])
      .columns([template.column('name', 'الاسم', true), template.column('ipAddress', 'IP')])
      .exportColumns(['id', 'name'])
      .exportQuery(Printer.query())
      .form([template.col(), template.text('name', 'الاسم'), template.text('ipAddress', 'IP')])
      .actions({
        editable: true,
        deletable: true,
        customActions: [
          {
            label: 'المنتجات',
            actionRoute: 'mapping-printer-products',
          },
        ],
      })
      .routes({
        store: 'printers',
        update: 'printers',
        destroy: 'printers',
      })

    return template.render()
  }
}
