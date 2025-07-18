import Customer from '#models/Customer'
import { RenderSuitePagination } from './RenderSuitePagination.js'
import { RenderSuiteTemplate } from './RenderSuiteTemplate.js'
import { CustomerRegion } from '#enums/CustomerEnums'

export default class CustomerRender {
  public async render() {
    const pagination = new RenderSuitePagination()
    const data = await pagination.paginate(Customer.query())
    const template = new RenderSuiteTemplate<typeof data>()
    template
      .title('العملاء')
      .slug('customer')
      .data(data)
      .searchable([template.searchWith('name', 'الاسم')])
      .columns([
        template.column('name', 'الاسم', true),
        template.column('phone', 'الهاتف'),
        template.column('address', 'العنوان'),
        template.column('hasWhatsapp', 'لديه واتساب', true),
      ])
      .exportColumns(['id', 'name', 'phone', 'address'])
      .exportQuery(Customer.query())
      .form([
        template.col(),
        template.text('name', 'الاسم'),
        template.text('phone', 'الهاتف'),
        template.text('address', 'العنوان'),
        template.checkbox('hasWhatsapp', 'لديه واتساب'),
        template.select('region', 'المنطقة', {
          [CustomerRegion.EAST]: CustomerRegion.EAST,
          [CustomerRegion.WEST]: CustomerRegion.WEST,
        }),
      ])
      .actions({
        editable: true,
        deletable: true,
      })
      .routes({
        store: 'customers',
        update: 'customers',
        destroy: 'customers',
      })

    return template.render()
  }
}
