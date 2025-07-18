import Category from '#models/Category'
import User from '#models/User'
import testUtils from '@adonisjs/core/services/test_utils'
import { test } from '@japa/runner'

test.group('category', (group) => {
  let user: User
  group.setup(() => testUtils.db().withGlobalTransaction())
  group.setup(async () => {
    user = (await User.findBy('email', 'admin@span.com'))!
  })

  test('categories.index', async ({ client }) => {
    const page = await client.get('/categories').header('X-Inertia', 'true').loginAs(user)
    page.assertInertiaComponent('RenderModel')
    page.assertInertiaPropsContains({
      title: 'الفئات',
      columns: [{ key: 'name', label: 'الاسم' }],
      slug: 'category',
      searchable: [{ key: 'name', label: 'الاسم' }],
      form: [{ key: 'name', label: 'الاسم' }],
      actions: { editable: true, deletable: true },
      routes: { store: 'categories', update: 'categories', destroy: 'categories' },
      data: { meta: {}, data: [] },
    })
  })

  test('categories.store', async ({ client, assert }) => {
    await client.post('/categories').form({ name: 'test create' }).loginAs(user).withCsrfToken()
    // // check db for the new category
    const category = await Category.query().where('name', 'test create').firstOrFail()
    // // assert the response
    assert.equal(category.name, 'test create')
  })

  test('categories.update', async ({ client, assert }) => {
    const category = await Category.create({ name: 'old name' })
    await client.put(`/categories/${category.id}`).form({ name: 'new name' }).loginAs(user).withCsrfToken()
    // check db for the updated category
    await category.refresh()
    // assert the response
    assert.equal(category.name, 'new name')
  })

  test('categories.destroy', async ({ client, assert }) => {
    const category = await Category.create({ name: 'test delete' })
    await client.delete(`/categories/${category.id}`).loginAs(user).withCsrfToken()
    // check db for the deleted category
    const categoryCount = await Category.query()
      .where('name', 'test delete')
      .count('* as count')
      .pojo<{ count: number }>()
    // assert the response
    assert.equal(categoryCount[0].count, 0)
  })

  test('cant destroy category that has products', async ({ client, assert }) => {
    const category = await Category.create({ name: 'test delete' })
    await category.related('products').create({ name: 'product' })
    await client.delete(`/categories/${category.id}`).loginAs(user).withCsrfToken()
    // check db for the deleted category
    const categoryCount = await Category.query()
      .where('name', 'test delete')
      .count('* as count')
      .pojo<{ count: number }>()
    // assert the response
    assert.equal(categoryCount[0].count, 1)
  })
})
