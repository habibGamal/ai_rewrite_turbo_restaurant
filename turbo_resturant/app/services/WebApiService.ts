import { inject } from '@adonisjs/core'
import { HttpContext } from '@adonisjs/core/http'
import DailySnapshotService from './DailySnapshotService.js'
import Shift from '#models/Shift'
import Order from '#models/Order'
import { OrderStatus, OrderType } from '#enums/OrderEnums'
import Customer from '#models/Customer'
import Product from '#models/Product'
import axios from 'axios'
import { PaymentMethod } from '#enums/PaymentEnums'
import transmit from '@adonisjs/transmit/services/main'
import ErrorMsgException from '#exceptions/error_msg_exception'
import WebApiException from '#exceptions/web_api_exception'
import SettingsService from './SettingsService.js'

type OrderRequest = {
  user: {
    name: string
    phone: string
    area: string
    address: string
  }
  order: {
    type: OrderType
    shiftId: number
    orderNumber: string

    subTotal: number
    tax: number
    service: number
    discount: number
    total: number

    note: string

    items: {
      quantity: number
      notes: string
      posRefObj: {
        productRef: string
        quantity: number
      }[]
    }[]
  }
}

@inject()
export default class WebApiService {
  constructor(
    protected ctx: HttpContext,
    protected settingsService: SettingsService
  ) {}

  private async verifyShiftId(shiftId: number) {
    if (shiftId !== (await this.getShiftId())) throw new Error('Shift is not valid')
  }

  private async createOrGetCustomer(customer: OrderRequest['user']) {
    const customerFound = await Customer.firstOrCreate(
      { phone: customer.phone },
      {
        name: customer.name,
        phone: customer.phone,
        region: customer.area,
        address: customer.address,
        hasWhatsapp: false,
        deliveryCost: 0,
      }
    )
    customerFound.address = customer.address
    customerFound.region = customer.area
    await customerFound.save()
    return customerFound
  }

  private async fillOrderItems(order: Order, items: OrderRequest['order']['items']) {
    const orderItemsData: {
      productId: number
      quantity: number
      price: number
      cost: number
      total: number
      notes: string
    }[] = []
    const productsRefs = items
      .map((item) => item.posRefObj)
      .flat()
      .map((item) => item.productRef)
    const products = await Product.query().whereIn('product_ref', productsRefs)
    // check if all products are found
    if (products.length !== productsRefs.length) {
      const notFoundProducts = productsRefs.filter(
        (productRef) => !products.find((product) => product.productRef === productRef)
      )
      throw new WebApiException(JSON.stringify({ message: 'Product not found', notFoundProducts }))
    }
    for (const item of items) {
      for (const posRefObj of item.posRefObj) {
        const product = products.find((product) => product.productRef === posRefObj.productRef)
        console.log('product:', product?.name, posRefObj.productRef)
        if (product) {
          orderItemsData.push({
            productId: product.id,
            quantity: item.quantity * posRefObj.quantity,
            price: product.price,
            cost: product.cost,
            total: product.price * item.quantity * posRefObj.quantity,
            notes: item.notes,
          })
        }
      }
    }
    await order.related('items').createMany(orderItemsData)
  }

  private async handlePayments(order: Order, webOrder: OrderRequest['order']) {
    await order.load('items')
    order.subTotal = webOrder.subTotal
    const posSubTotal = order.items.reduce((acc, item) => acc + item.total, 0)
    order.webPosDiff = webOrder.subTotal - posSubTotal
    order.tax = webOrder.tax
    order.service = webOrder.service
    order.discount = webOrder.discount
    order.total = webOrder.total
    const cost = order.items.reduce((total, item) => total + item.cost * item.quantity, 0)
    order.profit = order.total - cost
    await order.save()
  }

  public async applyDiscount(orderId: number, discount: number, discountType: 'percent' | 'value') {
    const order = await Order.findOrFail(orderId)
    order.discount = discountType === 'percent' ? order.subTotal * (discount / 100) : discount
    order.total = order.subTotal + order.tax + order.service - order.discount
    await order.save()
  }

  public async canAcceptOrder() {
    return await DailySnapshotService.dayIsOpen()
  }

  public async getShiftId() {
    return (await Shift.query().orderBy('id', 'desc').first())?.id
  }

  public async placeOrder(data: OrderRequest) {
    await this.verifyShiftId(data.order.shiftId)
    // get customer with the phone number or create a new
    let order
    try {
      const customer = await this.createOrGetCustomer(data.user)
      order = await customer.related('orders').create({
        shiftId: data.order.shiftId,
        status: OrderStatus.Pending,
        type: data.order.type,
        orderNumber: data.order.orderNumber,
        orderNotes: data.order.note,
      })
      await this.fillOrderItems(order, data.order.items)
      await this.handlePayments(order, data.order)
      transmit.broadcast('web-orders', { order: order.serialize() })
    } catch (e) {
      order?.related('items').query().delete()
      order?.delete()
      throw e
    }
  }

  public async acceptOrder(orderId: number) {
    const order = await Order.findOrFail(orderId)
    order.status = OrderStatus.Processing
    await this.notifyWebOrderWithStatus(order)
    await order.save()
  }

  public async rejectOrder(orderId: number) {
    const order = await Order.findOrFail(orderId)
    order.status = OrderStatus.Cancelled
    await this.notifyWebOrderWithStatus(order)
    await order.related('payments').query().delete()
    await order.save()
  }

  public async outForDelivery(orderId: number) {
    const order = await Order.findOrFail(orderId)
    order.status = OrderStatus.OutForDelivery
    await this.notifyWebOrderWithStatus(order)
    await order.save()
  }

  public async completeOrder(orderId: number) {
    const order = await Order.findOrFail(orderId)
    if ([OrderStatus.Completed, OrderStatus.Cancelled].includes(order.status)) {
      throw new ErrorMsgException('هذا الطلب لم يعد قيد التشغيل')
    }
    order.status = OrderStatus.Completed
    await this.notifyWebOrderWithStatus(order)
    await order.save()
    return order
  }

  private async notifyWebOrderWithStatus(order: Order) {
    try {
      await axios.post(await this.settingsService.getWebsiteLink() + '/api/order-status', {
        orderNumber: order.orderNumber,
        status: order.status,
      })
      this.ctx.message.success('تم إرسال تحديث الطلب إلى العميل')
    } catch (error) {
      console.log('error', error.response?.data)
      this.ctx.logger.error('Failed to notify web order with status', error)
      throw new Error('فشل في إرسال تحديث الطلب إلى العميل')
    }
  }
}
