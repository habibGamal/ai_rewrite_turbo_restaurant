import Customer from '#models/Customer'
import CustomerRender from '#render/CustomerRender'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'

const customerSchema = vine.compile(
  vine.object({
    name: vine.string(),
    phone: vine.string(),
    address: vine.string(),
    hasWhatsapp: vine.boolean(),
    region: vine.string(),
    deliveryCost: vine.number().optional(),
  })
)

export default class CustomersController {
  public async fetchCustomerInfo({ request, response }: HttpContext) {
    const { phone } = await request.validateUsing(
      vine.compile(
        vine.object({
          phone: vine.string(),
        })
      )
    )
    const customer = await Customer.findBy('phone', phone)
    if (!customer) {
      return response.status(404).json({ message: 'Customer not found' })
    }
    return response.json(customer)
  }

  public async storeQuick({ response, request }: HttpContext) {
    const data = await request.validateUsing(customerSchema)
    const customer = await Customer.updateOrCreate({ phone: data.phone }, data)
    return response.json(customer)
  }

  public async index({ inertia }: HttpContext) {
    return inertia.render('RenderModel', await new CustomerRender().render())
  }

  public async store({ response, request, message }: HttpContext) {
    const data = await request.validateUsing(customerSchema)
    await Customer.create(data)
    message.success('تم اضافة العميل بنجاح')
    return response.redirect().back()
  }

  public async update({ response, request, params, message }: HttpContext) {
    const data = await request.validateUsing(customerSchema)
    const customer = await Customer.findOrFail(params.id)
    customer.merge(data)
    await customer.save()
    message.success('تم تعديل العميل بنجاح')
    return response.redirect().back()
  }

  public async destroy({ response, params, message }: HttpContext) {
    const customer = await Customer.findOrFail(params.id)
    await customer.loadCount('orders')
    if (customer.$extras.orders_count) {
      message.error('لا يمكن حذف العميل لوجود طلبات مرتبطة به')
      return response.redirect().back()
    }
    await customer.delete()
    return response.redirect().back()
  }
}
