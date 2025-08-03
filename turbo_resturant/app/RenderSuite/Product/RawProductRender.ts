import Product from '#models/Product'
import { PaginatorService } from '../../services/PaginatorService.js'
import { RenderSuiteTemplate } from '../RenderSuiteTemplate.js'
import { ProductType, ProductUnit } from '#enums/ProductEnums'
import Category from '#models/Category'
export default class RawProductRender {
  public async render() {
    const pagination = new PaginatorService()
    const data = await pagination.paginate(
      Product.query().preload('category').where('type', ProductType.RawMaterial)
    )
    const template = new RenderSuiteTemplate<typeof data>()

    const categories = await Category.all()
    const categoriesSelect: Record<number, string> = {}
    categories.forEach((category) => {
      categoriesSelect[category.id] = category.name
    })

    template
      .title('المنتجات الخام')
      .slug('product')
      .data(data)
      .columns([
        template.column('name', 'الاسم', true),
        template.column('price', 'السعر'),
        template.column('cost', 'التكلفة'),
        template.column('unit', 'الوحدة'),
        template.column('category.name', 'الفئة'),
        template.column('legacy', 'منتج قديم', true, undefined, {
          '0': 'لا',
          '1': 'نعم',
        }),
      ])
      .exportColumns(['id', 'name', 'cost', 'unit'])
      .exportQuery(Product.query().where('type', ProductType.RawMaterial))
      .form([
        template.col(),
        template.text('name', 'الاسم'),
        template.text('cost', 'التكلفة'),
        template.col(),
        template.select('unit', 'الوحدة', {
          [ProductUnit.KG]: 'كجم',
          [ProductUnit.Packet]: 'باكت',
        }),
        template.col(),
        template.select('categoryId', 'الفئة', categoriesSelect),
        template.checkbox('legacy', 'منتج قديم'),
      ])
      .actions({
        editable: true,
        deletable: true,
      })
      .searchable([
        template.searchWith('name', 'الاسم'),
        template.searchWith('cost', 'التكلفة'),
        template.searchWith('unit', 'الوحدة'),
      ])
      .routes({
        store: 'raw-products',
        update: 'raw-products',
        destroy: 'raw-products',
      })
    return template.render()
  }
}
