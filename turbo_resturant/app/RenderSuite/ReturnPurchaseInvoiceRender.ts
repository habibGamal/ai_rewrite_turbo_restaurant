import ReturnPurchaseInvoice from '#models/ReturnPurchaseInvoice'
import { RenderSuitePagination } from './RenderSuitePagination.js'
import { RenderSuiteTemplate } from './RenderSuiteTemplate.js'

export default class ReturnPurchaseInvoiceRender {
  public async render() {
    const pagination = new RenderSuitePagination()
    const data = await pagination.paginate(ReturnPurchaseInvoice.query().preload('supplier'))
    const template = new RenderSuiteTemplate<typeof data>()
    template
      .title('فواتير مرتجع الشراء')
      .slug('return-purchase-invoice')
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
        template.column('received', 'المدفوع', true),
        template.column('statusString', 'الحالة'),
        template.column('closedString', 'مغلق/مفتوح'),
      ])
      .exportColumns(['id', 'supplierId', 'total', 'received', 'status'])
      .exportQuery(ReturnPurchaseInvoice.query())
      .form([template.col()])
      .actions({
        customActions: [
          template.customAction('عرض', 'return-purchase-invoices'),
          template.customAction('تعديل', 'return-purchase-invoices/:id/edit', {
            type: 'primary',
          }),
          template.customAction('حذف', 'return-purchase-invoices', {
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
