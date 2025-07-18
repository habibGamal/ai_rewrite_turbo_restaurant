import Product from '#models/Product'
import { RenderSuitePagination } from '../RenderSuitePagination.js'
import { RenderSuiteTemplate } from '../RenderSuiteTemplate.js'
import { ProductType, ProductUnit } from '#enums/ProductEnums'
import Printer from '#models/Printer'
import Category from '#models/Category'
export default class ConsumableProductRender {
  public async render() {
    const pagination = new RenderSuitePagination()
    const data = await pagination.paginate(
      Product.query().preload('category').preload('printers').where('type', ProductType.Consumable)
    )

    const template = new RenderSuiteTemplate<typeof data>()

    const printers = await Printer.all()
    const printersSelect: Record<number, string> = {}
    printers.forEach((printer) => {
      printersSelect[printer.id] = printer.name
    })

    const categories = await Category.all()
    const categoriesSelect: Record<number, string> = {}
    categories.forEach((category) => {
      categoriesSelect[category.id] = category.name
    })

    template
      .title('المنتجات الاستهلاكية')
      .slug('consumable-product')
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
      .exportQuery(Product.query().where('type', ProductType.Consumable))
      .exportColumns(['id', 'name', 'price', 'cost', 'unit'])
      .form([
        template.col(),
        template.text('name', 'الاسم'),
        template.text('price', 'السعر'),
        template.col(),
        template.text('cost', 'التكلفة'),
        template.select('unit', 'الوحدة', {
          [ProductUnit.KG]: 'كجم',
          [ProductUnit.Packet]: 'باكت',
        }),
        template.col(),
        template.checkboxGroup('printers', 'الطابعات', printersSelect),
        template.select('categoryId', 'الفئة', categoriesSelect),
        template.checkbox('legacy', 'منتج قديم'),
      ])
      .actions({
        editable: true,
        deletable: true,
      })
      .searchable([
        template.searchWith('name', 'الاسم'),
        template.searchWith('price', 'السعر'),
        template.searchWith('cost', 'التكلفة'),
        template.searchWith('unit', 'الوحدة'),
      ])
      .routes({
        store: 'consumable-products',
        update: 'consumable-products',
        destroy: 'consumable-products',
      })
    return template.render()
  }
}
