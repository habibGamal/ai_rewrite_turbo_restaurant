import Supplier from '#models/Supplier'
import { SupplierRender } from '#render/SupplierRender'
import { exportToCSV } from '#services/ExportCSV'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'

const supplierSchema = vine.compile(
  vine.object({
    name: vine.string(),
    phone: vine.string(),
  })
)
export default class SuppliersController {
  public async index({ inertia }: HttpContext) {
    return inertia.render('RenderModel', await new SupplierRender().render())
  }

  public async exportCSV({ response }: HttpContext) {
    const suppliers = await Supplier.all()
    const columns = ['id', 'name', 'phone']
    const data = suppliers.map((supplier) => {
      return {
        id: supplier.id,
        name: supplier.name,
        phone: supplier.phone,
      }
    })
    const result = await exportToCSV('suppliers', columns, data)
    return response.json(result)
  }

  public async store({ response, request, message }: HttpContext) {
    const data = await request.validateUsing(supplierSchema)
    await Supplier.create(data)
    message.success('تم اضافة المورد بنجاح')
    return response.redirect().back()
  }

  public async update({ response, request, params, message }: HttpContext) {
    const data = await request.validateUsing(supplierSchema)
    const supplier = await Supplier.findOrFail(params.id)
    supplier.merge(data)
    await supplier.save()
    message.success('تم تعديل المورد بنجاح')
    return response.redirect().back()
  }

  public async destroy({ response, params, message }: HttpContext) {
    const supplier = await Supplier.findOrFail(params.id)
    await supplier.delete()
    message.success('تم حذف المورد بنجاح')
    return response.redirect().back()
  }
}
