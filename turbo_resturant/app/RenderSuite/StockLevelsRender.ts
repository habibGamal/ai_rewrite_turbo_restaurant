import InventoryItem from '#models/InventoryItem'
import { RenderSuitePagination } from './RenderSuitePagination.js'
import { RenderSuiteTemplate } from './RenderSuiteTemplate.js'

export default class StockLevelsRender {
  public async render() {
    const pagination = new RenderSuitePagination()
    const data = await pagination.paginate(InventoryItem.query().preload('product'))
    const template = new RenderSuiteTemplate<typeof data>()
    template
      .title('مستويات المخزون')
      .slug('stock')
      .data(data)
      .searchable([template.searchWith('product.name', 'اسم المنتج')])
      .columns([
        template.column('product.name', 'اسم المنتج', true),
        template.column('quantity', 'الكمية', true),
        template.column('product.cost', 'التكلفة'),
        template.column('product.price', 'السعر'),
      ])
      .exportColumns([
        'id',
        'productId',
        'product.name',
        'quantity',
        'product.cost',
        'product.price',
      ])
      .exportQuery(InventoryItem.query().preload('product'))
      .form([template.col()])
      .actions({})
      .routes({})
      .noForm()
      .noActions()

    return template.render()
  }
}
