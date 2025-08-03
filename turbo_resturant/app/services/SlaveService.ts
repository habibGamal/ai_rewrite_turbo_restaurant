import { ProductType } from '#enums/ProductEnums'
import PartialReloadException from '#exceptions/partial_reload_exception'
import Category from '#models/Category'
import Product from '#models/Product'
import { inject } from '@adonisjs/core'
import { HttpContext } from '@adonisjs/core/http'
import db from '@adonisjs/lucid/services/db'
import axios, { AxiosResponse } from 'axios'
import https from 'https'
import SettingsService from './SettingsService.js'

type ProductRefArray = [
  {
    product_ref: string
  }[],
  [string],
]

const querySettings = {
  httpsAgent: new https.Agent({ rejectUnauthorized: false }),
}

@inject()
export default class SlaveService {
  constructor(
    protected ctx: HttpContext,
    protected settingsService: SettingsService
  ) {}

  /**
   *
   * @param ids format: 'id1,id2,id3'
   */
  private async queryGetProductsMaster(ids: string) {
    return this.tryMasterConnection(async () => {
      const masterLink = await this.settingsService.getMasterLink()
      return axios.get<Product[]>(
        masterLink + '/api/get-products-master?ids=,' + ids,
        querySettings
      )
    })
  }

  private async queryAllProductsRefsMaster() {
    return this.tryMasterConnection(async () => {
      const masterLink = await this.settingsService.getMasterLink()
      return await axios.get<
        {
          id: number
          name: string
          products: { id: number; name: string; productRef: string }[]
        }[]
      >(masterLink + '/api/all-products-refs-master', querySettings)
    })
  }

  private async queryAllProductsPricesMaster() {
    return this.tryMasterConnection(async () => {
      const masterLink = await this.settingsService.getMasterLink()
      return await axios.get<
        {
          id: number
          name: string
          products: { id: number; name: string; productRef: string; price: number; cost: number }[]
        }[]
      >(masterLink + '/api/all-products-prices-master', querySettings)
    })
  }

  private async queryGetProductsPricesMaster(ids: string) {
    return this.tryMasterConnection(async () => {
      const masterLink = await this.settingsService.getMasterLink()
      return await axios.get<Product[]>(
        masterLink + '/api/get-products-prices-master?ids=,' + ids,
        querySettings
      )
    })
  }

  private async queryAllProductsRecipesMaster() {
    return this.tryMasterConnection(async () => {
      const masterLink = await this.settingsService.getMasterLink()
      return await axios.get<
        {
          id: number
          name: string
          products: { id: number; name: string; productRef: string; componentsHash: string }[]
        }[]
      >(masterLink + '/api/all-products-recipes-master', querySettings)
    })
  }

  private async tryMasterConnection<T>(
    axiosCallback: () => Promise<AxiosResponse<T>>
  ): Promise<AxiosResponse<T>> {
    try {
      return await axiosCallback()
    } catch (e) {
      throw new PartialReloadException('لا يمكن الاتصال بالنقطة الرئيسية')
    }
  }

  /**
   *
   * @param ids format: 'id1,id2,id3'
   */
  async importProductsFromMaster(ids: string) {
    const products = await this.queryGetProductsMaster(ids)
    const productsDataMap = new Map(products.data.map((product) => [product.productRef, product]))
    // raw products
    const rawProducts = products.data
      .map((product) => product.components)
      .flat()
    // distinct raw products
    const rawProductsMap = new Map(rawProducts.map((product) => [product.productRef, product]))
    const rawProductsDistinct = Array.from(rawProductsMap.values())

    // create categories if not exists
    const categoriesNames = [
      ...new Set([
        ...products.data.map((product) => product.category.name),
        ...rawProducts.map((product) => product.category.name),
      ]),
    ]
    const localCategories = await Category.fetchOrCreateMany(
      ['name'],
      categoriesNames.map((name) => ({ name }))
    )
    const localcategoriesMap = new Map(localCategories.map((category) => [category.name, category]))
    // create raw products
    const createdRawProducts = await Product.fetchOrCreateMany(
      ['productRef'],
      rawProductsDistinct.map((product) => ({
        name: product.name,
        price: product.price,
        cost: product.cost,
        unit: product.unit,
        categoryId: localcategoriesMap.get(product.category.name)!.id,
        type: product.type,
        productRef: product.productRef,
      }))
    )
    const createdRawProductsMap = new Map(
      createdRawProducts.map((product) => [product.productRef, product])
    )

    // create products
    const newProducts = await Product.fetchOrCreateMany(
      ['productRef'],
      products.data.map((product) => ({
        name: product.name,
        price: product.price,
        cost: product.cost,
        unit: product.unit,
        categoryId: localcategoriesMap.get(product.category.name)!.id,
        type: product.type,
        productRef: product.productRef,
      }))
    )

    // create products components
    const manifacturedProducts = newProducts.filter(
      (product) => product.type === ProductType.Manifactured
    )
    for (const product of manifacturedProducts) {
      const productData = productsDataMap.get(product.productRef)!
      await product.updateComponents({
        components:
          productData.components.map((component) => ({
            productId: createdRawProductsMap.get(component.productRef)!.id,
            // @ts-ignore
            quantity: component.meta.pivot_quantity,
          })) || [],
      })
    }
  }

