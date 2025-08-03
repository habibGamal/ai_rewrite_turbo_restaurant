import router from '@adonisjs/core/services/router'
import { middleware } from '../kernel.js'
import Product from '#models/Product'
import { ProductType } from '#enums/ProductEnums'
import vine from '@vinejs/vine'
import Category from '#models/Category'

router
  .group(() => {
    router.get('/check', (ctx) => {
      ctx.response.json({ message: 'turbo' })
    })
    router.post('/check', (ctx) => {
      ctx.response.json({ message: 'turbo' })
    })

    router.get('/products/product_search', async (ctx) => {
      const { search } = ctx.request.qs()
      const products = await Product.query()
        .select(['id', 'name', 'product_ref'])
        .where('name', 'like', `%${search}%`)
        .whereNot('type', ProductType.RawMaterial)
      return ctx.response.json({
        products: products.map((product) => ({
          id: product.id,
          name: product.name,
          product_ref: product.productRef,
        })),
      })
    })
    router.post('/web-orders/place-order', '#controllers/WebOrdersController.createOrder')
    router.get('/can-accept-order', '#controllers/WebOrdersController.canAcceptOrder')
    router.get('/get-shift-id', '#controllers/WebOrdersController.getShiftId')

    router.post('/validate_products', async (ctx) => {
      const { productsRefs } = await ctx.request.validateUsing(
        vine.compile(
          vine.object({
            productsRefs: vine.array(
              vine.object({
                name: vine.string(),
                ref: vine.string(),
              })
            ),
          })
        )
      )
      const products = await Product.query()
        .whereIn(
          'product_ref',
          productsRefs.map((product) => product.ref)
        )
        .select(['name', 'product_ref'])
      const valid = productsRefs.length === products.length
      if (valid) return ctx.response.json({ valid })
      const invalidProductsRefs = productsRefs.filter(
        (productRef) => !products.find((product) => product.product_ref === productRef.ref)
      )
      return ctx.response.json({
        valid,
        invalidProductsRefs,
      })
    })

    router.get('/all-products', async (ctx) => {
      return ctx.response.json({
        data: await Product.query().where('type', ProductType.Manifactured).preload('category'),
      })
    })

    router.get('/all-products-refs-master', async (ctx) => {
      return ctx.response.json(
        await Category.query()
          .select(['id', 'name'])
          .preload('products', (query) => {
            query.select(['id', 'name', 'product_ref', 'type'])
          })
      )
    })

    router.get('/all-products-prices-master', async (ctx) => {
      return ctx.response.json(
        await Category.query()
          .select(['id', 'name'])
          .preload('products', (query) => {
            query.select(['id', 'name', 'product_ref', 'price', 'cost', 'type'])
          })
      )
    })

    router.get('/all-products-recipes-master', async (ctx) => {
      return ctx.response.json(
        (
          await Category.query()
            .select(['id', 'name'])
            .preload('products', (query) => {
              query
                .select(['id', 'name', 'product_ref'])
                .where({
                  type: ProductType.Manifactured,
                })

                .preload('components', (query) => {
                  query.select(['id', 'name', 'product_ref'])
                })
            })
        ).map((category) =>
          category.serialize({
            relations: {
              products: {
                fields: {
                  pick: ['id', 'name', 'productRef', 'componentsHash'],
                },
                relations: {
                  components: {
                    fields: [],
                  },
                },
              },
            },
          })
        )
      )
    })
    router.get('/get-products-master', async (ctx) => {
      const ids = ctx.request.qs().ids
      return ctx.response.json(
        await Product.query()
          .whereIn('id', ids)
          .preload('components', (query) => {
            query.preload('category')
          })
          .preload('category')
      )
    })
    router.get('/get-products-master-by-refs', async (ctx) => {
      const refs = ctx.request.qs().refs
      return ctx.response.json(await Product.query().whereIn('product_ref', refs))
    })
    router.get('/get-products-prices-master', async (ctx) => {
      const ids = ctx.request.qs().ids
      return ctx.response.json(
        await Product.query()
          .whereIn('id', ids)
          .select(['id', 'name', 'product_ref', 'type', 'price', 'cost'])
      )
    })
  })
  .prefix('api')
