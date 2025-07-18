import Region from '#models/Region'
import { RenderSuitePagination } from './RenderSuitePagination.js'
import { RenderSuiteTemplate } from './RenderSuiteTemplate.js'

export class RegionRender {
  public async render() {
    const pagination = new RenderSuitePagination()
    const data = await pagination.paginate(Region.query())
    const template = new RenderSuiteTemplate<typeof data>()

    template
      .title('المناطق')
      .slug('regions')
      .data(data)
      .columns([
        template.column('name', 'اسم المنطقة', true),
        template.column('deliveryCost', 'تكلفة التوصيل'),
      ])
      // .exportColumns(['id', 'name', 'phone'])
      // .exportQuery(Region.query())
      .form([
        template.col(),
        template.text('name', 'اسم المنطقة'),
        template.number('deliveryCost', 'تكلفة التوصيل'),
      ])
      .actions({
        editable: true,
        deletable: true,
      })
      .searchable([
        template.searchWith('name', 'الاسم'),
      ])
      .routes({
        store: 'regions',
        update: 'regions',
        destroy: 'regions',
      })

    return template.render()
  }
}
