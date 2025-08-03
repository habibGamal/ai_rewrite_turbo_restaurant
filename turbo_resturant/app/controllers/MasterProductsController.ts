import SlaveService from '#services/SlaveService'
import { inject } from '@adonisjs/core'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'

@inject()
export default class MasterProductsController {
  constructor(protected slaveService: SlaveService) {}

  public async show({ inertia }: HttpContext) {
    return inertia.render('ImportFromMaster', {
      newProducts: inertia.lazy(() => this.slaveService.getNewProductsFromMaster()),
      changedPrices: inertia.lazy(() => this.slaveService.getChangedPricesProductsFromMaster()),
      changedRecipes: inertia.lazy(() => this.slaveService.getChangedRecipesFromMaster()),
    })
  }

  public async importProducts({ request, response, message }: HttpContext) {
    const productsIds = await request.validateUsing(
      vine.compile(
        vine.object({
          products: vine.array(vine.any()),
        })
      )
    )
    const ids = productsIds.products.join(',')
    await this.slaveService.importProductsFromMaster(ids)
    message.success('تم استيراد المنتجات بنجاح')
    return response.redirect().back()
  }

  public async updatePrices({ request, response, message }: HttpContext) {
    const productsIds = await request.validateUsing(
      vine.compile(
        vine.object({
          products: vine.array(vine.any()),
        })
      )
    )
    const ids = productsIds.products.join(',')
    await this.slaveService.updateProductPricesFromMaster(ids)
    message.success('تم تحديث اسعار المنتجات بنجاح')
    return response.redirect().back()
  }

  public async updateRecipes({ request, response, message }: HttpContext) {
    const productsIds = await request.validateUsing(
      vine.compile(
        vine.object({
          products: vine.array(vine.any()),
        })
      )
    )
    const ids = productsIds.products.join(',')
    await this.slaveService.updateRecipesFromMaster(ids)
    message.success('تم تحديث المعياري للمنتجات بنجاح')
    return response.redirect().back()
  }
}