  async updateProductPricesFromMaster(ids: string) {
    const products = await this.queryGetProductsPricesMaster(ids)
    for (const product of products.data) {
      const localProduct = await Product.query()
        .where('product_ref', product.productRef)
        .firstOrFail()
      if (product.type === ProductType.Manifactured)
        await localProduct
          .merge({
            price: product.price,
          })
          .save()
      if (product.type === ProductType.RawMaterial)
        await localProduct
          .merge({
            price: product.cost,
            cost: product.cost,
          })
          .save()
      if (product.type === ProductType.Consumable)
        await localProduct
          .merge({
            price: product.price,
            cost: product.cost,
          })
          .save()
    }
  }

  async updateRecipesFromMaster(ids: string) {
    const products = await this.queryGetProductsMaster(ids)
    const productsDataMap = new Map(products.data.map((product) => [product.productRef, product]))
    // raw products
    const rawProducts = products.data
      .map((product) => product.components)
      .flat()
    // distinct raw products
    const rawProductsMap = new Map(rawProducts.map((product) => [product.productRef, product]))
    const rawProductsDistinct = Array.from(rawProductsMap.values())
    // create categories if not exists
    const categoriesNames = [...new Set(rawProducts.map((product) => product.category.name))]
    const localCategories = await Category.fetchOrCreateMany(
      ['name'],
      categoriesNames.map((name) => ({ name }))
    )
    const localcategoriesMap = new Map(localCategories.map((category) => [category.name, category]))

    // create raw products
    const createdRawProducts = await Product.fetchOrCreateMany(
      ['productRef'],
      rawProductsDistinct.map((product) => ({
        name: product.name,
        price: product.price,
        cost: product.cost,
        unit: product.unit,
        categoryId: localcategoriesMap.get(product.category.name)!.id,
        type: product.type,
        productRef: product.productRef,
      }))
    )
    const createdRawProductsMap = new Map(
      createdRawProducts.map((product) => [product.productRef, product])
    )

    // create products components
    const manifacturedProductsRefs = products.data.map((product) => product.productRef)
    const manifacturedProducts = await Product.query().whereIn('product_ref', manifacturedProductsRefs)
    for (const product of manifacturedProducts) {
      const productData = productsDataMap.get(product.productRef)!
      await product.updateComponents({
        components:
          productData.components.map((component) => ({
            productId: createdRawProductsMap.get(component.productRef)!.id,
            // @ts-ignore
            quantity: component.meta.pivot_quantity,
          })) || [],
      })
    }
  }

  async getNewProductsFromMaster() {
    const allMasterProducts = await this.queryAllProductsRefsMaster()
    const productRefs = allMasterProducts.data
      .map((category) => category.products)
      .flat()
      .map((product) => product.productRef)

    const query = `
      SELECT t.product_ref
      FROM (
        ${productRefs.map((ref) => `SELECT '${ref}' AS product_ref`).join(' UNION ALL ')}
      ) AS t
      LEFT JOIN products p
      ON t.product_ref = p.product_ref
      WHERE p.product_ref IS NULL;
    `
    const result = (await db.rawQuery(query)) as ProductRefArray
    const nonExistingProducts = result[0].map((product) => product.product_ref)
    return allMasterProducts.data.map((category) => ({
      ...category,
      products: category.products.filter((product) =>
        nonExistingProducts.includes(product.productRef)
      ),
    }))
  }

  async getChangedPricesProductsFromMaster() {
    const allMasterProducts = await this.queryAllProductsPricesMaster()
    const products = allMasterProducts.data.map((category) => category.products).flat()
    const productsRefs = products.map((product) => product.productRef)

    const query = `
      SELECT t.product_ref
      FROM (
        ${productsRefs.map((ref) => `SELECT '${ref}' AS product_ref`).join(' UNION ALL ')}
      ) AS t
      LEFT JOIN products p
      ON t.product_ref = p.product_ref
      WHERE p.product_ref IS NOT NULL AND (
        ${products
          .map(
            (product) =>
              `(p.product_ref = '${product.productRef}' AND (p.price != ${product.price} OR p.cost != ${product.cost}))`
          )
          .join(' OR ')}
      );
    `
    const result = (await db.rawQuery(query)) as ProductRefArray
    const changedPricesProducts = result[0].map((product) => product.product_ref)
    return allMasterProducts.data.map((category) => ({
      ...category,
      products: category.products.filter((product) =>
        changedPricesProducts.includes(product.productRef)
      ),
    }))
  }

  async getChangedRecipesFromMaster() {
    const masterManifacturedProducts = await this.queryAllProductsRecipesMaster()
    console.log(masterManifacturedProducts.data)
    const productsRefs = masterManifacturedProducts.data
      .map((category) => category.products)
      .flat()
      .map((product) => product.productRef)
    const products = await Product.query()
      .whereIn('product_ref', productsRefs)
      .preload('components')
    const productsMap = new Map(products.map((product) => [product.productRef, product]))
    return masterManifacturedProducts.data.map((category) => {
      return {
        ...category,
        products: category.products.filter((product) => {
          const localProduct = productsMap.get(product.productRef)
          if (!localProduct) return false
          return localProduct.componentsHash !== product.componentsHash
        }),
      }
    })
  }
}
