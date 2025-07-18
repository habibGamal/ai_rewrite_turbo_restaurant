import { ProductType, ProductUnit } from '#enums/ProductEnums'
import Category from '#models/Category'
import Order from '#models/Order'
import Printer from '#models/Printer'
import Product from '#models/Product'
import User from '#models/User'
import testUtils from '@adonisjs/core/services/test_utils'
import { test } from '@japa/runner'

test.group('Consumable product', (group) => {
  let user: User
  group.each.setup(() => testUtils.db().withGlobalTransaction())
  group.setup(async () => {
    user = (await User.findBy('email', 'admin@span.com'))!
  })

  test('consumable_products.index', async ({ client }) => {
    const page = await client.get('/consumable-products').header('X-Inertia', 'true').loginAs(user)
    page.assertInertiaComponent('RenderModel')
    page.assertInertiaPropsContains({
      title: 'المنتجات الاستهلاكية',
      columns: [
        { key: 'name', label: 'الاسم' },
        { key: 'price', label: 'السعر' },
        { key: 'cost', label: 'التكلفة' },
        { key: 'unit', label: 'الوحدة' },
        { key: 'category.name', label: 'الفئة' },
      ],
      slug: 'consumable-product',
      searchable: [{ key: 'name', label: 'الاسم' }],
      form: [
        { key: 'name', label: 'الاسم' },
        { key: 'price', label: 'السعر' },
        { key: 'cost', label: 'التكلفة' },
        { key: 'unit', label: 'الوحدة' },
        { key: 'categoryId', label: 'الفئة' },
        { key: 'printers', label: 'الطابعات' },
      ],
      actions: { editable: true, deletable: true },
      routes: {
        store: 'consumable-products',
        update: 'consumable-products',
        destroy: 'consumable-products',
      },
      data: { meta: {}, data: [] },
    })
  })

  test('consumable_products.store', async ({ client, assert }) => {
    const category = await Category.create({ name: 'test category' })
    const printer = await Printer.create({ name: 'test printer' })
    await client
      .post('/consumable-products')
      .form({
        name: 'test create',
        price: 10,
        cost: 5,
        unit: ProductUnit.KG,
        categoryId: category.id,
        printers: [printer.id],
      })
      .loginAs(user)
      .withCsrfToken()
    // check db for the new product
    const product = await Product.query()
      .where('type', ProductType.Consumable)
      .where('name', 'test create')
      .preload('printers')
      .firstOrFail()
    // assert the response
    assert.equal(product.name, 'test create')
    assert.equal(product.categoryId, category.id)
    assert.equal(product.printers[0].id, printer.id)
    assert.equal(product.price, 10)
    assert.equal(product.cost, 5)
    assert.equal(product.unit, ProductUnit.KG)
  })

  test('consumable_products.update', async ({ client, assert }) => {
    const category = await Category.create({ name: 'test category' })
    const printer = await Printer.create({ name: 'test printer' })
    const product = await Product.create({
      name: 'old name',
      type: ProductType.Consumable,
      categoryId: category.id,
      price: 10,
      cost: 5,
      unit: ProductUnit.KG,
    })
    await client
      .put(`/consumable-products/${product.id}`)
      .form({
        name: 'new name',
        price: 20,
        cost: 15,
        unit: ProductUnit.Packet,
        categoryId: category.id,
        printers: [printer.id],
      })
      .loginAs(user)
      .withCsrfToken()
    // check db for the updated product
    await (await product.refresh()).load('printers')
    // assert the response
    assert.equal(product.name, 'new name')
    assert.equal(product.categoryId, category.id)
    assert.equal(product.printers[0].id, printer.id)
    assert.equal(product.price, 20)
    assert.equal(product.cost, 15)
    assert.equal(product.unit, ProductUnit.Packet)
  })

  test('consumable_products.destroy', async ({ client, assert }) => {
    const category = await Category.create({ name: 'test category' })
    const product = await Product.create({
      name: 'test delete',
      type: ProductType.Consumable,
      categoryId: category.id,
      price: 10,
      cost: 5,
      unit: ProductUnit.KG,
    })
    await client.delete(`/consumable-products/${product.id}`).loginAs(user).withCsrfToken()
    // check db for the deleted product
    const productCount = await Product.query()
      .where('name', 'test delete')
      .count('* as count')
      .pojo<{ count: number }>()
    // assert the response
    assert.equal(productCount[0].count, 0)
  })

  test('cant destroy consumable product that is used as a component', async ({
    client,
    assert,
  }) => {
    const category = await Category.create({ name: 'test category' })
    const product = await Product.create({
      name: 'test delete',
      type: ProductType.Consumable,
      categoryId: category.id,
      price: 10,
      cost: 5,
      unit: ProductUnit.KG,
    })
    await product.related('componentOf').create({
      name: 'component',
      type: ProductType.Manifactured,
      categoryId: category.id,
      price: 10,
      cost: 5,
      unit: ProductUnit.KG,
    })
    await client.delete(`/consumable-products/${product.id}`).loginAs(user).withCsrfToken()
    // check db for the deleted product
    const productCount = await Product.query()
      .where('name', 'test delete')
      .count('* as count')
      .pojo<{ count: number }>()
    // assert the response
    assert.equal(productCount[0].count, 1)
  })

  test('cant destroy consumable product that is used in any order', async ({ client, assert }) => {
    const category = await Category.create({ name: 'test category' })
    const product = await Product.create({
      name: 'test delete',
      type: ProductType.Consumable,
      categoryId: category.id,
      price: 10,
      cost: 5,
      unit: ProductUnit.KG,
    })
    const order = await Order.create({ shiftId: 1 })
    await product
      .related('orderItems')
      .create({ quantity: 1, price: 10, total: 10, cost: 5, orderId: order.id })
    await client.delete(`/consumable-products/${product.id}`).loginAs(user).withCsrfToken()
    // check db for the deleted product
    const productCount = await Product.query()
      .where('name', 'test delete')
      .count('* as count')
      .pojo<{ count: number }>()
    // assert the response
    assert.equal(productCount[0].count, 1)
  })
})
