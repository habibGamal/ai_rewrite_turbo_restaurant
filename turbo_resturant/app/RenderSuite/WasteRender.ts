import { PaginatorService } from '../services/PaginatorService.js'
import { RenderSuiteTemplate } from './RenderSuiteTemplate.js'
import Waste from '#models/Waste'

export default class WasteRender {
  public async render() {
    const pagination = new PaginatorService()
    const data = await pagination.paginate(Waste.query().preload('user'))
    const template = new RenderSuiteTemplate<typeof data>()
    template
      .title('سجل الهالك')
      .slug('wastes')
      .data(data)
      .searchable([template.searchWith('id', 'رقم الهالك')])
      .columns([
        template.column('id', 'رقم الهالك', true),
        template.column('total', 'الاجمالي', true),
        template.column('createdAt', 'التاريخ', true),
        template.column('user.email', 'تم بواسطة', true),
      ])
      .exportColumns(['id', 'total', 'createdAt', 'user.email'])
      .exportQuery(Waste.query().preload('user'))
      .form([template.col()])
      .actions({
        customActions: [template.customAction('عرض', 'wastes')],
      })
      .routes({})
      .noForm()

    return template.render()
  }
}
