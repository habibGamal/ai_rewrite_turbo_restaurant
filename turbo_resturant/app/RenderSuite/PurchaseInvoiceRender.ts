import PurchaseInvoice from '#models/PurchaseInvoice'
import { PaginatorService } from '../services/PaginatorService.js'
import { RenderSuiteTemplate } from './RenderSuiteTemplate.js'

export default class PurchaseInvoiceRender {
  public async render() {
    const pagination = new PaginatorService()
    const data = await pagination.paginate(PurchaseInvoice.query().preload('supplier'))
    const template = new RenderSuiteTemplate<typeof data>()
    template
      .title('فواتير الشراء')
      .slug('purchase-invoice')
      .data(data)
      .searchable([
        template.searchWith('id', 'رقم الفاتورة'),
        template.searchWith('createdAt', 'التاريخ'),
      ])
      .columns([
        template.column('id', 'رقم الفاتورة', true),
        template.column('supplier.name', 'المورد', true),
        template.column('total', 'الاجمالي', true),
        template.column('createdAt', 'التاريخ', true),
        template.column('paid', 'المدفوع', true),
        template.column('statusString', 'الحالة'),
        template.column('closedString', 'مغلق/مفتوح'),
      ])
      .exportColumns(['id', 'supplierId', 'total', 'paid', 'status'])
      .exportQuery(PurchaseInvoice.query())
      .form([template.col()])
      .actions({
        customActions: [
          template.customAction('عرض', 'purchase-invoices'),
          template.customAction('تعديل', 'purchase-invoices/:id/edit', { type: 'primary' }),
          template.customAction('حذف', 'purchase-invoices', {
            type: 'danger',
            method: 'delete',
          }),
        ],
      })
      .routes({})
      .noForm()

    return template.render()
  }
}
