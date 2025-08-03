import Product from '#models/Product'
import { PaginatorService } from '../../services/PaginatorService.js'
import { RenderSuiteTemplate } from '../RenderSuiteTemplate.js'
import { ProductType, ProductUnit } from '#enums/ProductEnums'
import Category from '#models/Category'
import Printer from '#models/Printer'
export default class ManifacturedProductRender {
  public async render() {
    const pagination = new PaginatorService()
    const data = await pagination.paginate(
      Product.query()
        .preload('category')
        .preload('printers')
        .where('type', ProductType.Manifactured)
    )
    const template = new RenderSuiteTemplate<typeof data>()

    const categories = await Category.all()
    const categoriesSelect: Record<number, string> = {}
    categories.forEach((category) => {
      categoriesSelect[category.id] = category.name
    })

    const printers = await Printer.all()
    const printersSelect: Record<number, string> = {}
    printers.forEach((printer) => {
      printersSelect[printer.id] = printer.name
    })

    template
      .title('المنتجات المصنعة')
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
      .exportColumns(['id', 'name', 'price', 'cost', 'unit'])
      .exportQuery(Product.query().where('type', ProductType.Manifactured).preload('category'))
      .form([
        template.col(),
        template.text('name', 'الاسم'),
        template.text('price', 'السعر', {
          type: [ProductType.RawMaterial],
        }),
        template.col(),
        template.select('unit', 'الوحدة', {
          [ProductUnit.KG]: 'كجم',
          [ProductUnit.Packet]: 'باكت',
        }),
        template.checkboxGroup('printers', 'الطابعات', printersSelect),
        template.col(),
        template.select('categoryId', 'الفئة', categoriesSelect),
        template.checkbox('legacy', 'منتج قديم'),
      ])
      .actions({
        editable: true,
        deletable: true,
        customActions: [
          {
            label: 'تعديل المعياري',
            actionRoute: 'manifacture-product/components',
          },
        ],
      })
      .searchable([
        template.searchWith('name', 'الاسم'),
        template.searchWith('price', 'السعر'),
        template.searchWith('cost', 'التكلفة'),
        template.searchWith('unit', 'الوحدة'),
        template.searchWith('category.name', 'الفئة'),
      ])
      .routes({
        store: 'manifactured-products',
        update: 'manifactured-products',
        destroy: 'manifactured-products',
      })
    return template.render()
  }
}
