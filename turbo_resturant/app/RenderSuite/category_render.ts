import Category from '#models/Category'
import { RenderSuiteTemplate } from './RenderSuiteTemplate.js'
import { RenderSuitePagination } from './RenderSuitePagination.js'
export class CategoryRender {
  public async render() {
    const pagination = new RenderSuitePagination()
    const data = await pagination.paginate(Category.query())

    const template = new RenderSuiteTemplate<typeof data>()
    template
      .title('الفئات')
      .importRoute('categories.import-from-excel')
      .slug('category')
      .data(data)
      .searchable([template.searchWith('name', 'الاسم')])
      .columns([template.column('name', 'الاسم', true)])
      .exportColumns(['id', 'name'])
      .exportQuery(Category.query())
      .form([template.col(), template.text('name', 'الاسم')])
      .actions({
        editable: true,
        deletable: true,
      })
      .routes({
        store: 'categories',
        update: 'categories',
        destroy: 'categories',
      })

    return template.render()
  }
}
