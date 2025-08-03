import { OrderType } from '#enums/OrderEnums'
import { PaymentMethod } from '#enums/PaymentEnums'
import Order from '#models/Order'
import OrderManagerService from '#services/OrdersManageService'
import WebApiService from '#services/WebApiService'
import { inject } from '@adonisjs/core'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'

@inject()
export default class WebOrdersController {
  constructor(protected webApiService: WebApiService) {}

  public async canAcceptOrder({ response }: HttpContext) {
    const canAccept = await this.webApiService.canAcceptOrder()
    return response.json({ can_accept: canAccept })
  }

  public async getShiftId({ response }: HttpContext) {
    const shiftId = await this.webApiService.getShiftId()
    return response.json({ shift_id: shiftId })
  }

  public async createOrder({ request, response }: HttpContext) {
    console.log('createWEBOrder')
    const orderRequest = await request.validateUsing(
      vine.compile(
        vine.object({
          user: vine.object({
            name: vine.string(),
            phone: vine.string(),
            area: vine.string(),
            address: vine.string(),
          }),
          order: vine.object({
            type: vine.enum([OrderType.WebDelivery, OrderType.WebTakeaway]),
            shiftId: vine.number(),
            orderNumber: vine.string(),
            subTotal: vine.number(),
            tax: vine.number(),
            service: vine.number(),
            discount: vine.number(),
            total: vine.number(),
            note: vine.string().optional(),
            items: vine.array(
              vine.object({
                quantity: vine.number(),
                notes: vine.string().optional(),
                posRefObj: vine.array(
                  vine.object({
                    productRef: vine.string(),
                    quantity: vine.number(),
                  })
                ),
              })
            ),
          }),
        })
      )
    )

    await this.webApiService.placeOrder(orderRequest)

    return response.json({
      message: 'Order created successfully',
    })
  }

  public async acceptOrder(ctx: HttpContext) {
    await this.webApiService.acceptOrder(ctx.request.param('id'))
    return ctx.response.redirect().back()
  }

  public async rejectOrder(ctx: HttpContext) {
    await this.webApiService.rejectOrder(ctx.request.param('id'))
    return ctx.response.redirect().back()
  }

  public async cancelOrder(ctx: HttpContext) {
    await this.webApiService.rejectOrder(ctx.request.param('id'))
    return ctx.response.redirect().back()
  }

  public async completeOrder(ctx: HttpContext) {
    const data = await ctx.request.validateUsing(
      vine.compile(
        vine.object({
          [PaymentMethod.Card]: vine.number(),
          [PaymentMethod.Cash]: vine.number(),
          [PaymentMethod.TalabatCard]: vine.number(),
        })
      )
    )

    const order = await this.webApiService.completeOrder(ctx.params.id)
    // this complete the order as it is local order
    await OrderManagerService.completeWebOrder(order, ctx.session.get('shiftId'), data)

    return ctx.response.redirect().back()
  }

  public async outForDelivery(ctx: HttpContext) {
    await this.webApiService.outForDelivery(ctx.request.param('id'))
    return ctx.response.redirect().back()
  }

  public async applyDiscount(ctx: HttpContext) {
    const { discount, discountType } = await ctx.request.validateUsing(
      vine.compile(
        vine.object({
          discount: vine.number(),
          discountType: vine.enum(['percent', 'value']),
        })
      )
    )
    await this.webApiService.applyDiscount(ctx.params.id, discount, discountType)
    ctx.message.success('تم تطبيق الخصم')
    return ctx.response.redirect().back()
  }

  public async saveOrder({ request, params, response, message }: HttpContext) {
    // validation
    const { items } = await request.validateUsing(
      vine.compile(
        vine.object({
          items: vine.array(
            vine.object({
              productId: vine.number(),
              notes: vine.string().optional(),
            })
          ),
        })
      )
    )
    const order = await Order.findOrFail(params.id)
    await order.load('items')
    for (const item of items) {
      if (!item.notes) continue
      const orderItem = order.items.find((i) => i.productId === item.productId)
      if (orderItem) {
        orderItem.notes = item.notes
        await orderItem.save()
      }
    }

    message.success('تم حفظ الطلب')
    return response.redirect().back()
  }
}
