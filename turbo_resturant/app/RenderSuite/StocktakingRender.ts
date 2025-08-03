import Stocktaking from '#models/Stocktaking'
import { PaginatorService } from '../services/PaginatorService.js'
import { RenderSuiteTemplate } from './RenderSuiteTemplate.js'

export default class StocktakingRender {
  public async render() {
    const pagination = new PaginatorService()
    const data = await pagination.paginate(Stocktaking.query().preload('user'))
    const template = new RenderSuiteTemplate<typeof data>()
    template
      .title('سجل جرد المخزن')
      .slug('stocktaking')
      .data(data)
      .searchable([template.searchWith('id', 'رقم جرد المخزن')])
      .columns([
        template.column('id', 'رقم جرد المخزن', true),
        template.column('balance', 'المحصلة', true, true),
        template.column('closedString', 'مفتوح/مغلق', true),
        template.column('createdAt', 'التاريخ', true),
        template.column('user.email', 'تم بواسطة', true),
      ])
      .exportColumns(['id', 'balance', 'createdAt', 'user.email'])
      .exportQuery(Stocktaking.query().preload('user'))
      .form([template.col()])
      .actions({
        customActions: [template.customAction('عرض', 'stocktaking')],
      })
      .routes({})
      .noForm()

    return template.render()
  }
}
