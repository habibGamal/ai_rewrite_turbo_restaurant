import Supplier from '#models/Supplier'
import { PaginatorService } from '../services/PaginatorService.js'
import { RenderSuiteTemplate } from './RenderSuiteTemplate.js'

export class SupplierRender {
  public async render() {
    const pagination = new PaginatorService()
    const data = await pagination.paginate(Supplier.query())
    const template = new RenderSuiteTemplate<typeof data>()

    template
      .title('الموردين')
      .slug('supplier')
      .data(data)
      .columns([
        template.column('name', 'الاسم', true),
        template.column('phone', 'الهاتف'),
      ])
      .exportColumns(['id', 'name', 'phone'])
      .exportQuery(Supplier.query())
      .form([
        template.col(),
        template.text('name', 'الاسم'),
        template.text('phone', 'الهاتف'),
      ])
      .actions({
        editable: true,
        deletable: true,
      })
      .searchable([
        template.searchWith('name', 'الاسم'),
        template.searchWith('phone', 'الهاتف'),
      ])
      .routes({
        store: 'suppliers',
        update: 'suppliers',
        destroy: 'suppliers',
      })

    return template.render()
  }
}
