import Region from '#models/Region'
import { RegionRender } from '#render/RegionRender'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'

const regionSchema = vine.compile(
  vine.object({
    name: vine.string(),
    deliveryCost: vine.number(),
  })
)

export default class RegionsController {
  public async index({ inertia }: HttpContext) {
    return inertia.render('RenderModel', await new RegionRender().render())
  }

  public async store({ response, request, message }: HttpContext) {
    const data = await request.validateUsing(regionSchema)
    await Region.create({
      name: data.name,
      deliveryCost: data.deliveryCost,
    })
    message.success('تمت الإضافة بنجاح')
    return response.redirect().back()
  }

  public async update({ response, request, params, message }: HttpContext) {
    const data = await request.validateUsing(regionSchema)
    const region = await Region.findOrFail(params.id)
    region.merge({
      name: data.name,
      deliveryCost: data.deliveryCost,
    })
    await region.save()
    message.success('تم التعديل بنجاح')
    return response.redirect().back()
  }

  public async destroy({ response, params, message }: HttpContext) {
    const region = await Region.findOrFail(params.id)
    await region.delete()
    message.success('تم الحذف بنجاح')
    return response.redirect().back()
  }
}
