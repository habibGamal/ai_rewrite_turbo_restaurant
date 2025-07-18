import { RenderSuiteTemplate } from './RenderSuiteTemplate.js'
import { RenderSuitePagination } from './RenderSuitePagination.js'
import ExpenseType from '#models/ExpenceType'

export class ExpenseTypeRender {
  public async render() {
    const pagination = new RenderSuitePagination()
    const data = await pagination.paginate(ExpenseType.query())
    const template = new RenderSuiteTemplate<typeof data>()
    template
      .title('انواع المصروفات')
      .slug('expense-type')
      .data(data)
      .searchable([template.searchWith('name', 'الاسم')])
      .columns([template.column('name', 'الاسم', true)])
      .exportColumns(['id', 'name'])
      .exportQuery(ExpenseType.query())
      .form([template.col(), template.text('name', 'الاسم')])
      .actions({
        editable: true,
        deletable: true,
      })
      .routes({
        store: 'expense-types',
        update: 'expense-types',
        destroy: 'expense-types',
      })

    return template.render()
  }
}
