import { OrderStatus, OrderType } from '#enums/OrderEnums'
import { ProductType } from '#enums/ProductEnums'
import Category from '#models/Category'
import Order from '#models/Order'
import Printer from '#models/Printer'
import Product from '#models/Product'
import Setting from '#models/Setting'
import Shift from '#models/Shift'
import PrinterRender from '#render/PrinterRender'
import PrinterService from '#services/PrinterService'
import type { HttpContext } from '@adonisjs/core/http'
import vine from '@vinejs/vine'
import { PrinterTypes, ThermalPrinter } from 'node-thermal-printer'

const printerSchema = vine.compile(
  vine.object({
    name: vine.string(),
    ipAddress: vine.string(),
  })
)

export default class PrintersController {
  public async index({ inertia }: HttpContext) {
    return inertia.render('RenderModel', await new PrinterRender().render())
  }

  public async store({ response, request, session }: HttpContext) {
    try {
      const data = await request.validateUsing(printerSchema)
      await Printer.create(data)
      return response.redirect().back()
    } catch (error) {
      session.flash({ errors: error.messages })
      return response.redirect().back()
    }
  }

  public async update({ response, request, params, message }: HttpContext) {
    const data = await request.validateUsing(printerSchema)
    const printer = await Printer.findOrFail(params.id)
    printer.merge(data)
    await printer.save()
    message.success('تم تعديل الطابعة بنجاح')
    return response.redirect().back()
  }

  public async destroy({ response, params, message }: HttpContext) {
    const printer = await Printer.findOrFail(params.id)
    await printer.delete()
    message.success('تم حذف الطابعة بنجاح')
    return response.redirect().back()
  }

  public async mappingPrinterProducts({ inertia, params }: HttpContext) {
    const printer = await Printer.findOrFail(params.id)
    const categories = await Category.query().preload('products', (query) => {
      query.whereNot('type', ProductType.RawMaterial).preload('printers')
    })
    return inertia.render('MappingPrinters', { printer, categories })
  }

  public async saveMappingPrinterProducts({ request, message, response }: HttpContext) {
    const { printerId, products } = await request.validateUsing(
      vine.compile(
        vine.object({
          printerId: vine.number(),
          products: vine.array(vine.number()),
        })
      )
    )
    const printer = await Printer.findOrFail(printerId)
    await printer.related('products').sync(products)
    message.success('تم الحفظ بنجاح')
    return response.redirect().back()
  }

  public async openCashDrawer({ response, session, message }: HttpContext) {
    const cashierPrinter =
      session.get('cashierPrinter') || (await Setting.findBy('key', 'cashierPrinter'))?.value
    if (!cashierPrinter) {
      message.error('يجب تحديد طابعة الكاشير اولا')
      return response.redirect().back()
    }

    let printer = new ThermalPrinter({
      type: PrinterTypes.EPSON,
      interface: cashierPrinter,
    })
    printer.openCashDrawer()
    printer.execute().catch((_) => {})
    message.success('تم فتح الدرج بنجاح')
    return response.redirect().back()
  }

  public async printShiftReceipts({ response, params, session, message }: HttpContext) {
    try {
      const shift = await Shift.findOrFail(params.id)
      await shift.load('expenses')
      await shift.load('orders')

      const completedOrders = shift.orders.filter((order) => order.status === OrderStatus.Completed)
      const completedOrdersValue = completedOrders.reduce((acc, order) => acc + order.total, 0)
      const expensesValue = shift.expenses.reduce((acc, expense) => acc + expense.amount, 0)
      const deliveryCost = completedOrders
        .filter((order) => order.type === OrderType.Delivery)
        .reduce((acc, order) => acc + order.service, 0)

      const cashierPrinter =
        session.get('cashierPrinter') || (await Setting.findBy('key', 'cashierPrinter'))?.value
      if (!cashierPrinter) {
        message.error('يجب تحديد طابعة الكاشير اولا')
        return response.redirect().back()
      }

      const printerService = new PrinterService({
        network: cashierPrinter,
      })

      await printerService.shiftReportTemplate(
        shift,
        completedOrders.length,
        completedOrdersValue,
        expensesValue,
        deliveryCost
      )

      printerService.execute()

      message.success('تم ارسال طلب الطباعة')
      return response.redirect().back()
    } catch (error) {
      message.error('حدث خطأ اثناء الطباعة')
      console.log(error)
      return response.redirect().back()
    }
  }

  public async testPrinter({ response, request, message }: HttpContext) {
    const printerService = new PrinterService({
      network: request.input('printer'),
    })
    try {
      await printerService.printImgDataUrl(request.input('img'))
      await printerService.execute()
    } catch (error) {
      console.log(error)
      message.error('حدث خطأ اثناء الطباعة')
      return response.redirect().back()
    }

    message.success('تم ارسال طلب الطباعة')
    return response.redirect().back()
  }

  public async printOrder({ response, request, session, message }: HttpContext) {
    const cashierPrinter =
      session.get('cashierPrinter') || (await Setting.findBy('key', 'cashierPrinter'))?.value
    if (!cashierPrinter) {
      message.error('يجب تحديد طابعة الكاشير اولا')
      return response.redirect().back()
    }

    const printerService = new PrinterService({
      network: cashierPrinter,
    })

    const images = request.input('images') as string[]

    try {
      await printerService.printImgsDataUrl(images)
      printerService.execute()
    } catch (error) {
      console.log(error)
      message.error('حدث خطأ اثناء الطباعة')
      return response.redirect().back()
    }

    message.success('تم ارسال طلب الطباعة')
    return response.redirect().back()
  }

  public async printShiftSummary({ response, request, session, message }: HttpContext) {
    const cashierPrinter =
      session.get('cashierPrinter') || (await Setting.findBy('key', 'cashierPrinter'))?.value
    if (!cashierPrinter) {
      message.error('يجب تحديد طابعة الكاشير اولا')
      return response.redirect().back()
    }

    const printerService = new PrinterService({
      network: cashierPrinter,
    })

    const image = request.input('image') as string
    try {
      await printerService.printImgDataUrl(image)
      printerService.execute()
    } catch (error) {
      console.log(error)
      message.error('حدث خطأ اثناء الطباعة')
      return response.redirect().back()
    }

    message.success('تم ارسال طلب الطباعة')
    return response.redirect().back()
  }

  public async printInKitchen({ response, request, message }: HttpContext) {
    const images = request.input('images') as {
      printerId: string
      image: string
    }[]

    for (const image of images) {
      const printer = await Printer.findOrFail(image.printerId)
      const printerService = new PrinterService({
        network: printer.ipAddress,
      })
      await printerService.printImgDataUrl(image.image)
      try {
        await printerService.execute()
      } catch (error) {
        console.log(error)
        message.error('حدث خطأ اثناء الطباعة')
        return response.redirect().back()
      }
    }

    message.success('تم ارسال طلب الطباعة')
    return response.redirect().back()
  }

  public async printersOfProducts({ response, request }: HttpContext) {
    const ids = request.input('ids')
    const products = await Product.query()
      .select('id')
      .whereIn('id', ids)
      .preload('printers', (query) => {
        query.select('id')
      })
    return response.json(products)
  }
}
